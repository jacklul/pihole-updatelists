#!/usr/bin/env bash
set -e

# Try re-running with sudo
[ "$UID" -eq 0 ] || exec sudo bash "$0" "$@"

# Required to do when unit files are changed or removed
function reloadSystemd() {
	echo "Reloading systemd manager configuration..." 
	systemctl daemon-reload
}

SPATH=$(dirname "$0") # Path to the script
REMOTE_URL=https://raw.githubusercontent.com/jacklul/pihole-updatelists # Remote URL that serves raw files from the repository
GIT_BRANCH=master # Git branch to use, user can specify custom branch as first argument

# Install environments detection
SYSTEMD=$(pidof systemd >/dev/null && echo "1" || echo "0") # Is systemd available?
SYSTEMD_INSTALLED=$([ -f "/etc/systemd/system/pihole-updatelists.timer" ] && echo "1" || echo "0") # Is systemd timer installed already?
DOCKER=$([ "$(grep "docker" < /proc/1/cgroup)" == "" ] && echo "0" || echo "1") # Is this a Docker installation?
ENTWARE=$([ -f /opt/etc/opkg.conf ] && echo "1" || echo "0") # Entware is detected?

# Install paths
BIN_PATH=/usr/local/sbin
ETC_PATH=/etc
VAR_TMP_PATH=/var/tmp

if [ "$1" == "docker" ]; then # Force Docker install
	DOCKER=1
elif [ "$1" == "entware" ]; then # Force Entware install
	ENTWARE=1
elif [ "$1" == "systemd" ]; then # Force systemd unit files installation
	SYSTEMD=1
elif [ "$1" == "crontab" ]; then # Force crontab installation
	SYSTEMD=0
elif [ "$1" != "" ]; then # Install using different branch
	GIT_BRANCH=$1

	if ! wget -q --spider "$REMOTE_URL/$GIT_BRANCH/install.sh" ; then
		echo "Invalid branch: ${GIT_BRANCH}"
		exit 1
	fi
fi

# Do not install systemd unit files inside Docker container
if [ "$DOCKER" == 1 ]; then
	SYSTEMD=0
fi

# Overrides for Entware environment
if [ "$ENTWARE" == 1 ]; then
	SYSTEMD=0
    BIN_PATH=/opt/sbin
    ETC_PATH=/opt/etc
    VAR_TMP_PATH=/opt/tmp
fi

if [ "$1" == "uninstall" ] || [ "$2" == "uninstall" ]; then	# Simply remove the files and reload systemd (if available)
	rm -vf "$BIN_PATH/pihole-updatelists"
	rm -vf "$ETC_PATH/bash_completion.d/pihole-updatelists"
	rm -vf "$ETC_PATH/cron.d/pihole-updatelists"
	rm -vf "$ETC_PATH/systemd/system/pihole-updatelists.service"
	rm -vf "$ETC_PATH/systemd/system/pihole-updatelists.timer"

	if [ -f "$VAR_TMP_PATH/pihole-updatelists.old" ]; then
		rm -vf "$VAR_TMP_PATH/pihole-updatelists.old"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		reloadSystemd
	fi

	exit 0
fi

# We check some stuff before continuing
command -v php >/dev/null 2>&1 || { echo "This script requires PHP CLI to run - install 'php-cli' package."; exit 1; }
[[ $(php -v | head -n 1 | cut -d " " -f 2 | cut -f1 -d".") -lt 7 ]] && { echo "Detected PHP version lower than 7.0, make sure php-cli package is up to date!"; exit 1; }
command -v pihole >/dev/null 2>&1 || { echo "'pihole' command not found, is the Pi-hole even installed?"; exit 1; }

PIHOLE_VERSION="$(pihole version)"
if echo "$PIHOLE_VERSION" | grep -q "version is v5"; then
    PIHOLE_V5=1
fi

if [ "$PIHOLE_V5" != 1 ]; then
    echo "Unsupported Pi-hole version detected, expected V5."
    exit 1
fi

# Use local files when possible, otherwise install from remote repository
if \
	[ "$GIT_BRANCH" == "master" ] && \
	[ -f "$SPATH/pihole-updatelists.php" ] && \
	[ -f "$SPATH/pihole-updatelists.conf" ] && \
	[ -f "$SPATH/pihole-updatelists.service" ] && \
	[ -f "$SPATH/pihole-updatelists.timer" ] && \
	[ -f "$SPATH/pihole-updatelists.bash" ] \
; then
	if [ ! -d "$BIN_PATH" ]; then
		mkdir -vp "$BIN_PATH" && chmod -v 0755 "$BIN_PATH"
	fi

	if [ -f "$BIN_PATH/pihole-updatelists" ]; then
		if ! cmp -s "$SPATH/pihole-updatelists.php" "$BIN_PATH/pihole-updatelists"; then
			echo "Backing up previous version..."
			cp -v "$BIN_PATH/pihole-updatelists" "$VAR_TMP_PATH/pihole-updatelists.old" && \
			chmod -v -x "$VAR_TMP_PATH/pihole-updatelists.old"
		fi
	fi

	cp -v "$SPATH/pihole-updatelists.php" "$BIN_PATH/pihole-updatelists" && \
	chmod -v +x "$BIN_PATH/pihole-updatelists"

	if [ ! -f "$ETC_PATH/pihole-updatelists.conf" ]; then
		cp -v "$SPATH/pihole-updatelists.conf" "$ETC_PATH/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		cp -v "$SPATH/pihole-updatelists.service" "$ETC_PATH/systemd/system"
		cp -v "$SPATH/pihole-updatelists.timer" "$ETC_PATH/systemd/system"
	fi

	if [ ! -d "$ETC_PATH/bash_completion.d" ]; then
		mkdir -vp "$ETC_PATH/bash_completion.d"
	fi

	cp -v "$SPATH/pihole-updatelists.bash" "$ETC_PATH/bash_completion.d/pihole-updatelists"

	# Convert line endings when dos2unix command is available
	command -v dos2unix >/dev/null 2>&1 && dos2unix "$BIN_PATH/pihole-updatelists" "$ETC_PATH/bash_completion.d/pihole-updatelists"
