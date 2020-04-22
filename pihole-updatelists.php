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
 * Load config file, if exists
 *
 * @param string $config_file
 * @param array  $config
 *
 * @return array
 */
function loadConfig($config_file, $config)
{
    if (file_exists($config_file)) {
        $loadedConfig = @parse_ini_file($config_file, false, INI_SCANNER_TYPED);
        if ($loadedConfig === false) {
            print 'Failed to load configuration file!' . PHP_EOL;
            exit(1);
        }

        $config = array_merge($config, $loadedConfig);
    }

    if (empty($config['LOCK_FILE'])) {
        $config['LOCK_FILE'] = '/tmp/' . basename(__FILE__) . '-' . md5($config['CONFIG_FILE']) . '.lock';
    }

    if (empty($config['COMMENT_STRING'])) {
        print 'Configuration variable COMMENT_STRING cannot be empty!' . PHP_EOL;
        exit(1);
    }

    if ($config['VERBOSE'] === true) {
        print 'Configuration:' . PHP_EOL;

        foreach ($config as $var => $val) {
            print ' ' . $var . ' = ' . $val . PHP_EOL;
        }

        print PHP_EOL;
    }

    $config['COMMENT_STRING_WILDCARD'] = '%' . trim($config['COMMENT_STRING']) . '%'; // Wildcard comment string for SQL queries

    return $config;
}

/**
 * Parse parameters given to script
 */
function parseParameters()
{
    $options = getopt(
        'huc::',
        [
            'help',
            'update',
            'config::',
        ]
    );

    if (!empty($options)) {
        if (isset($options['help']) || isset($options['h'])) {
            printHelp();
        }

        if (isset($options['update']) || isset($options['u'])) {
            updateScript();
        }

        if (isset($options['config']) || isset($options['c'])) {
            global $config;
            isset($options['c']) && $options['config'] = $options['c'];

            if (file_exists($options['config'])) {
                $config['CONFIG_FILE'] = $options['config'];

                return;
            }

            print 'Invalid file: ' . $options['config'] . PHP_EOL;
            exit(1);
        }

        exit(0);
    }
}

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
    if (stripos(PHP_OS, 'WIN') === 0) {
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
            print 'Missing PHP extension: ' . $extension . PHP_EOL;
            exit(1);
        }
    }

    // Require root privileges
    if (function_exists('posix_getuid') && posix_getuid() !== 0) {
        print 'This tool must be run as root!' . PHP_EOL;
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
 * Handle script shutdown - cleanup, lock file unlock
 */
function shutdownHandler()
{
    global $config, $lock;

    flock($lock, LOCK_UN);
    fclose($lock);

    unlink($config['LOCK_FILE']);
}

/**
 * Acquire process lock
 *
 * @param string $lockfile
 */
function acquireLock($lockfile)
{
    global $lock;

    if (empty($lockfile)) {
        print 'Lock file not defined!' . PHP_EOL;
        exit(1);
    }

    if ($lock = @fopen($lockfile, 'wb+')) {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            print 'Another process is already running!' . PHP_EOL;
            exit(6);
        }
    } else {
        print 'Unable to access path or lock file: ' . $lockfile . PHP_EOL;
        exit(1);
    }
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
        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $print && print 'Opened gravity database: ' . $db_file . ' (' . formatBytes(filesize($db_file)) . ')' . PHP_EOL . PHP_EOL;

        return $pdo;
    } catch (PDOException $e) {
        print $e->getMessage();
        exit(1);
    }
}

/**
 * Header text of the script
 */
function printHeader()
{
    print '
 Pi-hole Remote Lists Fetcher and Updater
  by Jack\'lul 

 github.com/jacklul/pihole-updatelists
' . PHP_EOL . PHP_EOL;
}

/**
 * Show help message
 */
function printHelp()
{
    printHeader();

    print ' Available options:
  --help              - prints this help message
  --update            - updates the script
  --config=[FILE]     - overrides configuration file
' . PHP_EOL;
}

/**
 * Update the script to newest version
 */
