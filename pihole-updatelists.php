#!/usr/bin/env php
<?php
/**
 * Update Pi-hole lists from remote sources
 *
 * @author  Jack'lul <jacklul.github.io>
 * @license MIT
 * @link    https://github.com/jacklul/pihole-updatelists
 */

/**
 * Check for required stuff
 */
function checkDependencies()
{
    // Do not run on PHP lower than 7.0
    if ((float)PHP_VERSION < 7.0) {
        print 'Minimum PHP 7.0 is required to run this script!' . PHP_EOL;
        exit(1);
    }

    // Windows is obviously not supported
    if (stripos(PHP_OS, 'WIN') === 0 && empty(getenv('IGNORE_OS_CHECK'))) {
        print 'Windows is not supported!' . PHP_EOL;
        exit(1);
    }

    $extensions = [
        'pdo',
        'pdo_sqlite',
    ];

    // Required PHP extensions
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            print 'Missing required PHP extension: ' . $extension . PHP_EOL;
            exit(1);
        }
    }

    if (!function_exists('posix_getuid') || !function_exists('posix_kill')) {
        print 'Make sure PHP\'s functions \'posix_getuid\' and \'posix_kill\' are available!' . PHP_EOL;
        exit(1);
    }

    // Require root privileges
    if (function_exists('posix_getuid') && posix_getuid() !== 0) {
        print 'This tool must be run as root!' . PHP_EOL;
        exit(1);
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
        'LOCK_FILE'           => '/tmp/' . basename(__FILE__) . '.lock',
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
    ];

    $options = getopt(
        '',
        [
            'config::',
        ]
    );

    if (isset($options['config'])) {
        if (!file_exists($options['config'])) {
            print 'Invalid file: ' . $options['config'] . PHP_EOL;
            exit(1);
        }

        $config['CONFIG_FILE'] = $options['config'];
        $config['LOCK_FILE'] = '/tmp/' . basename(__FILE__) . '-' . md5($config['CONFIG_FILE']) . '.lock';
    }

    if (file_exists($config['CONFIG_FILE'])) {
        $loadedConfig = @parse_ini_file($config['CONFIG_FILE'], false, INI_SCANNER_TYPED);
        if ($loadedConfig === false) {
            print 'Failed to load configuration file!' . PHP_EOL;
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
        print 'Variable COMMENT must be a string at least 3 characters long!' . PHP_EOL;
        exit(1);
    }

    if (!is_int($config['GROUP_ID']) || $config['GROUP_ID'] < 0) {
        print 'Variable GROUP_ID must be a number higher or equal zero!' . PHP_EOL;
        exit(1);
    }
}

/**
 * Acquire process lock
 *
 * @param string $lockfile
 *
 * @return resource
 */
function acquireLock($lockfile)
{
    if (empty($lockfile)) {
        print 'Lock file not defined!' . PHP_EOL;
        exit(1);
    }

    if ($lock = @fopen($lockfile, 'wb+')) {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            print 'Another process is already running!' . PHP_EOL;
            exit(6);
        }

        return $lock;
    }

    print 'Unable to access path or lock file: ' . $lockfile . PHP_EOL;
    exit(1);
}

/**
 * Open the database
 *
 * @param string $db_file
 * @param bool   $print
 *
 * @return PDO
 */
function openDatabase($db_file, $print = true)
{
    try {
        $dbh = new PDO('sqlite:' . $db_file);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $print && print 'Opened gravity database: ' . $db_file . ' (' . formatBytes(filesize($db_file)) . ')' . PHP_EOL . PHP_EOL;

        return $dbh;
    } catch (PDOException $e) {
        print $e->getMessage();
        exit(1);
    }
}

/**
 * Convert text files from one-entry-per-line to array
 *
 * @param string $text
 *
 * @return array|false|string[]
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

    return $array;
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
    $header[] = 'https://github.com/jacklul/pihole-updatelists';
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

    print PHP_EOL . implode(PHP_EOL, $header) . PHP_EOL . PHP_EOL;
}

/**
 * Print debug information
 *
 * @param array $config
 */
