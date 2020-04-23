# Update Pi-hole lists from remote sources

**This branch is for Pi-hole v5 beta only!**

This script will update your lists using remote ones.

It will automatically merge with existing entries on the lists and disable entries that have been removed from the remote list. Nothing will be ever deleted by this script to prevent data loss or database corruption.

Group association __is not supported__, it has to be done manually.

### Requirements

- Pi-hole already installed
- php-cli >=7.0 and sqlite3 extension (`sudo apt install php-cli php-sqlite3`)

### Install

This will install this script globally as `pihole-updatelists` and add systemd service and timer.

```bash
wget -q -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/beta/install.sh | sudo bash
```

Alternatively you can clone this repo and `sudo bash ./install.sh`.

You should disable `pihole updateGravity` entry in `/etc/cron.d/pihole` as this script already runs it, unless you're planning on disabling this with setting `UPDATE_GRAVITY` set to `false`.

### Configuration

Configuration file is located in `/etc/pihole-updatelists.conf`, install script does not overwrite it when it exists.

#### Available variables:

| Variable | Default | Description |
|----------|---------|-------------|
| ADLISTS_URL | " " | Remote list URL containing blocklists |
| WHITELIST_URL | " " | Remote list URL with exact domains to whitelist |
| REGEX_WHITELIST_URL | " " | Remote list URL with regex rules for whitelisting |
| BLACKLIST_URL | " " | Remote list URL with exact domains to blacklist |
| REGEX_BLACKLIST_URL | " " | Remote list URL with regex rules for blacklisting |
| REQUIRE_COMMENT | true | Require specific comment in entries for script to touch them, disabling this will let script enable and disable any entry in the database |
| COMMENT_STRING | "Managed by pihole-updatelists" | The default comment for entries created by this script (should not be changed after script was executed) |
| UPDATE_GRAVITY | true | Update gravity after lists are updated? (runs `pihole updateGravity`) |
| VACUUM_DATABASE | true | Vacuum database at the end? (runs `VACUUM` SQLite command) |
| VERBOSE | false | Print extra information while script is running, for debugging purposes |

String values should be put between `" "`, otherwise weird things might happen.

You can specify alternative config file by passing the path to the script through `config` parameter: `pihole-updatelists --config=/etc/pihole-updatelists2.conf` - this combined with changed `COMMENT_STRING` can allow multiple script configurations for the same Pi-hole instance.

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

### Running custom commands before and/or after

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