function updateScript()
{
    $base_uri = 'https://raw.githubusercontent.com/jacklul/pihole-updatelists/beta';
    $remote = @file_get_contents($base_uri . '/pihole-updatelists.php');

    if (empty($remote)) {
        print 'Failed to fetch remote script!' . PHP_EOL;
        exit(1);
    }

    if (md5($remote) !== md5_file(__FILE__)) {
        passthru('wget -q -O - ' . $base_uri . '/install.sh | bash', $return);
        if ($return !== 0) {
            print 'Update failed!' . PHP_EOL;
            exit(1);
        }

        print 'Updated successfully!' . PHP_EOL;

        return;
    }

    print 'No need to update!' . PHP_EOL;
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

// PROCEDURAL CODE STARTS HERE
// Check script requirements
checkDependencies();

// Needed variables
$errors = 0;
$duplicates = 0;

// Default configuration
$config = [
    'LOCK_FILE'           => '',
    'CONFIG_FILE'         => '/etc/pihole-updatelists.conf',
    'GRAVITY_DB'          => '/etc/pihole/gravity.db',
    'COMMENT_STRING'      => 'Managed by pihole-updatelists',
    'REQUIRE_COMMENT'     => true,
    'UPDATE_GRAVITY'      => true,
    'VACUUM_DATABASE'     => true,
    'VERBOSE'             => false,
    'ADLISTS_URL'         => '',
    'WHITELIST_URL'       => '',
    'REGEX_WHITELIST_URL' => '',
    'BLACKLIST_URL'       => '',
    'REGEX_BLACKLIST_URL' => '',
];

// Parse parameters (if any), show header and load config
parseParameters();
printHeader();
$config = loadConfig($config['CONFIG_FILE'], $config);

// Make sure this is the only instance
acquireLock($config['LOCK_FILE']);
if ($config['VERBOSE'] === true) {
    print 'Acquired process lock through file: ' . $config['LOCK_FILE'] . ')' . PHP_EOL;
}

// Handle process interruption
register_shutdown_function('shutdownHandler');

// Open the database
$pdo = openDatabase($config['GRAVITY_DB']);

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    print 'Fetching ADLISTS from \'' . $config['ADLISTS_URL'] . '\'...';

    if (($contents = @file_get_contents($config['ADLISTS_URL'])) !== false) {
        $contentsArray = textToArray($contents);
        print ' done (' . count($contentsArray) . ' entries)' . PHP_EOL;

        print 'Processing...' . PHP_EOL;
        $pdo->beginTransaction();

        // Get enabled adlists managed by this script from the DB
        $sql = 'SELECT * FROM `adlist` WHERE `enabled` = 1';

        if ($config['REQUIRE_COMMENT'] === false) {
            $sth = $pdo->prepare($sql);
        } else {
            $sth = $pdo->prepare($sql .= ' AND `comment` LIKE :comment');
            $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
        }

        $listsSimple = [];
        if ($sth->execute()) {
            $lists = $sth->fetchAll();

            $listsSimple = [];
            foreach ($lists as $list) {
                $listsSimple[$list['id']] = $list['address'];
            }
        }

        // Entries that no longer exist in remote list
        $removedEntries = array_diff($listsSimple, $contentsArray);
        if (!empty($removedEntries)) {
            // Disable entries instead of removing them
            foreach ($removedEntries as $removedEntryId => $removedEntryAddress) {
                $entryIsOwned = isset($lists[$removedEntryId]) && strpos($lists[$removedEntryId]['comment'], $config['COMMENT_STRING']) !== false;

                $sql = 'UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id';
                if ($config['REQUIRE_COMMENT'] === false) {
                    $sth = $pdo->prepare($sql);
                } else {
                    $sth = $pdo->prepare($sql .= ' AND `comment` LIKE :comment');
                    $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
                }

                $sth->bindParam(':id', $removedEntryId);

                if ($sth->execute()) {
                    print 'Disabled: ' . $removedEntryAddress . ($entryIsOwned ? '' : ' *') . PHP_EOL;
                }
            }
        }

        // All entries in the list
        foreach ($contentsArray as $entry) {
            // Check whenever entry exists in the DB
            $sth = $pdo->prepare('SELECT * FROM `adlist` WHERE `address` = :address');
            $sth->bindParam(':address', $entry);

            if ($sth->execute()) {
                $entryExists = $sth->fetch();
                $entryIsOwned = strpos($entryExists['comment'] ?? '', $config['COMMENT_STRING']) !== false;

                if (!$entryExists) {
                    // Add entry if it doesn't exist
                    $sth = $pdo->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                    $sth->bindParam(':address', $entry);
                    $sth->bindParam(':comment', $config['COMMENT_STRING']);

                    if ($sth->execute()) {
                        print 'Inserted: ' . $entry . PHP_EOL;
                    }
                } elseif ((int)$entryExists['enabled'] !== 1 && ($config['REQUIRE_COMMENT'] === false || $entryIsOwned)) {
                    // Enable existing entry but only if it's managed by this script
                    $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $entryExists['id']);

                    if ($sth->execute()) {
                        print 'Enabled: ' . $entry . ($entryIsOwned ? '' : ' *') . PHP_EOL;
                    }
                }
            }
        }

        $pdo->commit();
        print PHP_EOL;
    } else {
        print ' failed' . PHP_EOL . 'Error: ' . (error_get_last()['message'] ?: 'Unknown') . PHP_EOL . PHP_EOL;
        $errors++;
    }
} elseif ($config['REQUIRE_COMMENT'] === true) {
    // In case user decides to unset the URL - disable previously added entries
    $sth = $pdo->prepare('SELECT id FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1 LIMIT 1');
    $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);

    if ($sth->execute() && count($sth->fetchAll()) > 0) {
        print 'No remote list set for ADLISTS, disabling orphaned entries in the database...';

        $pdo->beginTransaction();
        $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `comment` LIKE :comment');
        $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);

        if ($sth->execute()) {
            print ' ok' . PHP_EOL;
        } else {
            print ' fail' . PHP_EOL;
        }

        $pdo->commit();
        print PHP_EOL;
    }
}

