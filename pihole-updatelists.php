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
 * Load config file, if exists
 *
 * @return array
 */
function loadConfig()
{
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

    $options = getopt(
        'c::',
        [
            'config::',
        ]
    );

    if (!empty($options) && (isset($options['config']) || isset($options['c']))) {
        empty($options['config']) && !empty($options['c']) && $options['config'] = $options['c'];

        if (!file_exists($options['config'])) {
            print 'Invalid file: ' . $options['config'] . PHP_EOL;
            exit(1);
        }

        $config['CONFIG_FILE'] = $options['config'];
    }

    if (file_exists($config['CONFIG_FILE'])) {
        $loadedConfig = @parse_ini_file($config['CONFIG_FILE'], false, INI_SCANNER_TYPED);
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
        print 'Configuration: ';
        var_dump($config);
        print PHP_EOL;
    }

    return $config;
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

/** PROCEDURAL CODE STARTS HERE */
checkDependencies();    // Check script requirements

// Set needed variables
$config = loadConfig();
$lock = acquireLock($config['LOCK_FILE']);
$wildcardedCommentString = '%' . trim($config['COMMENT_STRING']) . '%'; // Wildcard comment string for SQL queries
$stat = [
    'errors'   => 0,
    'invalid'  => 0,
    'conflict' => 0,
];

// Print script header
print '
 Pi-hole Remote Lists Fetcher and Updater
  by Jack\'lul 

 github.com/jacklul/pihole-updatelists
' . PHP_EOL . PHP_EOL;

// Make sure this is the only instance
if ($config['VERBOSE'] === true) {
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
$pdo = openDatabase($config['GRAVITY_DB']);

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    print 'Fetching ADLISTS from \'' . $config['ADLISTS_URL'] . '\'...';

    if (($contents = @file_get_contents($config['ADLISTS_URL'])) !== false) {
        $adlists = textToArray($contents);
        print ' done (' . count($adlists) . ' entries)' . PHP_EOL;

        print 'Processing...' . PHP_EOL;
        $pdo->beginTransaction();

        // Get enabled adlists managed by this script from the DB
        $sql = 'SELECT * FROM `adlist` WHERE `enabled` = 1';

        if ($config['REQUIRE_COMMENT'] === false) {
            $sth = $pdo->prepare($sql);
        } else {
            $sth = $pdo->prepare($sql .= ' AND `comment` LIKE :comment');
            $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);
        }

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
            // Disable entries instead of removing them
            foreach ($removedLists as $id => $url) {
                $isTouchable = isset($lists[$id]) && strpos($lists[$id]['comment'], $config['COMMENT_STRING']) !== false;

                $sql = 'UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id';
                if ($config['REQUIRE_COMMENT'] === false) {
                    $sth = $pdo->prepare($sql);
                } else {
                    $sth = $pdo->prepare($sql .= ' AND `comment` LIKE :comment');
                    $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);
                }

                $sth->bindParam(':id', $id, PDO::PARAM_INT);

                if ($sth->execute()) {
                    print 'Disabled: ' . $url . ($isTouchable ? '' : ' *') . PHP_EOL;
                }
            }
        }

        // All entries in the list
        foreach ($adlists as $url) {
            // Check from `scripts/pi-hole/php/groups.php` 'add_adlist'
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                print 'Invalid: ' . $url . PHP_EOL;

                if (!isset($stat['invalids']) || !in_array($url, $stat['invalids'], true)) {
                    $stat['invalid']++;
                    $stat['invalids'][] = $url;
                }

                continue;
            }

            // Check whenever entry exists in the DB
            $sth = $pdo->prepare('SELECT * FROM `adlist` WHERE `address` = :address');
            $sth->bindParam(':address', $url, PDO::PARAM_STR);

            if ($sth->execute()) {
                $adlistExists = $sth->fetch();
                $isTouchable = strpos($entryExists['comment'] ?? '', $config['COMMENT_STRING']) !== false;

                isset($adlistExists['enabled']) && $adlistExists['enabled'] = (bool)$adlistExists['enabled'];

                if ($adlistExists === false) {
                    // Add entry if it doesn't exist
                    $sth = $pdo->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                    $sth->bindParam(':address', $url, PDO::PARAM_STR);
                    $sth->bindParam(':comment', $config['COMMENT_STRING'], PDO::PARAM_STR);

                    if ($sth->execute()) {
                        print 'Inserted: ' . $url . PHP_EOL;
                    }
                } elseif (
                    $adlistExists['enabled'] !== true &&
                    ($config['REQUIRE_COMMENT'] === false || $isTouchable === true)
                ) {
                    // Enable existing entry but only if it's managed by this script
                    $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $adlistExists['id'], PDO::PARAM_INT);

                    if ($sth->execute()) {
                        print 'Enabled: ' . $url . ($isTouchable ? '' : ' *') . PHP_EOL;
                    }
                } elseif ($config['VERBOSE'] === true) {
                    if ($adlistExists === true && $isTouchable === true) {
                        print 'Exists: ' . $url . PHP_EOL;
                    } elseif (!$isTouchable) {
                        print 'Ignored: ' . $url . PHP_EOL;
                    }
                }
            }
        }

        $pdo->commit();
        print PHP_EOL;
    } else {
        print ' failed' . PHP_EOL . 'Error: ' . (error_get_last()['message'] ?: 'Unknown') . PHP_EOL . PHP_EOL;

        $stat['errors']++;
    }
} elseif ($config['REQUIRE_COMMENT'] === true) {
    // In case user decides to unset the URL - disable previously added entries
    $sth = $pdo->prepare('SELECT id FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1 LIMIT 1');
    $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);

    if ($sth->execute() && count($sth->fetchAll()) > 0) {
        print 'No remote list set for ADLISTS, disabling orphaned entries in the database...';

        $pdo->beginTransaction();
        $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `comment` LIKE :comment');
        $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);

        if ($sth->execute()) {
            print ' ok' . PHP_EOL;
        }

        $pdo->commit();
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

