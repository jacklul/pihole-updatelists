#!/usr/bin/env php
<?php
/**
 * Update Pi-hole lists from remote sources
 *
 * @author  Jack'lul <jacklul.github.io>
 * @license MIT
 * @link    https://github.com/jacklul/pihole-updatelists
 */

define('GITHUB_LINK', 'https://github.com/jacklul/pihole-updatelists');
define('GITHUB_LINK_RAW', 'https://raw.githubusercontent.com/jacklul/pihole-updatelists/beta');

/**
 * Check for required stuff
 */
function checkDependencies()
{
    // Do not run on PHP lower than 7.0
    if ((float)PHP_VERSION < 7.0) {
        printAndLog('Minimum PHP 7.0 is required to run this script!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    // Windows is obviously not supported
    if (stripos(PHP_OS, 'WIN') === 0 && empty(getenv('IGNORE_OS_CHECK'))) {
        printAndLog('Windows is not supported!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if ((!function_exists('posix_getuid') || !function_exists('posix_kill')) && empty(getenv('IGNORE_OS_CHECK'))) {
        printAndLog('Make sure PHP\'s functions \'posix_getuid\' and \'posix_kill\' are available!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    // Require root privileges
    if (function_exists('posix_getuid') && posix_getuid() !== 0) {
        printAndLog('This tool must be run as root!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    $extensions = [
        'pdo',
        'pdo_sqlite',
        'json',
    ];

    // Required PHP extensions
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            printAndLog('Missing required PHP extension: ' . $extension . PHP_EOL, 'ERROR');
            exit(1);
        }
    }
}

/**
 * Load config file, if exists
 *
 * @return array
 */
function loadConfig()
{
    // Default configuration
    $config = [
        'CONFIG_FILE'         => '/etc/pihole-updatelists.conf',
        'GRAVITY_DB'          => '/etc/pihole/gravity.db',
        'LOCK_FILE'           => '/var/lock/pihole-updatelists.lock',
        'LOG_FILE'            => '',
        'ADLISTS_URL'         => '',
        'WHITELIST_URL'       => '',
        'REGEX_WHITELIST_URL' => '',
        'BLACKLIST_URL'       => '',
        'REGEX_BLACKLIST_URL' => '',
        'COMMENT'             => 'Managed by pihole-updatelists',
        'GROUP_ID'            => 0,
        'REQUIRE_COMMENT'     => true,
        'UPDATE_GRAVITY'      => true,
        'VACUUM_DATABASE'     => true,
        'VERBOSE'             => false,
        'DEBUG'               => false,
        'DOWNLOAD_TIMEOUT'    => 60,
    ];

    $options = getopt(
        '',
        [
            'config::',
        ]
    );

    if (isset($options['config'])) {
        if (!file_exists($options['config'])) {
            printAndLog('Invalid file: ' . $options['config'] . PHP_EOL, 'ERROR');
            exit(1);
        }

        $config['CONFIG_FILE'] = $options['config'];
    }

    if (file_exists($config['CONFIG_FILE'])) {
        $loadedConfig = @parse_ini_file($config['CONFIG_FILE'], false, INI_SCANNER_TYPED);
        if ($loadedConfig === false) {
            printAndLog('Failed to load configuration file: ' . parseLastError() . PHP_EOL, 'ERROR');
            exit(1);
        }

        unset($loadedConfig['CONFIG_FILE']);

        $config = array_merge($config, $loadedConfig);
    }

    validateConfig($config);
    $config['COMMENT'] = trim($config['COMMENT']);

    return $config;
}

/**
 * Validate important configuration variables
 *
 * @param $config
 */
function validateConfig($config)
{
    if (empty($config['COMMENT']) || strlen($config['COMMENT']) < 3) {
        printAndLog('Variable COMMENT must be a string at least 3 characters long!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if (!is_int($config['GROUP_ID']) || $config['GROUP_ID'] < 0) {
        printAndLog('Variable GROUP_ID must be a number higher or equal zero!' . PHP_EOL, 'ERROR');
        exit(1);
    }
}

/**
 * Acquire process lock
 *
 * @param string $lockfile
 * @param bool   $debug
 *
 * @return resource
 */
function acquireLock($lockfile, $debug = false)
{
    if (empty($lockfile)) {
        printAndLog('Lock file not defined!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if ($lock = @fopen($lockfile, 'wb+')) {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            printAndLog('Another process is already running!' . PHP_EOL, 'ERROR');
            exit(6);
        }

        $debug === true && printAndLog('Acquired process lock through file: ' . $lockfile . PHP_EOL, 'DEBUG');

        return $lock;
    }

    printAndLog('Unable to access path or lock file: ' . $lockfile . PHP_EOL, 'ERROR');
    exit(1);
}

/**
 * Open the database
 *
 * @param string $db_file
 * @param bool   $verbose
 * @param bool   $debug
 *
 * @return PDO
 */
function openDatabase($db_file, $verbose = true, $debug = false)
{
    $dbh = new PDO('sqlite:' . $db_file);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->exec('PRAGMA foreign_keys = ON;');   // Require foreign key constraints

    if ($debug) {
        !class_exists('LoggedPDOStatement') && registerPDOLogger();
        $dbh->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['LoggedPDOStatement']);
    }

    $verbose && printAndLog('Opened gravity database: ' . $db_file . ' (' . formatBytes(filesize($db_file)) . ')' . PHP_EOL);

    return $dbh;
}

/**
 * Register PDO logging class
 */
function registerPDOLogger()
{
    class LoggedPDOStatement extends PDOStatement
    {
        private $queryParameters = [];
        private $parsedQuery = '';

        public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
        {
            $this->queryParameters[$parameter] = [
                'value' => $value,
                'type'  => $data_type,
            ];

            return parent::bindValue($parameter, $value, $data_type);
        }

        public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null): bool
        {
            $this->queryParameters[$parameter] = [
                'value' => $variable,
                'type'  => $data_type,
            ];

            return parent::bindParam($parameter, $variable, $data_type, $length, $driver_options);
        }

        public function execute($input_parameters = null): bool
        {
            printAndLog('SQL Query: ' . $this->parseQuery() . PHP_EOL, 'DEBUG');

            return parent::execute($input_parameters);
        }

        private function parseQuery(): string
        {
            if (!empty($this->parsedQuery)) {
                return $this->parsedQuery;
            }

            $query = $this->queryString;
            foreach ($this->queryParameters as $parameter => $data) {
                switch ($data['type']) {
                    case PDO::PARAM_STR:
                        $value = '"' . $data['value'] . '"';
                        break;
                    case PDO::PARAM_INT:
                        $value = (int)$data['value'];
                        break;
                    case PDO::PARAM_BOOL:
                        $value = (bool)$data['value'];
                        break;
                    default:
                        $value = null;
                }

                $query = str_replace($parameter, $value, $query);
            }

            return $this->parsedQuery = $query;
        }
    }
}

/**
 * Convert text files from one-entry-per-line to array
 *
 * @param string $text
 *
 * @return array
 */
function textToArray($text)
{
    $array = preg_split('/\r\n|\r|\n/', $text);

    foreach ($array as $var => &$val) {
        if (empty($val) || strpos(trim($val), '#') === 0) {
            unset($array[$var]);
        }

        $val = trim($val);
    }
    unset($val);

    return array_values($array);
}

/**
 * @param int $bytes
 * @param int $precision
 *
 * @return string
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Just print the header
 */
function printHeader()
{
    $header[] = 'Pi-hole\'s Lists Updater by Jack\'lul';
    $header[] = GITHUB_LINK;
    $offset = ' ';

    $maxLen = 0;
    foreach ($header as $string) {
        $strlen = strlen($string);
        $strlen > $maxLen && $maxLen = $strlen;
    }

    foreach ($header as &$string) {
        $strlen = strlen($string);

        if ($strlen < $maxLen) {
            $diff = $maxLen - $strlen;
            $addL = ceil($diff / 2);
            $addR = $diff - $addL;

            $string = str_repeat(' ', $addL) . $string . str_repeat(' ', $addR);
        }

        $string = $offset . $string;
    }
    unset($string);

    printAndLog(trim($header[0]) . ' started' . PHP_EOL, 'INFO', true);
    print PHP_EOL . implode(PHP_EOL, $header) . PHP_EOL . PHP_EOL;
}

/**
 * Parse last error from error_get_last()
 *
 * @param string $default
 *
 * @return string
 */
function parseLastError($default = 'Unknown error')
{
    $lastError = error_get_last();

    return preg_replace('/file_get_contents(.*): /U', '', trim($lastError['message'] ?? $default));
}

/**
 * Check if script is up to date
 *
 * @return string
 * @noinspection PhpUnused
 */
function isUpToDate()
{
    $md5Self = md5_file(__FILE__);
    $cacheFile = sys_get_temp_dir() . '/pihole-updatelists.versioncheck';
    $cache = false;

    if (file_exists($cacheFile) && filemtime($cacheFile) + 300 > time()) {
        $cachedData = file_get_contents($cacheFile);
        $cachedData = json_decode($cachedData, true);

        if (isset($cachedData['md5']) && $cachedData['md5'] === $md5Self) {
            $cache = true;
            $result = $cachedData['result'];
        }
    }

    if (!isset($result)) {
        $remote = @file_get_contents(
            GITHUB_LINK_RAW . '/pihole-updatelists.php',
            false,
            stream_context_create(
                [
                    'http' => [
                        'timeout' => 10,
                    ],
                ]
            )
        );

        if ($remote === false) {
            $result = null;
        } elseif ($md5Self !== md5($remote)) {
            $result = false;
        } else {
            $result = true;
        }
    }

    if ($result === null) {
        $output = 'error - ' . parseLastError();
    } elseif ($result === false) {
        $output = 'outdated or checksum mismatch';
    } else {
        $output = 'up to date';
    }

    $cache === true && $output .= ' (cache)';

    $result !== null && @file_put_contents($cacheFile, json_encode(['result' => $result, 'md5' => $md5Self]));

    return $output;
}

/**
 * Print debug information
 *
 * @param array $config
 */
function printDebugHeader($config)
{
    //printAndLog('Script version: ' . isUpToDate() . PHP_EOL, 'DEBUG');
    printAndLog('Checksum: ' . md5_file(__FILE__) . PHP_EOL, 'DEBUG');
    printAndLog('OS: ' . php_uname() . PHP_EOL, 'DEBUG');
    printAndLog('PHP: ' . PHP_VERSION . (ZEND_THREAD_SAFE ? '' : ' NTS') . PHP_EOL, 'DEBUG');
    printAndLog('SQLite: ' . (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetch()[0] . PHP_EOL, 'DEBUG');

    $piholeVersions = @file_get_contents('/etc/pihole/localversions') ?? '';
    $piholeVersions = explode(' ', $piholeVersions);
    $piholeBranches = @file_get_contents('/etc/pihole/localbranches') ?? '';
    $piholeBranches = explode(' ', $piholeBranches);

    if (count($piholeVersions) === 3 && count($piholeBranches) === 3) {
        printAndLog('Pi-hole: ' . $piholeVersions[0] . ' (' . $piholeBranches[0] . ')' . PHP_EOL, 'DEBUG');
        printAndLog('Web: ' . $piholeVersions[1] . ' (' . $piholeBranches[1] . ')' . PHP_EOL, 'DEBUG');
        printAndLog('FTL: ' . $piholeVersions[2] . ' (' . $piholeBranches[2] . ')' . PHP_EOL, 'DEBUG');
    } else {
        printAndLog('Pi-hole: version info unavailable, make sure files `localversions` and `localbranches` exist in `/etc/pihole` and are valid!' . PHP_EOL, 'WARNING');
    }

    ob_start();
    var_dump($config);
    printAndLog('Configuration: ' . preg_replace('/=>\s+/', ' => ', ob_get_clean()) . PHP_EOL, 'DEBUG');
}

/**
 * Print (and optionally log) string
 *
 * @param string $str
 * @param string $channel
 * @param bool   $logOnly
 *
 * @throws RuntimeException
 */
function printAndLog($str, $channel = 'INFO', $logOnly = false)
{
    global $config, $lock;

    if (!empty($config['LOG_FILE'])) {
        $flags = FILE_APPEND;

        if (strpos($config['LOG_FILE'], '-') === 0) {
            $flags = 0;
            $config['LOG_FILE'] = substr($config['LOG_FILE'], 1);
        }

        // Do not overwrite log files until we have a lock (this could mess up log file if another instance is already running)
        if ($flags !== null || $lock !== null) {
            if (!file_exists($config['LOG_FILE']) && !@touch($config['LOG_FILE'])) {
                throw new RuntimeException('Unable to create log file: ' . $config['LOG_FILE']);
            }

            $lines = preg_split('/\r\n|\r|\n/', ucfirst(trim($str)));
            foreach ($lines as &$line) {
                $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($channel) . '] ' . $line;
            }
            unset($line);

            file_put_contents(
                $config['LOG_FILE'],
                implode(PHP_EOL, $lines) . PHP_EOL,
                $flags | LOCK_EX
            );
        }
    }

    if ($logOnly) {
        return;
    }

    print $str;
}

/**
 * Shutdown related tasks
 */
function shutdownCleanup()
{
    global $config, $lock;

    if ($config['DEBUG'] === true) {
        printAndLog('Releasing lock and removing lockfile: ' . $config['LOCK_FILE'] . PHP_EOL, 'DEBUG');
    }

    flock($lock, LOCK_UN) && fclose($lock) && unlink($config['LOCK_FILE']);
}

/** PROCEDURAL CODE STARTS HERE */
$startTime = microtime(true);
checkDependencies();    // Check script requirements
$config = loadConfig();     // Load config and process variables

// Exception handler, always log detailed information
set_exception_handler(
    static function (Exception $e) use (&$config) {
        if ($config['DEBUG'] === false) {
            print 'Exception: ' . $e->getMessage() . PHP_EOL;
        }

        printAndLog($e . PHP_EOL, 'ERROR', $config['DEBUG'] === false);
        exit(1);
    }
);

$lock = acquireLock($config['LOCK_FILE'], $config['DEBUG']);  // Make sure this is the only instance
register_shutdown_function('shutdownCleanup');  // Cleanup when script finishes

// Handle script interruption / termination
if (function_exists('pcntl_signal')) {
    declare(ticks=1);

    function signalHandler($signo)
    {
        $definedConstants = get_defined_constants(true);
        $signame = null;

        if (isset($definedConstants['pcntl'])) {
            foreach ($definedConstants['pcntl'] as $name => $num) {
                if ($num === $signo && strpos($name, 'SIG') === 0 && $name[3] !== '_') {
                    $signame = $name;
                }
            }
        }

        printAndLog(PHP_EOL . 'Interrupted by ' . ($signame ?? $signo) . PHP_EOL, 'WARNING');
        exit(130);
    }

    pcntl_signal(SIGHUP, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
}

// This array holds some state data
$stat = [
    'errors'   => 0,
    'invalid'  => 0,
    'conflict' => 0,
];

// Hi
printHeader();

// Show warning when php-intl isn't installed
if (!extension_loaded('intl')) {
    printAndLog('Missing recommended PHP extension: intl' . PHP_EOL . PHP_EOL, 'WARNING');
}

// Show initial debug messages
$config['DEBUG'] === true && printDebugHeader($config);

// Open the database
$dbh = openDatabase($config['GRAVITY_DB'], true, $config['DEBUG']);

// Helper function that checks if comment field matches when required
$checkIfTouchable = static function ($array) use (&$config) {
    return $config['REQUIRE_COMMENT'] === false || strpos($array['comment'] ?? '', $config['COMMENT']) !== false;
};

print PHP_EOL;

// Set download timeout
$streamContext = stream_context_create(
    [
        'http' => [
            'timeout' => $config['DOWNLOAD_TIMEOUT'],
        ],
    ]
);

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    printAndLog('Fetching ADLISTS from \'' . $config['ADLISTS_URL'] . '\'...');

    if (($contents = @file_get_contents($config['ADLISTS_URL'], false, $streamContext)) !== false) {
        $adlists = textToArray($contents);
        printAndLog(' done (' . count($adlists) . ' entries)' . PHP_EOL);

        printAndLog('Processing...' . PHP_EOL);
        $dbh->beginTransaction();

        // Get enabled adlists managed by this script from the DB
        $sql = 'SELECT * FROM `adlist` WHERE `enabled` = 1';

        if ($config['REQUIRE_COMMENT'] === true) {
            $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
            $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
        } else {
            $sth = $dbh->prepare($sql);
        }

        // Fetch all adlists
        $adlistsAll = [];
        if (($sthAll = $dbh->prepare('SELECT * FROM `adlist`'))->execute()) {
            $adlistsAll = $sthAll->fetchAll(PDO::FETCH_ASSOC);

            $tmp = [];
            foreach ($adlistsAll as $key => $value) {
                $tmp[$value['id']] = $value;
            }

            $adlistsAll = $tmp;
            unset($tmp);
        }

        // Fetch all enabled touchable adlists
        $enabledLists = [];
        if ($sth->execute()) {
            foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $adlist) {
                $enabledLists[$adlist['id']] = trim($adlist['address']);
            }
        }

        // Entries that no longer exist in remote list
        $removedLists = array_diff($enabledLists, $adlists);
        foreach ($removedLists as $id => $address) {        // Disable entries instead of removing them
            $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id');
            $sth->bindParam(':id', $id, PDO::PARAM_INT);

            if ($sth->execute()) {
                $adlistsAll[$id]['enabled'] = false;

                printAndLog('Disabled: ' . $address . PHP_EOL);
            }
        }

        // Helper function to check whenever adlist already exists
        $checkAdlistExists = static function ($address) use (&$adlistsAll) {
            $result = array_filter(
                $adlistsAll,
                static function ($array) use ($address) {
                    return isset($array['address']) && $array['address'] === $address;
                }
            );

            return count($result) === 1 ? array_values($result)[0] : false;
        };

        foreach ($adlists as $address) {
            // Check 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_adlist'
            if (!filter_var($address, FILTER_VALIDATE_URL) || preg_match('/[^a-zA-Z0-9$\\-_.+!*\'(),;\/?:@=&%]/', $address) !== 0) {
                printAndLog('Invalid: ' . $address . PHP_EOL);

                if (!isset($stat['invalids']) || !in_array($address, $stat['invalids'], true)) {
                    $stat['invalid']++;
                    $stat['invalids'][] = $address;
                }

                continue;
            }

            $adlistUrl = $checkAdlistExists($address);
            if ($adlistUrl === false) {     // Add entry if it doesn't exist
                $sth = $dbh->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                $sth->bindParam(':address', $address, PDO::PARAM_STR);
                $sth->bindParam(':comment', $config['COMMENT'], PDO::PARAM_STR);

                if ($sth->execute()) {
                    $lastInsertId = $dbh->lastInsertId();

                    // Insert this adlist into cached list of all adlists to prevent future duplicate errors
                    $adlistsAll[$lastInsertId] = [
                        'id'      => $lastInsertId,
                        'address' => $address,
                        'enabled' => true,
                        'comment' => $config['COMMENT'],
                    ];

                    if ($config['GROUP_ID'] > 0) {      // Assign to group ID
                        $sth = $dbh->prepare('INSERT OR IGNORE INTO `adlist_by_group` (adlist_id, group_id) VALUES (:adlist_id, :group_id)');
                        $sth->bindParam(':adlist_id', $lastInsertId, PDO::PARAM_INT);
                        $sth->bindParam(':group_id', $config['GROUP_ID'], PDO::PARAM_INT);
                        $sth->execute();
                    }

                    printAndLog('Inserted: ' . $address . PHP_EOL);
                }
            } else {
                $isTouchable = $checkIfTouchable($adlistUrl);
                $adlistUrl['enabled'] = (bool)$adlistUrl['enabled'] === true;

                // Enable existing entry but only if it's managed by this script
                if ($adlistUrl['enabled'] !== true && $isTouchable === true) {
                    $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $adlistUrl['id'], PDO::PARAM_INT);

                    if ($sth->execute()) {
                        $adlistsAll[$adlistUrl['id']]['enabled'] = true;

                        printAndLog('Enabled: ' . $address . PHP_EOL);
                    }
                } elseif ($config['VERBOSE'] === true) {        // Show other entry states only in verbose mode
                    if ($adlistUrl !== false && $isTouchable === true) {
                        printAndLog('Exists: ' . $address . PHP_EOL);
                    } elseif ($isTouchable === false) {
                        printAndLog('Ignored: ' . $address . PHP_EOL);
                    }
                }
            }
        }

        $dbh->commit();
    } else {
        printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');

        $stat['errors']++;
    }

    print PHP_EOL;
} elseif ($config['REQUIRE_COMMENT'] === true) {        // In case user decides to unset the URL - disable previously added entries
    $sth = $dbh->prepare('SELECT `id` FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1 LIMIT 1');
    $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);

    if ($sth->execute() && count($sth->fetchAll()) > 0) {
        printAndLog('No remote list set for ADLISTS, disabling orphaned entries in the database...');

        $dbh->beginTransaction();
        $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `comment` LIKE :comment');
        $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);

        if ($sth->execute()) {
            printAndLog(' ok' . PHP_EOL);
        }

        $dbh->commit();

        print PHP_EOL;
    }
}