// This array binds type of list to 'domainlist' table 'type' field, thanks to this we can use foreach loop instead of duplicating code
$domainLists = [
    'WHITELIST'       => 0,
    'REGEX_WHITELIST' => 2,
    'BLACKLIST'       => 1,
    'REGEX_BLACKLIST' => 3,
];

// Fetch WHITELISTS AND BLACKLISTS (both exact and regex)
foreach ($domainLists as $domainListsEntry => $domainListsType) {
    $url_key = $domainListsEntry . '_URL';

    if (!empty($config[$url_key])) {
        print 'Fetching ' . $domainListsEntry . ' from \'' . $config[$url_key] . '\'...';

        if (($contents = @file_get_contents($config[$url_key])) !== false) {
            $contentsArray = textToArray($contents);
            print ' done (' . count($contentsArray) . ' entries)' . PHP_EOL;

            print 'Processing...' . PHP_EOL;
            $pdo->beginTransaction();

            // Get enabled domains of this type managed by this script from the DB
            $sql = 'SELECT * FROM `domainlist` WHERE `enabled` = 1 AND `type` = :type';

            if ($config['REQUIRE_COMMENT'] === false) {
                $sth = $pdo->prepare($sql);
            } else {
                $sth = $pdo->prepare($sql .= ' AND `comment` LIKE :comment');
                $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
            }

            $sth->bindParam(':type', $domainListsType);

            $listsSimple = [];
            if ($sth->execute()) {
                $lists = $sth->fetchAll();

                $listsSimple = [];
                foreach ($lists as $list) {
                    if (strpos(trim($list['domain']), '#') !== 0) {
                        $listsSimple[$list['id']] = $list['domain'];
                    }
                }
            }

            // Entries that no longer exist in remote list
            $removedEntries = array_diff($listsSimple, $contentsArray);
            if (!empty($removedEntries)) {
                // Disable entries instead of removing them
                foreach ($removedEntries as $removedEntryId => $removedEntryDomain) {
                    $entryIsOwned = isset($lists[$removedEntryId]) && strpos($lists[$removedEntryId]['comment'], $config['COMMENT_STRING']) !== false;

                    $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                    $sth->bindParam(':id', $removedEntryId);

                    if ($sth->execute()) {
                        print 'Disabled: ' . $removedEntryDomain . ($entryIsOwned ? '' : ' *') . PHP_EOL;
                    }
                }
            }

            // All entries in the list
            foreach ($contentsArray as $entry) {
                // Check whenever entry exists in the DB
                $sth = $pdo->prepare('SELECT * FROM `domainlist` WHERE `domain` = :domain');
                $sth->bindParam(':domain', $entry);

                if ($sth->execute()) {
                    $entryExists = $sth->fetch();
                    $entryIsOwned = strpos($entryExists['comment'] ?? '', $config['COMMENT_STRING']) !== false;

                    if (!$entryExists) {
                        // Add entry if it doesn't exist
                        $sth = $pdo->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                        $sth->bindParam(':domain', $entry);
                        $sth->bindParam(':type', $domainListsType);
                        $sth->bindParam(':comment', $config['COMMENT_STRING']);

                        if ($sth->execute()) {
                            print 'Inserted: ' . $entry . PHP_EOL;
                        }
                    } elseif (
                        $entryExists['type'] === $domainListsType &&
                        (int)$entryExists['enabled'] !== 1 &&
                        ($config['REQUIRE_COMMENT'] === false || $entryIsOwned)
                    ) {
                        // Enable existing entry but only if it's managed by this script
                        $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $entryExists['id']);

                        if ($sth->execute()) {
                            print 'Enabled: ' . $entry . ($entryIsOwned ? '' : ' *') . PHP_EOL;
                        }
                    } elseif ($entryExists['type'] !== $domainListsType) {
                        print 'Duplicate: ' . $entry . ' (' . (array_search($entryExists['type'], $domainLists, false) ?: 'type=' . $entryExists['type']) . ')' . PHP_EOL;
                        $duplicates++;
                    }
                }
            }

            $pdo->commit();
            print PHP_EOL;
        } else {
            print ' failed' . PHP_EOL . 'Error: ' . (error_get_last()['message'] ?: 'Unknown') . PHP_EOL . PHP_EOL;
            $errors++;
        }
    } elseif ($config['REQUIRE_COMMENT'] === true) {
        // In case user decides to unset the URL - disable previously added entries
        $sth = $pdo->prepare('SELECT id FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type LIMIT 1');
        $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
        $sth->bindParam(':type', $domainListsType);

        if ($sth->execute() && count($sth->fetchAll()) > 0) {
            print 'No remote list set for ' . $domainListsEntry . ', disabling orphaned entries in the database...';

            $pdo->beginTransaction();
            $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `comment` LIKE :comment AND `type` = :type');
            $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
            $sth->bindParam(':type', $domainListsType);

            if ($sth->execute()) {
                print ' ok' . PHP_EOL;
            } else {
                print ' fail' . PHP_EOL;
            }

            $pdo->commit();
            print PHP_EOL;
        }
    }
}

