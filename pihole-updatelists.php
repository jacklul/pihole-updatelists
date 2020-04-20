#!/usr/bin/php
<?php
#########################################
#   Remote Lists updater for Pi-hole    #
#    by Jack'lul <jacklul.github.io>    #
#                                       #
# github.com/jacklul/pihole-updatelists #
#########################################

// Check requirements
checkDependencies();

// Let's begin
$start = microtime(true);
$errors = 0;
printHeader();

// Default configuration
$config = [
    'LOCK_FILE'           => '',
    'CONFIG_FILE'         => '/etc/pihole-updatelists.conf',
    'GRAVITY_DB'          => '/etc/pihole/gravity.db',
    'COMMENT_STRING'      => 'Managed by pihole-updatelists',
    'REQUIRE_COMMENT'     => true,
    'UPDATE_GRAVITY'      => true,
    'OPTIMIZE_DB'         => true,
    'VERBOSE'             => false,
    'ADLISTS_URL'         => '',
    'WHITELIST_URL'       => '',
    'REGEX_WHITELIST_URL' => '',
    'BLACKLIST_URL'       => '',
    'REGEX_BLACKLIST_URL' => '',
];

// Allow to override config file by first argument
if (isset($argv[1]) && file_exists($argv[1])) {
    $config['CONFIG_FILE'] = $argv[1];
}

$config = loadConfig($config['CONFIG_FILE'], $config);

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
        $loadedConfig = parse_ini_file($config_file);
        if (!is_array($loadedConfig)) {
            echo 'Failed to load configuration file!' . PHP_EOL;
            exit(1);
        }

        $config = array_merge($config, $loadedConfig);
    }

    $config['COMMENT_STRING_WILDCARD'] = '%' . trim($config['COMMENT_STRING']) . '%'; // Wildcard comment string for SQL queries

    if (empty($config['LOCK_FILE'])) {
        $config['LOCK_FILE'] = '/tmp/' . basename(__FILE__) . '-' . md5($config['CONFIG_FILE']) . '.lock';
    }

    if ((bool)$config['VERBOSE'] === true) {
        echo 'Configuration:' . PHP_EOL;

        foreach ($config as $var => $val) {
            echo ' ' . $var . ' = ' . $val . PHP_EOL;
        }

        echo PHP_EOL;
    }

    return $config;
}

/**
 * Check for required stuff
 */