// This array binds type of list to 'domainlist' table 'type' field, thanks to this we can use foreach loop instead of duplicating code
$domainListTypes = [
    'WHITELIST'       => 0,
    'REGEX_WHITELIST' => 2,
    'BLACKLIST'       => 1,
    'REGEX_BLACKLIST' => 3,
];

// Fetch all domains from domainlist
$domainsAll = [];
if (($sth = $dbh->prepare('SELECT * FROM `domainlist`'))->execute()) {
    $domainsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

    $tmp = [];
    foreach ($domainsAll as $key => $value) {
        $tmp[$value['id']] = $value;
    }

    $domainsAll = $tmp;
    unset($tmp);
}

// Helper function to check whenever domain already exists
$checkDomainExists = static function ($domain) use (&$domainsAll) {
    $result = array_filter(
        $domainsAll,
        static function ($array) use ($domain) {
            return isset($array['domain']) && $array['domain'] === $domain;
        }
    );

    return count($result) === 1 ? array_values($result)[0] : false;
};

// Fetch DOMAINLISTS
foreach ($domainListTypes as $typeName => $typeId) {
    $url_key = $typeName . '_URL';

    if (!empty($config[$url_key])) {
        printAndLog('Fetching ' . $typeName . ' from \'' . $config[$url_key] . '\'...');

        if (($contents = @file_get_contents($config[$url_key], false, $streamContext)) !== false) {
            $domainlist = textToArray($contents);
            printAndLog(' done (' . count($domainlist) . ' entries)' . PHP_EOL);

            printAndLog('Processing...' . PHP_EOL);
            $dbh->beginTransaction();

            // Get enabled domains of this type managed by this script from the DB
            $sql = 'SELECT * FROM `domainlist` WHERE `enabled` = 1 AND `type` = :type';

            if ($config['REQUIRE_COMMENT'] === false) {
                $sth = $dbh->prepare($sql);
            } else {
                $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
                $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
            }

            $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

            // Fetch all enabled touchable domainlists
            $enabledDomains = [];
            if ($sth->execute()) {
                foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $domain) {
                    if (strpos($typeName, 'REGEX_') === false) {
                        $enabledDomains[$domain['id']] = strtolower(trim($domain['domain']));
                    } else {
                        $enabledDomains[$domain['id']] = trim($domain['domain']);
                    }
                }
            }

            // Entries that no longer exist in remote list
            $removedDomains = array_diff($enabledDomains, $domainlist);

            foreach ($removedDomains as $id => $domain) {       // Disable entries instead of removing them
                $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                $sth->bindParam(':id', $id, PDO::PARAM_INT);

                if ($sth->execute()) {
                    $domainsAll[$id]['enabled'] = false;

                    printAndLog('Disabled: ' . $domain . PHP_EOL);
                }
            }

            foreach ($domainlist as $domain) {
                if (strpos($typeName, 'REGEX_') === false) {
                    $domain = strtolower($domain);

                    // Conversion code 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_domain'
                    if (extension_loaded('intl')) {
                        $idn_domain = false;

                        if (defined('INTL_IDNA_VARIANT_UTS46')) {
                            $idn_domain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                        }

                        if ($idn_domain === false && defined('INTL_IDNA_VARIANT_2003')) {
                            $idn_domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_2003);
                        }

                        if ($idn_domain !== false) {
                            $domain = $idn_domain;
                        }
                    }

                    // Check 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_domain'
                    if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                        printAndLog('Invalid: ' . $domain . PHP_EOL);

                        if (!isset($stat['invalids']) || !in_array($domain, $stat['invalids'], true)) {
                            $stat['invalid']++;
                            $stat['invalids'][] = $domain;
                        }

                        continue;
                    }
                }

                $domainlistDomain = $checkDomainExists($domain);
                if ($domainlistDomain === false) {      // Add entry if it doesn't exist
                    $sth = $dbh->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                    $sth->bindParam(':domain', $domain, PDO::PARAM_STR);
                    $sth->bindParam(':type', $typeId, PDO::PARAM_INT);
                    $sth->bindParam(':comment', $config['COMMENT'], PDO::PARAM_STR);

                    if ($sth->execute()) {
                        $lastInsertId = $dbh->lastInsertId();

                        // Insert this domain into cached list of all domains to prevent future duplicate errors
                        $domainsAll[$lastInsertId] = [
                            'id'      => $lastInsertId,
                            'domain'  => $domain,
                            'type'    => $typeId,
                            'enabled' => true,
                            'comment' => $config['COMMENT'],
                        ];

                        if ($config['GROUP_ID'] > 0) {      // Assign to group ID
                            $sth = $dbh->prepare('INSERT OR IGNORE INTO `domainlist_by_group` (domainlist_id, group_id) VALUES (:domainlist_id, :group_id)');
                            $sth->bindParam(':domainlist_id', $lastInsertId, PDO::PARAM_INT);
                            $sth->bindParam(':group_id', $config['GROUP_ID'], PDO::PARAM_INT);
                            $sth->execute();
                        }

                        printAndLog('Inserted: ' . $domain . PHP_EOL);
                    }
                } else {
                    $isTouchable = $checkIfTouchable($domainlistDomain);
                    $domainlistDomain['enabled'] = (bool)$domainlistDomain['enabled'] === true;
                    $domainlistDomain['type'] = (int)$domainlistDomain['type'];

                    // Enable existing entry but only if it's managed by this script
                    if ($domainlistDomain['type'] === $typeId && $domainlistDomain['enabled'] !== true && $isTouchable === true) {
                        $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $domainlistDomain['id'], PDO::PARAM_INT);

                        if ($sth->execute()) {
                            $domainsAll[$domainlistDomain['id']]['enabled'] = true;

                            printAndLog('Enabled: ' . $domain . PHP_EOL);
                        }
                    } elseif ($domainlistDomain['type'] !== $typeId) {
                        printAndLog('Conflict: ' . $domain . ' (' . (array_search($domainlistDomain['type'], $domainListTypes, true) ?: 'type=' . $domainlistDomain['type']) . ')' . PHP_EOL, 'WARNING');
                        if (!isset($stat['conflicts']) || !in_array($domain, $stat['conflicts'], true)) {
                            $stat['conflict']++;
                            $stat['conflicts'][] = $domain;
                        }
                    } elseif ($config['VERBOSE'] === true) {        // Show other entry states only in verbose mode
                        if ($domainlistDomain !== false && $isTouchable === true) {
                            printAndLog('Exists: ' . $domain . PHP_EOL);
                        } elseif ($isTouchable === false) {
                            printAndLog('Ignored: ' . $domain . PHP_EOL);
                        }
                    }
                }
            }

            $dbh->commit();
        } else {
            printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');

            $stat['errors']++;
        }

        print PHP_EOL;
    } elseif ($config['REQUIRE_COMMENT'] === true) {        // In case user decides to unset the URL - disable previously added entries
        $sth = $dbh->prepare('SELECT id FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type LIMIT 1');
        $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
        $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

        if ($sth->execute() && count($sth->fetchAll()) > 0) {
            printAndLog('No remote list set for ' . $typeName . ', disabling orphaned entries in the database...');

            $dbh->beginTransaction();
            $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `comment` LIKE :comment AND `type` = :type');
            $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
            $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

            if ($sth->execute()) {
                printAndLog(' ok' . PHP_EOL);
            }

            $dbh->commit();

            print PHP_EOL;
        }
    }
}