function printVerboseHeader($config)
{
    print 'OS: ' . php_uname() . PHP_EOL;
    print 'PHP: ' . PHP_VERSION . (ZEND_THREAD_SAFE ? '' : ' NTS'). PHP_EOL;
    print 'SQLite: ' . (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetch()[0] . PHP_EOL;

    ob_start();
    var_dump($config);
    print 'Configuration: ' . preg_replace('/=>\s+/', ' => ', ob_get_clean()) . PHP_EOL;
}

/** PROCEDURAL CODE STARTS HERE */
checkDependencies();    // Check script requirements

// Set needed variables
$config = loadConfig();     // Load config and process variables
$lock = acquireLock($config['LOCK_FILE']);  // Make sure this is the only instance
$stat = [
    'errors'   => 0,
    'invalid'  => 0,
    'conflict' => 0,
];

// Print script header
printHeader();

if ($config['VERBOSE'] === true) {
    printVerboseHeader($config);
    print 'Acquired process lock through file: ' . $config['LOCK_FILE'] . ')' . PHP_EOL;
}

// Handle process interruption and cleanup after script exits
register_shutdown_function(
    static function () use ($config, &$lock) {
        flock($lock, LOCK_UN);
        fclose($lock);

        unlink($config['LOCK_FILE']);
    }
);

// Open the database
$dbh = openDatabase($config['GRAVITY_DB']);

// Helper function that checks if comment field matches when required
$checkIfTouchable = static function ($array) use ($config) {
    return $config['REQUIRE_COMMENT'] === false || strpos($array['comment'] ?? '', $config['COMMENT']) !== false;
};

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    print 'Fetching ADLISTS from \'' . $config['ADLISTS_URL'] . '\'...';

    if (($contents = @file_get_contents($config['ADLISTS_URL'])) !== false) {
        $adlists = textToArray($contents);
        print ' done (' . count($adlists) . ' entries)' . PHP_EOL;

        print 'Processing...' . PHP_EOL;
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
        $enabledLists = [];
        if ($sth->execute()) {
            $enabledLists = [];

            foreach ($sth->fetchAll() as $list) {
                $enabledLists[$list['id']] = $list['address'];
            }
        }

        // Entries that no longer exist in remote list
        $removedLists = array_diff($enabledLists, $adlists);
        if (!empty($removedLists)) {
            foreach ($removedLists as $id => $address) {        // Disable entries instead of removing them
                $sql = 'UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id';

                if ($config['REQUIRE_COMMENT'] === true) {
                    $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
                    $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
                } else {
                    $sth = $dbh->prepare($sql);
                }

                $sth->bindParam(':id', $id, PDO::PARAM_INT);

                if ($sth->execute()) {
                    print 'Disabled: ' . $address . PHP_EOL;
                }
            }
        }

        $adlistsAll = [];
        if (($sth = $dbh->prepare('SELECT * FROM `adlist`'))->execute()) {
            $adlistsAll = $sth->fetchAll();
        }

        // Helper function to check whenever adlist already exists
        $checkAdlistExists = static function ($address) use ($adlistsAll) {
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
            if (!filter_var($address, FILTER_VALIDATE_URL) || preg_match('/[^a-zA-Z0-9$\\-_.+!*\'(),;\/?:@=&]/', $address) !== 0) {
                print 'Invalid: ' . $address . PHP_EOL;

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
                    if ($config['GROUP_ID'] > 0 && $lastInsertId = $dbh->lastInsertId()) {      // Assign to group ID
                        $sth = $dbh->prepare('INSERT OR IGNORE INTO `adlist_by_group` (adlist_id, group_id) VALUES (:adlist_id, :group_id)');
                        $sth->bindParam(':adlist_id', $lastInsertId, PDO::PARAM_INT);
                        $sth->bindParam(':group_id', $config['GROUP_ID'], PDO::PARAM_INT);
                        $sth->execute();
                    }

                    print 'Inserted: ' . $address . PHP_EOL;
                }
            } else {
                $isTouchable = $checkIfTouchable($adlistUrl);
                $adlistUrl['enabled'] = (bool)$adlistUrl['enabled'] === true;

                // Enable existing entry but only if it's managed by this script
                if ($adlistUrl['enabled'] !== true && $isTouchable === true) {
                    $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $adlistUrl['id'], PDO::PARAM_INT);

                    if ($sth->execute()) {
                        print 'Enabled: ' . $address . PHP_EOL;
                    }
                } elseif ($config['VERBOSE'] === true) {        // Show other entry states only in verbose mode
                    if ($adlistUrl !== false && $isTouchable === true) {
                        print 'Exists: ' . $address . PHP_EOL;
                    } elseif ($isTouchable === false) {
                        print 'Ignored: ' . $address . PHP_EOL;
                    }
                }
            }
        }

        $dbh->commit();
        print PHP_EOL;
    } else {
        print ' failed' . PHP_EOL . 'Error: ' . (error_get_last()['message'] ?: 'Unknown') . PHP_EOL . PHP_EOL;

        $stat['errors']++;
    }
} elseif ($config['REQUIRE_COMMENT'] === true) {        // In case user decides to unset the URL - disable previously added entries
    $sth = $dbh->prepare('SELECT `id` FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1 LIMIT 1');
    $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);

    if ($sth->execute() && count($sth->fetchAll()) > 0) {
        print 'No remote list set for ADLISTS, disabling orphaned entries in the database...';

        $dbh->beginTransaction();
        $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `comment` LIKE :comment');
        $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);

        if ($sth->execute()) {
            print ' ok' . PHP_EOL;
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
    $domainsAll = $sth->fetchAll();
}

