# Update Pi-hole's lists from remote sources

When using remote lists like [this](https://v.firebog.net/hosts/lists.php?type=tick) or [this](https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt) it's a hassle to manually check for changes and update - this script will do that for you!

User-created entries will not be touched and those removed from the remote list will be disabled instead.

__If you're not using remote lists like the ones mentioned above then this script will be useless to you - Pi-hole already updates the lists weekly automatically.__

## Requirements

- **Pi-hole v5+** installed (fresh install preferred)
- **php-cli >=7.0** and **a few extensions** (`sudo apt-get install php-cli php-sqlite3 php-intl php-curl`)
- **systemd** is optional but recommended

## Install

_Docker users - [look below](#install-with-docker)._

This command will install this script to `/usr/local/sbin`:

```bash
wget -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | sudo bash
```

_Alternatively you can clone this repo and `sudo bash install.sh`._

If **systemd** is available this will also add service and timer unit files to the system, otherwise a crontab entry in `/etc/cron.d/pihole-updatelists` will be created.

**In the future to quickly update the script you can use `sudo pihole-updatelists --update`.**

Note that in most cases you will be able to execute this script globally as `pihole-updatelists` command but some will require you to add `/usr/local/sbin` to `$PATH` or execute it via `/usr/local/sbin/pihole-updatelists`.

__This script does nothing by default (except running `pihole updateGravity`), you have to [configure it](#configuration).__

### Disable default gravity update schedule

_If you don't plan on updating adlists or want to keep Pi-hole's gravity update schedule you should skip this section and set `UPDATE_GRAVITY=false` in the configuration file._

You should disable entry with `pihole updateGravity` command in `/etc/cron.d/pihole` as this script already runs it:

```bash
sudo nano /etc/cron.d/pihole
```
Put a `#` before this line (numbers might be different):
```
#49 4   * * 7   root    PATH="$PATH:/usr/local/bin/" pihole updateGravity >/var/log/pihole_updateGravity.log || cat /var/log/pihole_updateGravity.log
```

**You might have to do this after each Pi-hole update.**

### Migrating lists and domains

If you already imported any of the remote lists manually you should migrate their entries to allow the script to disable them in case they are removed from the remote list.

If you used [pihole5-list-tool](https://github.com/jessedp/pihole5-list-tool) to import adlists and whitelist you can use these commands to do this quickly:
```bash
sudo sqlite3 /etc/pihole/gravity.db "UPDATE adlist SET comment = 'Managed by pihole-updatelists' WHERE comment LIKE '%Firebog |%' OR comment LIKE '%[ph5lt]'"
sudo sqlite3 /etc/pihole/gravity.db "UPDATE domainlist SET comment = 'Managed by pihole-updatelists' WHERE comment LIKE '%AndeepND |%' OR comment LIKE '%[ph5lt]'"
```
_(code up to date as of pihole5-list-tool 0.6.0)_

Alternatively, some manual work is required - pick one:

- Manually modify comment field of all imported domains/adlists to match the one this script uses (see `COMMENT` variable in **Configuration** section) **(recommended but might be a lot of work)**
- Manually delete all imported domains/adlists from the web interface (might be a lot of work)
- Wipe all adlists and domains (not recommended but fast - use this if you want to start fresh)
  - backup your lists and custom entries (write them down somewhere, do not use the Teleporter)
  - run the following commands:
	```bash
	sudo sqlite3 /etc/pihole/gravity.db "DELETE FROM adlist"
	sudo sqlite3 /etc/pihole/gravity.db "DELETE FROM adlist_by_group"
	sudo sqlite3 /etc/pihole/gravity.db "DELETE FROM domainlist"
	sudo sqlite3 /etc/pihole/gravity.db "DELETE FROM domainlist_by_group"
	```
  - keep reading and configure the script then run `sudo pihole-updatelists` to finish up
  - (only when `UPDATE_GRAVITY=false`) run `pihole updateGravity`

## Install with Docker

Follow the [official instructions](https://hub.docker.com/r/pihole/pihole/) but use [`jacklul/pihole:latest`](https://hub.docker.com/r/jacklul/pihole) image instead of `pihole/pihole:latest` and add a volume for `/etc/pihole-updatelists/` directory.

Your `docker-compose.yml` file should look similar to this:

```yml
version: "3"

services:
  pihole:
    container_name: pihole
    image: jacklul/pihole:latest
    ports:
      - "53:53/tcp"
      - "53:53/udp"
      - "67:67/udp"
      - "80:80/tcp"
      - "443:443/tcp"
    environment:
      TZ: 'America/Chicago'
    volumes:
      - './etc-pihole/:/etc/pihole/'
      - './etc-dnsmasq.d/:/etc/dnsmasq.d/'
      - './etc-pihole-updatelists/:/etc/pihole-updatelists/'
    dns:
      - 127.0.0.1
      - 1.1.1.1
    cap_add:
      - NET_ADMIN
    restart: unless-stopped
```

If you already have existing `gravity.db` you should also check out [Migrating lists and domains](#migrating-lists-and-domains) section, keep in mind that you will have to adjust paths in the commands mentioned there.

## Configuration

Default configuration file is `/etc/pihole-updatelists.conf`.

```bash
sudo nano /etc/pihole-updatelists.conf
```

### Available variables

| Variable | Default | Description |
|----------|---------|-------------|
| ADLISTS_URL | " " | Remote list URL containing list of adlists to import |
| WHITELIST_URL | " " | Remote list URL containing exact domains to whitelist |
| REGEX_WHITELIST_URL | " " | Remote list URL containing regex rules for whitelisting |
| BLACKLIST_URL | " " | Remote list URL containing exact domains to blacklist <br>This is specifically for handcrafted lists only, do not use regular blocklists here! |
| REGEX_BLACKLIST_URL | " " | Remote list URL containing regex rules for blacklisting |
| COMMENT | "Managed by pihole-updatelists" | Comment string used to know which entries were created by the script <br>You can still add your own comments to individual entries as long you keep this string intact |
| GROUP_ID | 0 | Assign additional group to all inserted entries, to assign only the specified group (do not add to the default) make the number negative <br>`0` is the default group, you can view ID of the group in Pi-hole's web interface by hovering mouse cursor over group name field on the 'Group management' page |
| PERSISTENT_GROUP | false | Makes sure entries have the specified group assigned on each run <br>This does not prevent you from assigning more groups through the web interface but can remove entries from the default group if GROUP_ID is negative number <br>Do not enable this when you're running multiple different configs
| REQUIRE_COMMENT | true | Prevent touching entries not created by this script by comparing comment field <br>When `false` any user-created entry will be disabled |
| UPDATE_GRAVITY | true | Update gravity after lists are updated? (runs `pihole updateGravity`) <br>When `false` invokes lists reload instead <br>Set to `null` to do nothing |
| VACUUM_DATABASE | false | Vacuum database at the end? (runs `VACUUM` SQLite command) <br>Will cause additional writes to disk |
| VERBOSE | false | Show more information while the script is running |
| DEBUG | false | Show debug messages for troubleshooting purposes |
| DOWNLOAD_TIMEOUT | 60 | Maximum time in seconds one list download can take before giving up <br>You should increase this when downloads fail because of timeout | 
| IGNORE_DOWNLOAD_FAILURE | false | Ignore download failures when using multiple lists <br> This will cause entries from the lists that failed to download to be disabled |
| GRAVITY_DB | "/etc/pihole/gravity.db" | Path to `gravity.db` in case you need to change it |
| LOCK_FILE | "/var/lock/pihole-updatelists.lock" | Process lockfile to prevent multiple instances of the script from running <br>You shouldn't change it - unless `/var/lock` is unavailable |
| LOG_FILE | " " | Log console output to file <br>In most cases you don't have to set this as you can view full log in the system journal <br>Put `-` before path to overwrite file instead of appending to it |
| GIT_BRANCH | "master" | Branch to pull remote checksum and update from |

String values should be put between `" "`, otherwise weird things might happen.

You can also give paths to the local files instead of URLs, for example setting `WHITELIST_URL` to `/home/pi/whitelist.txt` will fetch this file from filesystem.

### Multiple configurations

You can specify alternative config file by passing the path to the script through `config` parameter: `pihole-updatelists --config=/home/pi/pihole-updatelists2.conf` - this combined with different `COMMENT` string can allow multiple script configurations for the same Pi-hole instance.

### Multiple list URLs

You can pass multiple URLs to the list variables by separating them with whitespace (space or new line):

```bash
ADLISTS_URL="https://v.firebog.net/hosts/lists.php?type=tick  https://raw.githubusercontent.com/you/adlists/master/my_adlists.txt"
```

If one of the lists fails to download nothing will be affected for that list type.

### Recommended lists

| List | URL | Description |
|----------|-------------|-------------|
| Adlist<br>(ADLISTS_URL) | https://v.firebog.net/hosts/lists.php?type=tick | https://firebog.net - safe lists only |
| Whitelist<br>(WHITELIST_URL) | https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt | https://github.com/anudeepND/whitelist - commonly whitelisted |
| Regex blacklist<br>(REGEX_BLACKLIST_URL) | https://raw.githubusercontent.com/mmotti/pihole-regex/master/regex.list | https://github.com/mmotti/pihole-regex - basic regex rules |

## Extra information

### Runtime options

These can be used when executing `pihole-updatelists`.

| Option | Description |
|----------|-------------|
| `--help, -h` | Show help message, which is simply this list |
| `--no-gravity, -n` | Force gravity update to be skipped |
| `--no-reload, -b` | Force lists reload to be skipped<br>Only if gravity update is disabled either by configuration (`UPDATE_GRAVITY=false`) or `--no-gravity` parameter |
| `--no-vacuum, -m` | Force database vacuuming to be skipped |
| `--verbose, -v` | Turn on verbose mode |
| `--debug, -d`  | Turn on debug mode |
| `--config=<file>` | Load alternative configuration file |
| `--git-branch=<branch>` | Select git branch to pull remote checksum and update from <br>Can only be used with `--update` and `--version` |
| `--update` | Update the script using selected git branch |
| `--version `| Show script checksum (and also if update is available) |

### Changing the schedule

By default, the script runs at random time (between 03:00 and 04:00) on Saturday, to change it you'll have to override [timer unit](https://www.freedesktop.org/software/systemd/man/systemd.timer.html) file:
 
```bash
sudo systemctl edit pihole-updatelists.timer
```
```
[Timer]
RandomizedDelaySec=5m
OnCalendar=
OnCalendar=Sat *-*-* 00:00:00
```

### Running custom commands before/after scheduled run

Override [service unit](https://www.freedesktop.org/software/systemd/man/systemd.service.html) file:

```bash
sudo systemctl edit pihole-updatelists.service
```
```
[Service]
Type=oneshot
ExecStartPre=echo "before"
ExecStartPost=echo "after"
```

### Changing comment value after running the script

```bash
sudo sqlite3 /etc/pihole/gravity.db "UPDATE adlist SET comment = 'NEWCOMMENT' WHERE comment LIKE '%Managed by pihole-updatelists%'"
sudo sqlite3 /etc/pihole/gravity.db "UPDATE domainlist SET comment = 'NEWCOMMENT' WHERE comment LIKE '%Managed by pihole-updatelists%'"
```

Replace `NEWCOMMENT` with your new desired comment value. This assumes `Managed by pihole-updatelists` is the old comment value, replace it with your old custom value when needed.

### Custom comments for entries

If you wish to add custom comments to entries you can use the following file syntax:

```
example-domain.com # your comment
```

Which will cause `example-domain.com` to have `comment` set to `your comment | Managed by pihole-updatelists`.

You can also add your comments directly through the Pi-hole's web interface by either appending or prepending the comment field for entries.

### Uninstalling

```bash
wget -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | sudo bash /dev/stdin uninstall
```
or remove files manually:
```bash
sudo rm -vf /usr/local/sbin/pihole-updatelists /etc/bash_completion.d/pihole-updatelists /etc/systemd/system/pihole-updatelists.service /etc/systemd/system/pihole-updatelists.timer /etc/cron.d/pihole-updatelists
```

## License

[MIT License](/LICENSE).
