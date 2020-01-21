#!/usr/bin/php
<?php
####################################
# Remote Lists updater for Pi-hole #
#  by Jack'lul <jacklul.github.io> #
#                                  #
# github.com/jacklul/raspberry-pi  #
#                                  #
# This script fetches remote lists #
# and merges them into gravity.db. # 
####################################

// Check requirements
checkDependencies();

// Require root privileges
if (posix_getuid() !== 0) {
    echo 'This tool must be run as root!' . PHP_EOL;
    exit(1);
}

// Default configuration array
$config = [
    'LOCK_FILE'           => '/tmp/' . basename(__FILE__) . '.lock',
    'CONFIG_FILE'         => '/etc/pihole-updatelists.conf',
    'GRAVITY_DB'          => '/etc/pihole/gravity.db',
    'COMMENT_STRING'      => 'Managed by pihole-updatelists',
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

// Load config file, if exists
if (file_exists($config['CONFIG_FILE'])) {
    $config = array_merge($config, parse_ini_file($config['CONFIG_FILE']));
}

// For SQL queries
$config['COMMENT_STRING_WILDCARD'] = '%' . trim($config['COMMENT_STRING']) . '%';

// Check for required stuff
function checkDependencies()
{
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		echo 'Windows is not supported!';
		exit(1);
	}
	
    $extensions = [
        'pdo',
        'pdo_sqlite',
    ];

    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            echo 'Missing PHP extension: ' . $extension . PHP_EOL;
            exit(1);
        }
    }
}

// Convert text files from one-entry-per-line to array
function textToArray($text)
{
    $text = preg_split('/\r\n|\r|\n/', $text);
    foreach ($text as $var => $val) {
        if (empty($val)) {
            unset($text[$var]);
        }
    }

    return $text;
}

// Handle script shutdown - cleanup, lock file unlock
function shutdownHandler()
{
    global $config, $lock;

    flock($lock, LOCK_UN);
    fclose($lock);

    unlink($config['LOCK_FILE']);
}

// Aquire process lock
$lock = fopen($config['LOCK_FILE'], 'wb+');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Another process is already running!';
    exit(6);
}
register_shutdown_function('shutdownHandler');

// Let's begin!
$start = microtime(true);
print '
  Pi-hole Remote Lists Fetcher
    by Jack\'lul

  https://github.com/jacklul/raspberry-pi
' . PHP_EOL;