// Fetch WHITELISTS AND BLACKLISTS (both exact and regex)
foreach ($domainListTypes as $typeName => $typeId) {
    $url_key = $typeName . '_URL';

    if (!empty($config[$url_key])) {
        print 'Fetching ' . $typeName . ' from \'' . $config[$url_key] . '\'...';

        if (($contents = @file_get_contents($config[$url_key])) !== false) {
            $list = textToArray($contents);
            print ' done (' . count($list) . ' entries)' . PHP_EOL;

            print 'Processing...' . PHP_EOL;
            $pdo->beginTransaction();

            // Get enabled domains of this type managed by this script from the DB
            $sql = 'SELECT * FROM `domainlist` WHERE `enabled` = 1 AND `type` = :type';

            if ($config['REQUIRE_COMMENT'] === false) {
                $sth = $pdo->prepare($sql);
            } else {
                $sth = $pdo->prepare($sql .= ' AND `comment` LIKE :comment');
                $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);
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
                // Disable entries instead of removing them
                foreach ($removedDomains as $id => $domain) {
                    $isTouchable = isset($lists[$id]) && strpos($lists[$id]['comment'], $config['COMMENT_STRING']) !== false;

                    $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                    $sth->bindParam(':id', $id, PDO::PARAM_INT);

                    if ($sth->execute()) {
                        print 'Disabled: ' . $domain . ($isTouchable ? '' : ' *') . PHP_EOL;
                    }
                }
            }

            // All entries in the list
            foreach ($list as $domain) {
                $domain = strtolower(extension_loaded('intl') ? idn_to_ascii($domain) : $domain);

                // Check from `scripts/pi-hole/php/groups.php` 'add_domain'
                if (strpos($typeName, 'REGEX_') === false && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                    print 'Invalid: ' . $domain . PHP_EOL;

                    if (!isset($stat['invalids']) || !in_array($domain, $stat['invalids'], true)) {
                        $stat['invalid']++;
                        $stat['invalids'][] = $domain;
                    }

                    continue;
                }

                // Check whenever entry exists in the DB
                $sth = $pdo->prepare('SELECT * FROM `domainlist` WHERE `domain` = :domain');
                $sth->bindParam(':domain', $domain, PDO::PARAM_STR);

                if ($sth->execute()) {
                    $domainExists = $sth->fetch();
                    $isTouchable = strpos($entryExists['comment'] ?? '', $config['COMMENT_STRING']) !== false;

                    isset($domainExists['type']) && $domainExists['type'] = (int)$domainExists['type'];
                    isset($domainExists['enabled']) && $domainExists['enabled'] = (bool)$domainExists['enabled'];

                    if ($domainExists === false) {
                        // Add entry if it doesn't exist
                        $sth = $pdo->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                        $sth->bindParam(':domain', $domain, PDO::PARAM_STR);
                        $sth->bindParam(':type', $typeId, PDO::PARAM_INT);
                        $sth->bindParam(':comment', $config['COMMENT_STRING'], PDO::PARAM_STR);

                        if ($sth->execute()) {
                            print 'Inserted: ' . $domain . PHP_EOL;
                        }
                    } elseif (
                        $domainExists['type'] === $typeId &&
                        $domainExists['enabled'] !== true &&
                        ($config['REQUIRE_COMMENT'] === false || $isTouchable === true)
                    ) {
                        // Enable existing entry but only if it's managed by this script
                        $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $domainExists['id'], PDO::PARAM_INT);

                        if ($sth->execute()) {
                            print 'Enabled: ' . $domain . ($isTouchable ? '' : ' *') . PHP_EOL;
                        }
                    } elseif ($domainExists['type'] !== $typeId) {
                        print 'Conflict: ' . $domain . ' (' . (array_search($domainExists['type'], $domainListTypes, true) ?: 'type=' . $domainExists['type']) . ')' . PHP_EOL;
                        if (!isset($stat['conflicts']) || !in_array($domain, $stat['conflicts'], true)) {
                            $stat['conflict']++;
                            $stat['conflicts'][] = $domain;
                        }
                    } elseif ($config['VERBOSE'] === true) {
                        if ($domainExists === true && $isTouchable === true) {
                            print 'Exists: ' . $domain . PHP_EOL;
                        } elseif (!$isTouchable) {
                            print 'Ignored: ' . $domain . PHP_EOL;
                        }
                    }
                }
            }

            $pdo->commit();
            print PHP_EOL;
        } else {
            print ' failed' . PHP_EOL . 'Error: ' . (error_get_last()['message'] ?: 'Unknown') . PHP_EOL . PHP_EOL;

            $stat['errors']++;
        }
    } elseif ($config['REQUIRE_COMMENT'] === true) {
        // In case user decides to unset the URL - disable previously added entries
        $sth = $pdo->prepare('SELECT id FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type LIMIT 1');
        $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);
        $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

        if ($sth->execute() && count($sth->fetchAll()) > 0) {
            print 'No remote list set for ' . $typeName . ', disabling orphaned entries in the database...';

            $pdo->beginTransaction();
            $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `comment` LIKE :comment AND `type` = :type');
            $sth->bindParam(':comment', $wildcardedCommentString, PDO::PARAM_STR);
            $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

            if ($sth->execute()) {
                print ' ok' . PHP_EOL;
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
    print PHP_EOL;

    if ($return !== 0) {
        print 'Error occurred while updating gravity!' . PHP_EOL . PHP_EOL;
        $stat['errors']++;
    }

    if ($config['VERBOSE'] === true) {
        print 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL . PHP_EOL;
    }
}

if ($config['VACUUM_DATABASE']) {
    $pdo = openDatabase($config['GRAVITY_DB'], false);

    print 'Vacuuming database...';
    if ($pdo->query('VACUUM')) {
        print ' done' . PHP_EOL;
    }

    $pdo = null;

    if ($config['VERBOSE'] === true) {
        print 'Database size: ' . formatBytes(filesize($config['GRAVITY_DB'])) . PHP_EOL;
    }

    print PHP_EOL;
}

if ($config['VERBOSE'] === true) {
    print 'Peak memory usage: ' . formatBytes(memory_get_peak_usage()) . PHP_EOL . PHP_EOL;
}

if ($stat['invalid'] > 0) {
    print 'Did not insert ' . $stat['invalid'] . ' invalid ' . ($stat['invalid'] === 1 ? 'entry' : 'entries') . '.' . PHP_EOL;
}

if ($stat['conflict'] > 0) {
    print 'Found ' . $stat['conflict'] . ' conflicting ' . ($stat['conflict'] === 1 ? 'entry' : 'entries') . ' across all your lists.' . PHP_EOL;
}

if ($stat['errors'] > 0) {
    print 'Finished with ' . $stat['errors'] . ' error(s).' . PHP_EOL;
    exit(1);
}

print 'Finished successfully.' . PHP_EOL;