elif [ "$REMOTE_URL" != "" ] && [ "$GIT_BRANCH" != "" ]; then
	if [ ! -d "$BIN_PATH" ]; then
		mkdir -vp "$BIN_PATH" && chmod -v 0755 "$BIN_PATH"
	fi

	if [ -f "$BIN_PATH/pihole-updatelists" ]; then
		wget -nv -O /tmp/pihole-updatelists.php "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.php"

		if ! cmp -s "/tmp/pihole-updatelists.php" "$BIN_PATH/pihole-updatelists"; then
			echo "Backing up previous version..."
			cp -v "$BIN_PATH/pihole-updatelists" "$VAR_TMP_PATH/pihole-updatelists.old" && \
			chmod -v -x "$VAR_TMP_PATH/pihole-updatelists.old"
		fi

		mv -v /tmp/pihole-updatelists.php "$BIN_PATH/pihole-updatelists" && \
		chmod -v +x "$BIN_PATH/pihole-updatelists"
	else
		wget -nv -O "$BIN_PATH/pihole-updatelists" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.php" && \
		chmod -v +x "$BIN_PATH/pihole-updatelists"
	fi

	if [ ! -f "$ETC_PATH/pihole-updatelists.conf" ]; then
		wget -nv -O "$ETC_PATH/pihole-updatelists.conf" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		wget -nv -O "$ETC_PATH/systemd/system/pihole-updatelists.service" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.service"
		wget -nv -O "$ETC_PATH/systemd/system/pihole-updatelists.timer" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.timer"
	fi

	if [ ! -d "$ETC_PATH/bash_completion.d" ]; then
		mkdir -vp "$ETC_PATH/bash_completion.d"
	fi

	wget -nv -O "$ETC_PATH/bash_completion.d/pihole-updatelists" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.bash"
else
	echo "Missing required files for installation!"
	exit 1
fi

if [ -f "$BIN_PATH/pihole-updatelists.old" ]; then
	rm -v "$BIN_PATH/pihole-updatelists.old"
fi

# Install schedule related files
if [ "$SYSTEMD" == 1 ]; then
	if [ -f "$ETC_PATH/cron.d/pihole-updatelists" ]; then
		# Comment out the existing cron job
		sed "s/^#*/#/" -i "$ETC_PATH/cron.d/pihole-updatelists"
	fi

	if [ "$SYSTEMD_INSTALLED" == 0 ]; then
		echo "Enabling and starting pihole-updatelists.timer..."
		systemctl enable pihole-updatelists.timer && systemctl start pihole-updatelists.timer
	else
		reloadSystemd
	fi
else
	if [ ! -f "$ETC_PATH/cron.d/pihole-updatelists" ]; then
		echo "# Pi-hole's Lists Updater by Jack'lul
# https://github.com/jacklul/pihole-updatelists

#30 3 * * 6   root   $BIN_PATH/pihole-updatelists
" > "$ETC_PATH/cron.d/pihole-updatelists"
		sed "s/#30 /$((1 + RANDOM % 58)) /" -i "$ETC_PATH/cron.d/pihole-updatelists"

		echo "Created crontab ($ETC_PATH/cron.d/pihole-updatelists)"
	fi
fi

# Docker-related tasks
if [ "$DOCKER" == 1 ]; then
	[ ! -d "/etc/s6-overlay/s6-rc.d/_postFTL" ] && { echo "Missing /etc/s6-overlay/s6-rc.d/_postFTL directory!"; exit 1; }
	[ ! -f "/usr/local/bin/_postFTL.sh" ] && { echo "Missing /usr/local/bin/_postFTL.sh file!"; exit 1; }

	mkdir -v /etc/pihole-updatelists

	if [ -f "$SPATH/docker.sh" ]; then
		cp -v "$SPATH/docker.sh" /usr/local/bin/_updatelists.sh
	elif [ "$REMOTE_URL" != "" ]; then
		wget -nv -O /usr/local/bin/_updatelists.sh "$REMOTE_URL/$GIT_BRANCH/docker.sh"
	else
		echo "Missing required file (docker.sh) for installation!"
	fi

	chmod -v +x /usr/local/bin/_updatelists.sh

	echo "#!/command/execlineb" > /etc/s6-overlay/s6-rc.d/_postFTL/up
	echo "background { bash -ec \"/usr/local/bin/_updatelists.sh && /usr/local/bin/_postFTL.sh\" }" >> /etc/s6-overlay/s6-rc.d/_postFTL/up
	echo "Modified /etc/s6-overlay/s6-rc.d/_postFTL/up to launch pihole-updatelists first!"

	echo "alias pihole-updatelists='/usr/local/sbin/pihole-updatelists --config=/etc/pihole-updatelists/pihole-updatelists.conf --env'" >> /root/.bashrc
	echo "Created alias for pihole-updatelists command in /root/.bashrc"
fi