// Open the database
try {
    $pdo = new PDO('sqlite:' . $config['GRAVITY_DB']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo 'Opened gravity database: ' . $config['GRAVITY_DB'] . PHP_EOL . PHP_EOL;
} catch (PDOException $e) {
    echo $e->getMessage();
    exit(1);
}

// Optimize the database before doing anything
echo 'Optimizing database...';
if ($pdo->query('VACUUM')) {
    echo ' done' . PHP_EOL . PHP_EOL;
} else {
    echo ' error' . PHP_EOL . PHP_EOL;
}

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    echo 'Downloading ADLISTS...';

    if ($contents = @file_get_contents($config['ADLISTS_URL'])) {
        $contentsArray = textToArray($contents);
        echo ' done (' . count($contentsArray) . ' entries)' . PHP_EOL;

        echo 'Processing...' . PHP_EOL;
        $pdo->beginTransaction();

        // Get enabled adlists managed by this script from the DB
        $sth = $pdo->prepare('SELECT * FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1');
        $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);

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
                $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id');
                $sth->bindParam(':id', $removedEntryId);

                if ($sth->execute()) {
                    echo 'Disabled: ' . $removedEntryAddress . PHP_EOL;
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

                if (!$entryExists) {
                    // Add entry if it doesn't exist
                    $sth = $pdo->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                    $sth->bindParam(':address', $entry);
                    $sth->bindParam(':comment', $config['COMMENT_STRING']);

                    if ($sth->execute()) {
                        echo 'Inserted: ' . $entry . PHP_EOL;
                    }
                } elseif ($entryExists['enabled'] != 1 && strpos($entryExists['comment'], $config['COMMENT_STRING']) !== false) {
                    // Enable existing entry but only if it's managed by this script
                    $sth = $pdo->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $entryExists['id']);

                    if ($sth->execute()) {
                        echo 'Enabled: ' . $entry . PHP_EOL;
                    }
                }
            }
        }

        $pdo->commit();
        echo PHP_EOL;
    } else {
        echo ' failed' . PHP_EOL;
    }
} else {
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

// This array binds type of list to 'domainlist' table 'type' field
$domainLists = [
    'WHITELIST'       => 0,
    'BLACKLIST'       => 1,
    'REGEX_WHITELIST' => 2,
    'REGEX_BLACKLIST' => 3,
];

// Fetch WHITELISTS AND BLACKLISTS (both exact and regex)
foreach ($domainLists as $domainListsEntry => $domainListsType) {
    $url = $domainListsEntry . '_URL';

    if (!empty($config[$url])) {
        echo 'Downloading ' . $domainListsEntry . '...';

        if ($contents = @file_get_contents($config[$url])) {
            $contentsArray = textToArray($contents);
            echo ' done (' . count($contentsArray) . ' entries)' . PHP_EOL;

            echo 'Processing...' . PHP_EOL;
            $pdo->beginTransaction();

            // Get enabled domains of this type managed by this script from the DB
            $sth = $pdo->prepare('SELECT * FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type');
            $sth->bindParam(':comment', $config['COMMENT_STRING_WILDCARD']);
            $sth->bindParam(':type', $domainListsType);

            $listsSimple = [];
            if ($sth->execute()) {
                $lists = $sth->fetchAll();

                $listsSimple = [];
                foreach ($lists as $list) {
                    $listsSimple[$list['id']] = $list['domain'];
                }
            }

            // Entries that no longer exist in remote list
            $removedEntries = array_diff($listsSimple, $contentsArray);
            if (!empty($removedEntries)) {
                // Disable entries instead of removing them
                foreach ($removedEntries as $removedEntryId => $removedEntryDomain) {
                    $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                    $sth->bindParam(':id', $removedEntryId);

                    if ($sth->execute()) {
                        echo 'Disabled: ' . $removedEntryDomain . PHP_EOL;
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

                    if (!$entryExists) {
                        // Add entry if it doesn't exist
                        $sth = $pdo->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                        $sth->bindParam(':domain', $entry);
                        $sth->bindParam(':type', $domainListsType);
                        $sth->bindParam(':comment', $config['COMMENT_STRING']);


                        if ($sth->execute()) {
                            echo 'Inserted: ' . $entry . PHP_EOL;
                        }
                    } elseif ($entryExists['type'] == $domainListsType && $entryExists['enabled'] != 1 && strpos($entryExists['comment'], $config['COMMENT_STRING']) !== false) {
                        // Enable existing entry but only if it's managed by this script
                        $sth = $pdo->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $entryExists['id']);

                        if ($sth->execute()) {
                            echo 'Enabled: ' . $entry . PHP_EOL;
                        }
                    } elseif ($entryExists['type'] != $domainListsType) {
						echo 'Duplicate: ' . $entry . PHP_EOL;
					}
                }
            }

            $pdo->commit();
            echo PHP_EOL;
        } else {
            echo ' failed' . PHP_EOL;
        }
    } else {
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

// Unlock database
$pdo = null;

// Update gravity
echo 'Updating Pi-hole\'s gravity...' . PHP_EOL . PHP_EOL;

passthru('pihole -g', $return);
echo PHP_EOL . 'Done in ' . round(microtime(true) - $start, 2) . 's' . PHP_EOL . PHP_EOL;

if ($return !== 0) {
    echo 'Error occurred while updating gravity!' . PHP_EOL;
    exit(1);
}

echo 'Finished successfully.' . PHP_EOL;