// Helper function to check whenever domain already exists
$checkDomainExists = static function ($domain) use ($domainsAll) {
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
        print 'Fetching ' . $typeName . ' from \'' . $config[$url_key] . '\'...';

        if (($contents = @file_get_contents($config[$url_key])) !== false) {
            $list = textToArray($contents);
            print ' done (' . count($list) . ' entries)' . PHP_EOL;

            print 'Processing...' . PHP_EOL;
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

            $enabledDomains = [];
            if ($sth->execute()) {
                $enabledDomains = [];

                foreach ($sth->fetchAll() as $domain) {
                    if (strpos(trim($domain['domain']), '#') !== 0) {
                        $enabledDomains[$domain['id']] = $domain['domain'];
                    }
                }
            }

            // Entries that no longer exist in remote list
            $removedDomains = array_diff($enabledDomains, $list);
            if (!empty($removedDomains)) {
                foreach ($removedDomains as $id => $domain) {       // Disable entries instead of removing them
                    $isTouchable = isset($lists[$id]) && strpos($lists[$id]['comment'], $config['COMMENT']) !== false;

                    $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT);

                    if ($sth->execute()) {
                        print 'Disabled: ' . $domain . PHP_EOL;
                    }
                }
            }

            foreach ($list as $domain) {
                $domain = strtolower($domain);

                if (strpos($typeName, 'REGEX_') === false) {
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
                        print 'Invalid: ' . $domain . PHP_EOL;

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
                        if ($config['GROUP_ID'] > 0 && $lastInsertId = $dbh->lastInsertId()) {      // Assign to group ID
                            $sth = $dbh->prepare('INSERT OR IGNORE INTO `domainlist_by_group` (domainlist_id, group_id) VALUES (:domainlist_id, :group_id)');
                            $sth->bindParam(':domainlist_id', $lastInsertId, PDO::PARAM_INT);
                            $sth->bindParam(':group_id', $config['GROUP_ID'], PDO::PARAM_INT);
                            $sth->execute();
                        }

                        print 'Inserted: ' . $domain . PHP_EOL;
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
                            print 'Enabled: ' . $domain . PHP_EOL;
                        }
                    } elseif ($domainlistDomain['type'] !== $typeId) {
                        print 'Conflict: ' . $domain . ' (' . (array_search($domainlistDomain['type'], $domainListTypes, true) ?: 'type=' . $domainlistDomain['type']) . ')' . PHP_EOL;
                        if (!isset($stat['conflicts']) || !in_array($domain, $stat['conflicts'], true)) {
                            $stat['conflict']++;
                            $stat['conflicts'][] = $domain;
                        }
                    } elseif ($config['VERBOSE'] === true) {        // Show other entry states only in verbose mode
                        if ($domainlistDomain !== false && $isTouchable === true) {
                            print 'Exists: ' . $domain . PHP_EOL;
                        } elseif ($isTouchable === false) {
                            print 'Ignored: ' . $domain . PHP_EOL;
                        }
                    }
                }
            }

            $dbh->commit();
            print PHP_EOL;
        } else {
            print ' failed' . PHP_EOL . 'Error: ' . (error_get_last()['message'] ?: 'Unknown') . PHP_EOL . PHP_EOL;

            $stat['errors']++;
        }
    } elseif ($config['REQUIRE_COMMENT'] === true) {        // In case user decides to unset the URL - disable previously added entries
        $sth = $dbh->prepare('SELECT id FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type LIMIT 1');
        $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
        $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

        if ($sth->execute() && count($sth->fetchAll()) > 0) {
            print 'No remote list set for ' . $typeName . ', disabling orphaned entries in the database...';

            $dbh->beginTransaction();
            $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `comment` LIKE :comment AND `type` = :type');
            $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
            $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

            if ($sth->execute()) {
                print ' ok' . PHP_EOL;
            }

            $dbh->commit();
            print PHP_EOL;
        }
    }
}

