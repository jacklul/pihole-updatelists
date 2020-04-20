# Pi-hole - update lists from remote servers

**This branch is for Pi-hole v5 beta only!**

This script will update your lists using remote ones.

It will automatically merge with existing entries on the lists and disable entries that have been removed from the remote list. Nothing will be ever deleted by this script to prevent data loss or database corruption.

Group association __is not supported__, it has to be done manually.

### Requirements

- Pi-hole already installed
- php-cli >=7.0 and sqlite3 extension (`sudo apt install php-cli php-sqlite3`)

### Install

```bash
wget -q -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/beta/install.sh | sudo bash
```

Alternatively you can clone this repo and `sudo bash ./install.sh`.

### Configuration

Configuration file is located in `/etc/pihole-updatelists.conf`.

You can specify alternative config file by passing the path to the script: `pihole-updatelists /etc/pihole-updatelists2.conf` - this combined with changed `COMMENT_STRING` can allow multiple script configurations for the same Pi-hole instance.

#### Configuration variables:

| Variable | Default | Description |
|----------|---------|-------------|
| ADLISTS_URL | " " | Remote list URL containing blocklists |
| WHITELIST_URL | " " | Remote list URL with exact domains to whitelist |
| REGEX_WHITELIST_URL | " " | Remote list URL with regex rules for whitelisting |
| BLACKLIST_URL | " " | Remote list URL with exact domains to blacklist |
| REGEX_BLACKLIST_URL | " " | Remote list URL with regex rules for blacklisting |
| REQUIRE_COMMENT | true | Require specific comment in entries for script to touch them, disabling this will let script enable and disable any entry in the database |
| COMMENT_STRING | "Managed by pihole-updatelists" | The default comment for entries created by this script (should not be changed after script was executed) |
| OPTIMIZE_DB | true | Optimize database after script is done? (runs `VACUUM` and `PRAGMA optimize` SQLite commands) |
| UPDATE_GRAVITY | true | Update gravity after script is done? (runs `pihole updateGravity`) |
| VERBOSE | false | Print extra information while script is running, for debugging purposes |

String values should be put between `" "`, otherwise weird things might happen.

You can also give paths to local files instead of URL, for example setting `WHITELIST_URL` to `/home/pi/whitelist.txt` will fetch this file.

### Changing the time it runs

By default it runs at 00:00 Friday->Saturday.

Override systemd timer unit file.
 
`sudo systemctl edit pihole-updatelists.timer`

```
[Timer]
OnCalendar=Sat *-*-* 00:00:00
Persistent=true
```

[Timers configuration reference](https://www.freedesktop.org/software/systemd/man/systemd.timer.html).

### Running without systemd

If your system does not use systemd you can use cron daemon of your choice.

Just add cron entry for `/usr/local/sbin/pihole-updatelists`.

```
0 0 * * SAT     /usr/local/sbin/pihole-updatelists
```

### Notes

- You should disable `pihole updateGravity` entry in `/etc/cron.d/pihole` as this script already runs it, unless you're planning on disabling this with setting `UPDATE_GRAVITY` set to `false`.

- Entries marked with `*` in script output are not owned by the script (comment field does not match), you will see this when `REQUIRE_COMMENT` is set to `false`.

## License

[MIT License](/LICENSE).