// Close any database handles
$sth = null;
$pdo = null;

if ($config['UPDATE_GRAVITY']) {
    print 'Updating Pi-hole\'s gravity:' . PHP_EOL . PHP_EOL;

    passthru('pihole updateGravity', $return);
    if ($return !== 0) {
        print 'Error occurred while updating gravity!' . PHP_EOL;
        exit(1);
    }

    if ($config['VERBOSE'] === true) {
        print 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL;
    }

    print PHP_EOL;
}

if ($config['VACUUM_DATABASE']) {
    $pdo = openDatabase($config['GRAVITY_DB'], false);

    print 'Vacuuming database...';
    if ($pdo->query('VACUUM')) {
        print ' done' . PHP_EOL;
    }

    if ($config['VERBOSE'] === true) {
        print 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL;
    }

    print PHP_EOL;
}

if ($config['VERBOSE'] === true) {
    print 'Peak memory usage: ' . formatBytes(memory_get_peak_usage()) . PHP_EOL . PHP_EOL;
}

$result = '';

if ($errors > 0) {
    $result .= $errors . ' error(s)';
}

if ($duplicates > 0) {
    if ($result !== '') {
        $result .= ' and ';
    }

    $result .= $duplicates . ' duplicated entries';
}

if ($result === '') {
    print 'Finished successfully.' . PHP_EOL;
} else {
    print 'Finished with ' . $result . '.' . PHP_EOL;
}

$errors > 0 && exit(1);