// Update gravity (run `pihole updateGravity`)
if ($config['UPDATE_GRAVITY'] === true) {
    // Close any database handles
    $sth = $dbh = null;

    if ($config['DEBUG'] === true) {
        printAndLog('Closed database handles.' . PHP_EOL, 'DEBUG');
    }

    printAndLog('Updating Pi-hole\'s gravity...' . PHP_EOL . PHP_EOL);

    passthru('pihole updateGravity', $return);
    print PHP_EOL;

    if ($return !== 0) {
        printAndLog('Error occurred while updating gravity!' . PHP_EOL . PHP_EOL, 'ERROR');
        $stat['errors']++;
    }

    printAndLog('Done' . PHP_EOL . PHP_EOL, 'INFO', true);
}

// Vacuum database (run `VACUUM` command)
if ($config['VACUUM_DATABASE'] === true) {
    $dbh === null && $dbh = openDatabase($config['GRAVITY_DB'], $config['DEBUG'], $config['DEBUG']);

    printAndLog('Vacuuming database...');
    if ($dbh->query('VACUUM')) {
        printAndLog(' done (' . formatBytes(filesize($config['GRAVITY_DB'])) . ')' . PHP_EOL);
    }

    $dbh = null;
    print PHP_EOL;
}

// Sends signal to pihole-FTl to reload lists
if ($config['UPDATE_GRAVITY'] === false) {
    printAndLog('Sending reload signal to Pi-hole\'s DNS server...');

    exec('pidof pihole-FTL 2>/dev/null', $return);
    if (isset($return[0])) {
        $pid = $return[0];

        if (strpos($pid, ' ') !== false) {
            $pid = explode(' ', $pid);
            $pid = $pid[count($pid) - 1];
        }

        if (posix_kill($pid, SIGRTMIN)) {
            printAndLog(' done' . PHP_EOL);
        } else {
            printAndLog(' failed to send signal' . PHP_EOL, 'ERROR');
            $stat['errors']++;
        }
    } else {
        printAndLog(' failed to find process PID' . PHP_EOL, 'ERROR');
        $stat['errors']++;
    }

    print PHP_EOL;
}

if ($config['DEBUG'] === true) {
    printAndLog('Memory reached peak usage of ' . formatBytes(memory_get_peak_usage()) . PHP_EOL, 'DEBUG');
}

if ($stat['invalid'] > 0) {
    printAndLog('Ignored ' . $stat['invalid'] . ' invalid ' . ($stat['invalid'] === 1 ? 'entry' : 'entries') . '.' . PHP_EOL, 'WARNING');
}

if ($stat['conflict'] > 0) {
    printAndLog('Found ' . $stat['conflict'] . ' conflicting ' . ($stat['conflict'] === 1 ? 'entry' : 'entries') . ' across your lists.' . PHP_EOL, 'WARNING');
}

$elapsedTime = round(microtime(true) - $startTime, 2) . ' seconds';

if ($stat['errors'] > 0) {
    printAndLog('Finished with ' . $stat['errors'] . ' error(s) in ' . $elapsedTime . '.' . PHP_EOL, 'ERROR');
    exit(1);
}

printAndLog('Finished successfully in ' . $elapsedTime . '.' . PHP_EOL);