function checkDependencies()
{
    // Do not run on PHP lower than 7.0
    if ((float)PHP_VERSION < 7.0) {
        echo 'Minimum PHP 7.0 is required to run this script!' . PHP_EOL;
        exit(1);
    }

    // Windows is obviously not supported
    if (stripos(PHP_OS, 'WIN') === 0) {
        echo 'Windows is not supported!' . PHP_EOL;
        exit(1);
    }

    $extensions = [
        'pdo',
        'pdo_sqlite',
    ];

    // Required PHP extensions
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            echo 'Missing PHP extension: ' . $extension . PHP_EOL;
            exit(1);
        }
    }

    // Require root privileges
    if (posix_getuid() !== 0) {
        echo 'This tool must be run as root!' . PHP_EOL;
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
    foreach ($array as $var => $val) {
        if (empty($val) || strpos(trim($val), '#') === 0) {
            unset($array[$var]);
        }
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
 * Aquire process lock
 *
 * @param string $lockfile
 */
function acquireLock($lockfile)
{
    global $lock;

    if (empty($lockfile)) {
        echo 'Lock file not defined!' . PHP_EOL;
        exit(1);
    }

    $lock = fopen($lockfile, 'wb+');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        echo 'Another process is already running!' . PHP_EOL;
        exit(6);
    }
}

/**
 * Open the database
 *
 * @param string $db_file
 *
 * @return PDO
 */
function openDatabase($db_file)
{
    try {
        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        echo 'Opened gravity database: ' . $db_file . ' (' . formatBytes(filesize($db_file)) . ')' . PHP_EOL . PHP_EOL;

        return $pdo;
    } catch (PDOException $e) {
        echo $e->getMessage();
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

acquireLock($config['LOCK_FILE']);
if ((bool)$config['VERBOSE'] === true) {
    echo 'Acquired process lock through file: ' . $config['LOCK_FILE'] . ')' . PHP_EOL;
}

register_shutdown_function('shutdownHandler');
$pdo = openDatabase($config['GRAVITY_DB']);

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    echo 'Fetching ADLISTS from \'' . $config['ADLISTS_URL'] . '\'...';

    if ($contents = @file_get_contents($config['ADLISTS_URL'])) {
        $contentsArray = textToArray($contents);
        echo ' done (' . count($contentsArray) . ' entries)' . PHP_EOL;

        echo 'Processing...' . PHP_EOL;
        $pdo->beginTransaction();

        // Get enabled adlists managed by this script from the DB
        $sql = 'SELECT * FROM `adlist` WHERE `enabled` = 1';

        if ((bool)$config['REQUIRE_COMMENT'] === false) {
            $sth = $pdo->prepare($sql);
        } else {
            $sth = $pdo->prepare($sql .= 'AND `comment` LIKE :comment');
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
                if ((bool)$config['REQUIRE_COMMENT'] === false) {
                    $sth = $pdo->prepare($sql);
                } else {
                    $sth = $pdo->prepare($sql .= 'AND `comment` LIKE :comment');
                    $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
                }

                if ($sth->execute()) {
                    echo 'Disabled: ' . $removedEntryAddress . ($entryIsOwned ? '' : ' *') . PHP_EOL;
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
                $entryIsOwned = strpos($entryExists['comment'], $config['COMMENT_STRING']) !== false;

                if (!$entryExists) {
                    // Add entry if it doesn't exist
                    $sth = $pdo->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                    $sth->bindParam(':address', $entry);
                    $sth->bindParam(':comment', $config['COMMENT_STRING']);

                    if ($sth->execute()) {
                        echo 'Inserted: ' . $entry . PHP_EOL;
                    }
                } elseif ((int)$entryExists['enabled'] !== 1 && ((bool)$config['REQUIRE_COMMENT'] === false || $entryIsOwned)) {
                    // Enable existing entry but only if it's managed by this script
                    $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $entryExists['id']);

                    if ($sth->execute()) {
                        echo 'Enabled: ' . $entry . ($entryIsOwned ? '' : ' *') . PHP_EOL;
                    }
                }
            }
        }

        $pdo->commit();
        echo PHP_EOL;
    } else {
        echo ' failed' . PHP_EOL . 'Error: ' . error_get_last()['message'] . PHP_EOL . PHP_EOL;
        $errors++;
    }
} elseif ((bool)$config['REQUIRE_COMMENT'] === true) {
    // In case user decides to unset the URL - disable previously added entries
    $sth = $pdo->prepare('SELECT id FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1 LIMIT 1');
    $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);

    if ($sth->execute() && count($sth->fetchAll()) > 0) {
        echo 'No remote list set for ADLISTS, disabling orphaned entries in the database...';

        $pdo->beginTransaction();
        $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `comment` LIKE :comment');
        $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);

        if ($sth->execute()) {
            echo ' ok' . PHP_EOL;
        } else {
            echo ' fail' . PHP_EOL;
        }

        $pdo->commit();
        echo PHP_EOL;
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
        echo 'Fetching ' . $domainListsEntry . ' from \'' . $config[$url_key] . '\'...';

        if ($contents = @file_get_contents($config[$url_key])) {
            $contentsArray = textToArray($contents);
            echo ' done (' . count($contentsArray) . ' entries)' . PHP_EOL;

            echo 'Processing...' . PHP_EOL;
            $pdo->beginTransaction();

            // Get enabled domains of this type managed by this script from the DB
            $sql = 'SELECT * FROM `domainlist` WHERE `enabled` = 1 AND `type` = :type';

            if ((bool)$config['REQUIRE_COMMENT'] === false) {
                $sth = $pdo->prepare($sql);
            } else {
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
                        echo 'Disabled: ' . $removedEntryDomain . ($entryIsOwned ? '' : ' *') . PHP_EOL;
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
                    $entryIsOwned = strpos($entryExists['comment'], $config['COMMENT_STRING']) !== false;

                    if (!$entryExists) {
                        // Add entry if it doesn't exist
                        $sth = $pdo->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                        $sth->bindParam(':domain', $entry);
                        $sth->bindParam(':type', $domainListsType);
                        $sth->bindParam(':comment', $config['COMMENT_STRING']);

                        if ($sth->execute()) {
                            echo 'Inserted: ' . $entry . PHP_EOL;
                        }
                    } elseif (
                        $entryExists['type'] === $domainListsType &&
                        (int)$entryExists['enabled'] !== 1 &&
                        ((bool)$config['REQUIRE_COMMENT'] === false || $entryIsOwned)
                    ) {
                        // Enable existing entry but only if it's managed by this script
                        $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $entryExists['id']);

                        if ($sth->execute()) {
                            echo 'Enabled: ' . $entry . ($entryIsOwned ? '' : ' *') . PHP_EOL;
                        }
                    } elseif ($entryExists['type'] !== $domainListsType) {
                        echo 'Duplicate: ' . $entry . PHP_EOL;
                    }
                }
            }

            $pdo->commit();
            echo PHP_EOL;
        } else {
            echo ' failed' . PHP_EOL . 'Error: ' . error_get_last()['message'] . PHP_EOL . PHP_EOL;
            $errors++;
        }
    } elseif ((bool)$config['REQUIRE_COMMENT'] === true) {
        // In case user decides to unset the URL - disable previously added entries
        $sth = $pdo->prepare('SELECT id FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type LIMIT 1');
        $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
        $sth->bindParam(':type', $domainListsType);

        if ($sth->execute() && count($sth->fetchAll()) > 0) {
            echo 'No remote list set for ' . $domainListsEntry . ', disabling orphaned entries in the database...';

            $pdo->beginTransaction();
            $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `comment` LIKE :comment AND `type` = :type');
            $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
            $sth->bindParam(':type', $domainListsType);

            if ($sth->execute()) {
                echo ' ok' . PHP_EOL;
            } else {
                echo ' fail' . PHP_EOL;
            }

            $pdo->commit();
            echo PHP_EOL;
        }
    }
}

