# Update Pi-hole's lists from remote sources

When using remote lists like [this](https://v.firebog.net/hosts/lists.php?type=tick) or [this](https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt) it's a hassle to manually check for changes and update - this script will do that for you!

User-created entries will not be touched and those removed from the remote list will be disabled instead.

> [!WARNING]
> If you're not using remote lists like the ones mentioned above then this script will be useless to you - Pi-hole already updates the lists weekly automatically.

## Requirements

- **Pi-hole V5** installed (fresh install preferred)
- **php-cli >=7.0** and **a few extensions** (`sudo apt-get install php-cli php-sqlite3 php-intl php-curl`)
- **systemd** is optional but recommended

## Install

> [!NOTE]
> Docker users - [look below](#install-with-docker).

This command will install this script to `/usr/local/sbin`:

```bash
wget -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | sudo bash
```

_Alternatively you can clone this repo and `sudo bash install.sh`._

If **systemd** is available this script will also add service and timer unit files to the system, otherwise a crontab entry in `/etc/cron.d/pihole-updatelists` will be created.

> [!NOTE]
> If for some reason the install script does not copy service and timer files while your distro has systemd scheduler available you can force the installation by passing `systemd` as a parameter to the install script - modifying the install command above with `sudo bash -s systemd` instead.

Note that in most cases you will be able to execute this script globally as `pihole-updatelists` command but some will require you to add `/usr/local/sbin` to `$PATH` or execute it via `/usr/local/sbin/pihole-updatelists`.

> [!IMPORTANT]
>__This script does nothing by default (except running `pihole updateGravity`), - you have to [configure it](#configuration).__

> [!TIP]
> To quickly update the script run `sudo pihole-updatelists --update` which will check for script differences and re-run the install script when needed.

### Disable default gravity update schedule

> [!TIP]
> If you don't plan on updating adlists or want to keep Pi-hole's gravity update schedule you should skip this section and set `UPDATE_GRAVITY=false` in the configuration file.

You should disable entry with `pihole updateGravity` command in `/etc/cron.d/pihole` as this script already runs it:

```bash
sudo nano /etc/cron.d/pihole
```
Put a `#` before this line (numbers might be different):
```
#49 4   * * 7   root    PATH="$PATH:/usr/local/bin/" pihole updateGravity >/var/log/pihole_updateGravity.log || cat /var/log/pihole_updateGravity.log
```
Alternatively, the following `sed` command will disable the same entry:
```bash
sudo sed -e '/pihole updateGravity/ s/^#*/#/' -i /etc/cron.d/pihole
```

**You might have to do this manually after each Pi-hole update.**

> [!TIP]
> You can override `pihole-FTL.service` to disable the cron entry automatically after each update:
> 
> ```bash
> sudo systemctl edit pihole-FTL.service
> ```
> ```
> [Service]
> ExecStartPre=-/bin/sh -c "[ -w /etc/cron.d/pihole ] && /bin/sed -e '/pihole > updateGravity/ s/^#*/#/' -i /etc/cron.d/pihole"
> ```

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
  - run the following command:
	```bash
	sudo pihole -g -r recreate
	```
  - keep reading and configure the script then run `sudo pihole-updatelists` to finish up
  - (only when `UPDATE_GRAVITY=false`) run `pihole updateGravity`

## Install with Docker

Follow the [official instructions](https://hub.docker.com/r/pihole/pihole/) but use `jacklul/pihole:latest` image instead, pass [configuration variables](#configuration) as environment variables in `docker-compose.yml`.

If you need to pull a specific version of Pi-hole image you have no other choice but to use [custom Dockerfile](#using-official-image).

### Using custom image

Use [`jacklul/pihole:latest`](https://hub.docker.com/r/jacklul/pihole) image instead of `pihole/pihole:latest`. [Version-specific tags](https://hub.docker.com/r/jacklul/pihole/tags) are also available but keep in mind they will contain version of the script that was available at the time of that particular version.

### Using official image

If you don't want to use my image you can write custom `Dockerfile`:

```
FROM pihole/pihole:latest

RUN apt-get update && apt-get install -Vy wget php-cli php-sqlite3 php-intl php-curl

RUN wget -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | bash -s docker
```

Then build your image locally and use that image in your `docker-composer.yml` or launch command line.
You will have to update your local image manually each time update is released.

### Container Configuration

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
    environment:
      TZ: 'America/Chicago'
      ADLISTS_URL: 'https://v.firebog.net/hosts/lists.php?type=tick'
      WHITELIST_URL: 'https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt'
      #REGEX_WHITELIST_URL: ''
      #BLACKLIST_URL: ''
      REGEX_BLACKLIST_URL: 'https://raw.githubusercontent.com/mmotti/pihole-regex/master/regex.list'
    volumes:
      - './etc-pihole/:/etc/pihole/'
      - './etc-dnsmasq.d/:/etc/dnsmasq.d/'
      # If you need advanced configuration create a mount to access the config file:
      #- './etc-pihole-updatelists/:/etc/pihole-updatelists/'
    cap_add:
      - NET_ADMIN
    restart: unless-stopped
```
_(for more up to date `docker-compose.yml` see [pi-hole/docker-pi-hole](https://github.com/pi-hole/docker-pi-hole/#quick-start))_

If you already have existing `gravity.db` you should also check out [Migrating lists and domains](#migrating-lists-and-domains) section, keep in mind that you will have to adjust paths in the commands mentioned there.

Docker start script uses these extra environment variables:

- `PHUL_DNSCHECK_DOMAIN` - the domain to `nslookup` to check whenever DNS resolution is available (`pi.hole` by default)
- `PHUL_DNSCHECK_TIMELIMIT` - maximum time to wait for the DNS resolution to become available (`300` seconds by default)
- `PHUL_CRONTAB` - to override crontab schedule for gravity update, needs valid crontab string (`30 3 * * 6` for example)

## Configuration

Default configuration file is `/etc/pihole-updatelists.conf`.

```bash
sudo nano /etc/pihole-updatelists.conf
```

### Available variables

| Variable | Default | Description |
|----------|---------|-------------|
| ADLISTS_URL | " " | Remote list URL containing list of adlists to import <br>**URLs to single adlists are supported but it might be better if you add them manually** |
| WHITELIST_URL | " " | Remote list URL containing exact domains to whitelist |
| REGEX_WHITELIST_URL | " " | Remote list URL containing regex rules for whitelisting |
| BLACKLIST_URL | " " | Remote list URL containing exact domains to blacklist <br>**This is specifically for handcrafted lists only, do not use regular blocklists here!** |
| REGEX_BLACKLIST_URL | " " | Remote list URL containing regex rules for blacklisting |
| COMMENT | "Managed by pihole-updatelists" | Comment string used to know which entries were created by the script <br>**You can still add your own comments to individual entries as long you keep this string intact** |
| GROUP_ID | 0 | Assign additional group to all inserted entries, to assign only the specified group (do not add to the default) make the number negative <br>`0` is the default group, you can view ID of the group in Pi-hole's web interface by hovering mouse cursor over group name field on the 'Group management' page <br>**Multiple groups are not supported** |
| PERSISTENT_GROUP | true | Makes sure entries have the specified group assigned on each script run <br>This does not prevent you from assigning more groups through the web interface but can remove entries from the default group if GROUP_ID is a negative number <br>**When disabled but an entry has no groups assigned and is about to be enabled then it will be re-added to the groups it's supposed to be in** <br>**WARNING: This option might be buggy when running multiple different configurations with same lists** |
| REQUIRE_COMMENT | true | Prevent touching entries not created by this script by comparing comment field <br>When `false` any user-created entry will be disabled, only those created by the script will be active |
| MIGRATION_MODE | 1 | Decides how to migrate disabled entries from another config sections <br>1 - replace comment field <br>2 - append to comment field <br>0 - disables migration, entry will be ignored
| GROUP_EXCLUSIVE | false | Causes defined group in `GROUP_ID` to contain one defined list exclusively - only entries from the last list inserted will be enabled <br>**This option is experimental**
| UPDATE_GRAVITY | true | Update gravity after lists are updated? (runs `pihole updateGravity`) <br>When `false` invokes lists reload instead <br>Set to `null` to do nothing |
| VERBOSE | false | Show more information while the script is running |
| DEBUG | false | Show debug messages for troubleshooting purposes <br>**If you're having issues - this might help tracking it down** |
| DOWNLOAD_TIMEOUT | 60 | Maximum time in seconds one list download can take before giving up <br>You should increase this when downloads fail because of timeout |
| IGNORE_DOWNLOAD_FAILURE | false | Ignore download failures when using multiple lists <br> **This will cause entries from the lists that failed to download to be disabled** |
| GRAVITY_DB | "/etc/pihole/gravity.db" | Path to `gravity.db` in case you need to change it |
| LOCK_FILE | "/var/lock/pihole-updatelists.lock" | Process lockfile to prevent multiple instances of the script from running <br>**You shouldn't change it - unless `/var/lock` is unavailable** |
| LOG_FILE | " " | Log console output to file <br>In most cases you don't have to set this as you can view full log in the system journal <br>Put `-` before path to overwrite file instead of appending to it |
| PIHOLE_CMD | "/usr/local/bin/pihole" | Path to `pihole` script, <br>Change this only if it isn't in the default location |
| GIT_BRANCH | "master" | Branch to pull remote checksum and update from |

> [!NOTE]
> String values should be put between `" "`, otherwise weird things might happen.

> [!TIP]
> You can also give paths to the local files instead of URLs, for example setting `WHITELIST_URL` to `/home/pi/whitelist.txt` will fetch this file from filesystem.

### Environment variables

It is also possible to load configuration variables from the environment by using `--env` parameter - this will overwrite values in default section of the config file.

> [!IMPORTANT]
> Some variables will have to be prefixed with `PHUL_` for compatibility:
> ```
> CONFIG_FILE, GRAVITY_DB, LOCK_FILE, PIHOLE_CMD, LOG_FILE, VERBOSE, DEBUG, > GIT_BRANCH
> ```

### Multiple configurations

You can specify alternative config file by passing the path to the script through `config` parameter: `pihole-updatelists --config=/home/pi/pihole-updatelists2.conf` - this combined with different `COMMENT` string can allow multiple script configurations for the same Pi-hole instance.

**A more advanced way is to use sections in the configuration file:**

> [!WARNING]
> This method can sometimes be buggy or have weird behaviors when using lists that have shared entries!

```
(bottom of the file)

[GroupA_adlists]
WHITELIST_URL="https://raw.githubusercontent.com/you/adlists/master/my_whitelist1.txt"
GROUP_ID=-1
COMMENT="pihole-updatelists - whitelist1"

[GroupB_adlists]
WHITELIST_URL="https://raw.githubusercontent.com/you/adlists/master/my_whitelist2.txt"
GROUP_ID=-2
COMMENT="pihole-updatelists - whitelist2"
```

Configurations where one of the lists contains entries from the other are not officially supported but may work:

```
; When one of the lists contains entries from the other
; it's best to have it defined after the other one

; Group with ID=1 will use 'tick' list of adlists
[GroupA_adlists]
ADLISTS_URL="https://v.firebog.net/hosts/lists.php?type=tick"
GROUP_ID=-1
COMMENT="pihole-updatelists - firebog (tick)"

; Group with ID=2 will use 'nocross' list of adlists
[GroupB_adlists]
ADLISTS_URL="https://v.firebog.net/hosts/lists.php?type=nocross"
GROUP_ID=-2
COMMENT="pihole-updatelists - firebog (nocross)"
```

> [!IMPORTANT]
> You will want to have a different `COMMENT` value in each section, they have to be unique and one must not match the other!

Main configuration (the one without section header) is processed first, then the sections in the order of their appearance.

> [!IMPORTANT]
> You can only use selected variables in sections:
> ```
> ADLISTS_URL, WHITELIST_URL, REGEX_WHITELIST_URL, BLACKLIST_URL, > REGEX_BLACKLIST_URL, COMMENT, GROUP_ID, PERSISTENT_GROUP, GROUP_EXCLUSIVE, > IGNORE_DOWNLOAD_FAILURE
> ```

### Multiple list URLs

You can pass multiple URLs to the list variables by separating them with whitespace (space or new line):

```bash
ADLISTS_URL="https://v.firebog.net/hosts/lists.php?type=tick  https://raw.githubusercontent.com/you/adlists/master/my_adlists.txt"
```

If one of the lists fails to download nothing will be affected for that list type.

### Recommended lists

| List | URL/Variable value | Description |
|----------|-------------|-------------|
| Adlist<br>(ADLISTS_URL) | https://v.firebog.net/hosts/lists.php?type=tick | https://firebog.net - safe lists only |
| Whitelist<br>(WHITELIST_URL) | https://raw.githubusercontent.com/anudeepND/whitelist/master/domains/whitelist.txt | https://github.com/anudeepND/whitelist - commonly whitelisted |
| Regex blacklist<br>(REGEX_BLACKLIST_URL) | https://raw.githubusercontent.com/mmotti/pihole-regex/master/regex.list | https://github.com/mmotti/pihole-regex - basic regex rules |

Please note that [mmotti/pihole-regex](https://github.com/mmotti/pihole-regex) list can sometimes block domains that should not be blocked - any false positives should be [reported to the repository](https://github.com/mmotti/pihole-regex/issues) to be included in the [whitelist](https://github.com/mmotti/pihole-regex/blob/master/whitelist.list) (in that case you might consider adding that list to the `WHITELIST_URL` too).

## Extra information

### Runtime options

These can be used when executing `pihole-updatelists`.

| Option | Description |
|----------|-------------|
| `--help, -h` | Show help message, which is simply this list |
| `--no-gravity, -n` | Force gravity update to be skipped |
| `--no-reload, -b` | Force lists reload to be skipped<br>Only if gravity update is disabled either by configuration (`UPDATE_GRAVITY=false`) or `--no-gravity` parameter |
| `--verbose, -v` | Turn on verbose mode |
| `--debug, -d`  | Turn on debug mode |
| `--config=<file>` | Load alternative configuration file |
| `--env, -e` | Load configuration from environment variables |
| `--git-branch=<branch>` | Select git branch to pull remote checksum and update from <br>Can only be used with `--update` and `--version` |
| `--update` | Update the script using selected git branch |
| `--rollback` | Rollback script version to previous |
| `--force` | Force update without checking for newest version |
| `--yes, -y` | Automatically reply YES to all questions |
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

If systemd is not available you just modify the crontab entry in `/etc/cron.d/pihole-updatelists`:

```bash
14 6 * * 6   root   /usr/local/sbin/pihole-updatelists
```

When using Docker - either set `PHUL_CRONTAB` environment variable to desired crontab string or copy `/etc/cron.d/pihole-updatelists` into `/etc/pihole-updatelists/pihole-updatelists.cron` then modify it and restart the container.

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

If systemd is not available you just modify the crontab entry in `/etc/cron.d/pihole-updatelists`:

```bash
30 3 * * 6   root   /home/pi/before.sh && /usr/local/sbin/pihole-updatelists && /home/pi/after.sh
```

> [!TIP]
> You can use `;` instead of `&&` if you don't want the execution to stop on previous command failure.

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
wget -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | sudo bash -s uninstall
```

or remove files manually:

```bash
sudo rm -vf /usr/local/sbin/pihole-updatelists /etc/bash_completion.d/pihole-updatelists /etc/systemd/system/pihole-updatelists.service /etc/systemd/system/pihole-updatelists.timer /etc/cron.d/pihole-updatelists
```

## License

[MIT License](/LICENSE).
