# Update Pi-hole's lists from remote sources

When using remote lists like [this](https://v.firebog.net/hosts/lists.php?type=tick) or [this](https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt) it's a hassle to manually check for changes and update, this script will do that for you.

Entries that were removed from the remote list will be disabled instead of removed, this is to prevent database corruption.

### Requirements

- Pi-hole v5+ installed
- php-cli >=7.0 and sqlite3 extension (`sudo apt install php-cli php-sqlite3`)
- systemd distro is optional but recommended

### Install

This will install this script globally as `pihole-updatelists` and add systemd service and timer.

```bash
wget -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | sudo bash
```

Alternatively you can clone this repo and `sudo bash ./install.sh`.

When configuration file already exists this script will not overwrite it so it's safe to update at any time.

In the future to quickly update the script you can use `sudo pihole-updatelists --update`.

Note that you should disable `pihole updateGravity` entry in `/etc/cron.d/pihole` as this script already runs it, unless you're planning on disabling this with configuration setting `UPDATE_GRAVITY` set to `false`.

### Configuration

Default configuration file is `/etc/pihole-updatelists.conf`.

#### Available variables:

| Variable | Default | Description |
|----------|---------|-------------|
| ADLISTS_URL | " " | Remote list URL containing adlists |
| WHITELIST_URL | " " | Remote list URL containing exact domains to whitelist |
| REGEX_WHITELIST_URL | " " | Remote list URL containing regex rules for whitelisting |
| BLACKLIST_URL | " " | Remote list URL containing exact domains to blacklist |
| REGEX_BLACKLIST_URL | " " | Remote list URL containing regex rules for blacklisting |
| COMMENT | "Managed by pihole-updatelists" | Comment string used to know which entries were created by the script |
| GROUP_ID | 0 | All inserted adlists and domains will have this additional group ID assigned (`0` is the default group to which all entries are added anyway) |
| REQUIRE_COMMENT | true | Prevent touching entries not created by this script by comparing comment field |
| UPDATE_GRAVITY | true | Update gravity after lists are updated? (runs `pihole updateGravity`, when disabled will invoke lists reload instead) |
| VACUUM_DATABASE | true | Vacuum database at the end? (runs `VACUUM` SQLite command) |
| VERBOSE | false | Print more information while script is running? |
| DEBUG | false | Print even more information for debugging purposes |
| DOWNLOAD_TIMEOUT | 60 | Maximum time in seconds one list download can take before giving up (you should increase this when downloads fail) | 
| GRAVITY_DB | "/etc/pihole/gravity.db" | Path to `gravity.db` in case you need to change it |
| LOCK_FILE | "/var/lock/pihole-updatelists.lock" | Process lockfile to prevent multiple instances of the script, you shouldn't change it - unless `/var/lock` is unavailable |
| LOG_FILE | " " | Log console output to file (put `-` before path to overwrite file instead of appending to it), typically `/var/log/pihole-updatelists.log` is good |

String values should be put between `" "`, otherwise weird things might happen.

You can also give paths to the local files instead of URLs, for example setting `WHITELIST_URL` to `/home/pi/whitelist.txt` will fetch this file from filesystem.

You can specify alternative config file by passing the path to the script through `config` parameter: `pihole-updatelists --config=/home/pi/pihole-updatelists2.conf` - this combined with different `COMMENT` string can allow multiple script configurations for the same Pi-hole instance.

#### Multiple list URLs:

You can pass multiple URLs to the list variables by separating them with whitespace (space or new line):

```bash
ADLISTS_URL="https://v.firebog.net/hosts/lists.php?type=tick  https://raw.githubusercontent.com/you/adlists/master/my_adlists.txt"
```

If one of the lists fails to download nothing will be affected for that list type.

#### Recommended lists:

| List | URL | Description |
|----------|-------------|-------------|
| ADLISTS_URL | https://v.firebog.net/hosts/lists.php?type=tick | https://firebog.net - safe lists only |
| WHITELIST_URL | https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt | https://github.com/anudeepND/whitelist - commonly whitelisted |
| REGEX_BLACKLIST_URL | https://raw.githubusercontent.com/mmotti/pihole-regex/master/regex.list | https://github.com/mmotti/pihole-regex - basic regex rules |

### Runtime options

| Option | Description |
|----------|-------------|
| -help, -h | Show help message, which is simply this list |
| --config=[FILE], -c=[FILE] | Load alternative configuration file |
| --no-gravity, -n | Force gravity update to be skipped |
| --no-vacuum, -m | Force database vacuuming to be skipped |
| --verbose, -v | Turn on verbose mode (affects log) |
| --debug, -d  | Turn on debug mode (affects log) |
| --update, -u  | Update the script - compares local script with the one in the repository and overwrites it if they are different |

### Changing the schedule

By default, the script runs at random time (between 03:00 and 04:00) on Saturday, to change it you'll have to override [timer unit](https://www.freedesktop.org/software/systemd/man/systemd.timer.html) file:
 
`sudo systemctl edit pihole-updatelists.timer`
```
[Timer]
RandomizedDelaySec=5m
OnCalendar=
OnCalendar=Sat *-*-* 00:00:00
```

### Running custom commands before/after scheduled run

Override [service unit](https://www.freedesktop.org/software/systemd/man/systemd.service.html) file:

`sudo systemctl edit pihole-updatelists.service`

```
[Service]
Type=oneshot
ExecStartPre=echo "before"
ExecStartPost=echo "after"
```

## License

[MIT License](/LICENSE).