if ((bool)$config['OPTIMIZE_DB']) {
    // Close any prepared statements to allow unprepared queries
    $sth = null;

    // Reduce database size
    echo 'Compacting database...';
    if ($pdo->query('VACUUM')) {
        echo ' done' . PHP_EOL;
    }

    // Optimize the database
    echo 'Optimizing database...';
    if ($pdo->query('PRAGMA optimize')) {
        echo ' done' . PHP_EOL;
    }

    if ((bool)$config['VERBOSE'] === true) {
        echo 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL;
    }

    echo PHP_EOL;
}

if ((bool)$config['UPDATE_GRAVITY']) {
    // Close database handle to unlock it for gravity update
    $pdo = null;

    // Update gravity
    echo 'Updating Pi-hole\'s gravity...' . PHP_EOL . PHP_EOL;

    passthru('pihole updateGravity', $return);
    if ($return !== 0) {
        echo 'Error occurred while updating gravity!' . PHP_EOL;
        exit(1);
    }

    echo PHP_EOL . 'Done in ' . round(microtime(true) - $start, 2) . 's' . PHP_EOL;

    if ((bool)$config['VERBOSE'] === true) {
        echo 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL;
    }

    echo PHP_EOL;
}

if ((bool)$config['VERBOSE'] === true) {
    echo 'Peak memory usage: ' . formatBytes(memory_get_peak_usage()) . PHP_EOL . PHP_EOL;
}

if ($errors > 0) {
    echo 'Finished with ' . $errors . ' error(s).' . PHP_EOL;
    exit(1);
}

echo 'Finished successfully.' . PHP_EOL;
