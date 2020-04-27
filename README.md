# Update Pi-hole's lists from remote sources

**This branch is for Pi-hole v5 beta only!**

When using remote lists like [this](https://v.firebog.net/hosts/lists.php?type=tick) or [this](https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt) it's a hassle to manually check for changes and update, this script will do that for you.

Entries that were removed from the remote list will be disabled instead of removed, this is to prevent database corruption.

### Requirements

- Pi-hole already installed
- php-cli >=7.0 and sqlite3 extension (`sudo apt install php-cli php-sqlite3`)
- systemd distro is optional but recommended

### Install

This will install this script globally as `pihole-updatelists` and add systemd service and timer.

```bash
wget -q -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/beta/install.sh | sudo bash
```

Alternatively you can clone this repo and `sudo bash ./install.sh`.

When configuration file already exists this script will not overwrite it so it's safe to update at any time.

You should disable `pihole updateGravity` entry in `/etc/cron.d/pihole` as this script already runs it, unless you're planning on disabling this with setting `UPDATE_GRAVITY` set to `false`.

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
| GRAVITY_DB | "/etc/pihole/gravity.db" | Path to `gravity.db` in case you need to change it |
| LOCK_FILE | "/var/lock/pihole-updatelists.lock" | Process lockfile to prevent multiple instances of the script, you shouldn't change it - unless `/var/lock` is unavailable |
| LOG_FILE | " " | Log console output to file (put `-` before path to overwrite file instead of appending to it), typically `/var/log/pihole-updatelists.log` is good |

String values should be put between `" "`, otherwise weird things might happen.

You can specify alternative config file by passing the path to the script through `config` parameter: `pihole-updatelists --config=/etc/pihole-updatelists2.conf` - this combined with different `COMMENT` string can allow multiple script configurations for the same Pi-hole instance.

You can also give paths to local files instead of URL, for example setting `WHITELIST_URL` to `/home/pi/whitelist.txt` will fetch this file from filesystem.

#### Recommended lists:

| List | URL | Description |
|----------|-------------|-------------|
| ADLISTS_URL | https://v.firebog.net/hosts/lists.php?type=tick | https://firebog.net - safe lists only |
| WHITELIST_URL | https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt | https://github.com/anudeepND/whitelist - commonly whitelisted |
| REGEX_BLACKLIST_URL | https://raw.githubusercontent.com/mmotti/pihole-regex/master/regex.list | https://github.com/mmotti/pihole-regex - basic regex rules |

### Changing the schedule

By default, the script runs at 00:00 Friday->Saturday, to change it you'll have to override [timer unit](https://www.freedesktop.org/software/systemd/man/systemd.timer.html) file:
 
`sudo systemctl edit pihole-updatelists.timer`
```
[Timer]
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