// Close any database handles
$sth = null;
$dbh = null;

// Update gravity (run `pihole updateGravity`)
if ($config['UPDATE_GRAVITY'] === true) {
    print 'Updating Pi-hole\'s gravity:' . PHP_EOL . PHP_EOL;

    passthru('pihole updateGravity', $return);
    print PHP_EOL;

    if ($return !== 0) {
        print 'Error occurred while updating gravity!' . PHP_EOL . PHP_EOL;
        $stat['errors']++;
    }

    if ($config['VERBOSE'] === true) {
        print 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL . PHP_EOL;
    }
}

// Vacuum database (run `VACUUM` command)
if ($config['VACUUM_DATABASE'] === true) {
    $dbh = openDatabase($config['GRAVITY_DB'], false);

    print 'Vacuuming database...';
    if ($dbh->query('VACUUM')) {
        print ' done' . PHP_EOL;
    }

    $dbh = null;

    if ($config['VERBOSE'] === true) {
        print 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL;
    }

    print PHP_EOL;
}

// Sends signal to pihole-FTl to reload lists
if ($config['UPDATE_GRAVITY'] === false) {
    print 'Reloading Pi-hole\'s DNS lists...';

    exec('pidof pihole-FTL', $return);
    if (isset($return[0])) {
        $pid = $return[0];

        if (strpos($pid, ' ') !== false) {
            $pid = explode(' ', $pid);
            $pid = $pid[count($pid) - 1];
        }

        if (posix_kill($pid, SIGRTMIN)) {
            print ' done' . PHP_EOL;
        } else {
            print ' failed to send signal' . PHP_EOL;
            $stat['errors']++;
        }
    } else {
        print ' failed to find process PID' . PHP_EOL;
        $stat['errors']++;
    }

    print PHP_EOL;
}

if ($config['VERBOSE'] === true) {
    print 'Peak memory usage: ' . formatBytes(memory_get_peak_usage()) . PHP_EOL . PHP_EOL;
}

if ($stat['invalid'] > 0) {
    print 'Ignored ' . $stat['invalid'] . ' invalid ' . ($stat['invalid'] === 1 ? 'entry' : 'entries') . '.' . PHP_EOL;
}

if ($stat['conflict'] > 0) {
    print 'Found ' . $stat['conflict'] . ' conflicting ' . ($stat['conflict'] === 1 ? 'entry' : 'entries') . ' across your lists.' . PHP_EOL;
}

if ($stat['errors'] > 0) {
    print 'Finished with ' . $stat['errors'] . ' error(s).' . PHP_EOL;
    exit(1);
}

print 'Finished successfully.' . PHP_EOL;
