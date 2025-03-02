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

# Install environment detection
SYSTEMD=$({ [ -n "$(pidof systemd)" ] || [ "$(readlink -f /sbin/init)" = "/usr/lib/systemd/systemd" ]; } && echo "1" || echo "0") # Is systemd available?
DOCKER=$({ [ -f /proc/1/cgroup ] || [ "$(grep "docker" /proc/1/cgroup 2> /dev/null)" == "" ]; } && echo "0" || echo "1") # Is this a Docker container?
ENTWARE=$([ -f /opt/etc/opkg.conf ] && echo "1" || echo "0") # Is this an Entware installation?

# Install paths
BIN_PATH=/usr/local/sbin
ETC_PATH=/etc
VAR_TMP_PATH=/var/tmp

# Map old Pi-hole versions to branches
declare -A OLD_VERSION_BRANCH_MAP=(
    [5]="pihole-v5"
    #[6]="pihole-v6"
)

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

RM_CMD="rm -vf"
MKDIR_CMD="mkdir -vp"
CP_CMD="cp -v"
MV_CMD="mv -v"
CHMOD_CMD="chmod -v"

# Overrides for Entware environment
if [ "$ENTWARE" == 1 ]; then
	if [ -z "$BASH_VERSION" ]; then
		echo "This script requires Bash shell to run."
		exit 1
	fi

	SYSTEMD=0

	# Override paths
    BIN_PATH=/opt/sbin
    ETC_PATH=/opt/etc
    VAR_TMP_PATH=/opt/tmp

	# No -v flags on most Busybox implementations!
	RM_CMD="rm -f"
	MKDIR_CMD="mkdir -p"
	CP_CMD="cp"
	MV_CMD="mv"
	CHMOD_CMD="chmod"
fi

if [ "$1" == "uninstall" ] || [ "$2" == "uninstall" ]; then	# Simply remove the files and reload systemd (if available)
	$RM_CMD "$BIN_PATH/pihole-updatelists"
	$RM_CMD "$ETC_PATH/bash_completion.d/pihole-updatelists"
	$RM_CMD "$ETC_PATH/cron.d/pihole-updatelists"
	$RM_CMD "$ETC_PATH/systemd/system/pihole-updatelists.service"
	$RM_CMD "$ETC_PATH/systemd/system/pihole-updatelists.timer"

	if [ -f "$VAR_TMP_PATH/pihole-updatelists.old" ]; then
		$RM_CMD "$VAR_TMP_PATH/pihole-updatelists.old"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		reloadSystemd
	fi

	exit 0
fi

# Some systems might use php-cli instead of php
PHP_CMD=php
command -v php-cli >/dev/null 2>&1 && PHP_CMD=php-cli

# We check some stuff before continuing
command -v $PHP_CMD >/dev/null 2>&1 || { echo "This script requires PHP CLI to run - install 'php-cli' package."; exit 1; }
[[ $($PHP_CMD -v | head -n 1 | cut -d " " -f 2 | cut -f1 -d".") -lt 7 ]] && { echo "Detected PHP version lower than 7.0, make sure php-cli package is up to date!"; exit 1; }
command -v pihole >/dev/null 2>&1 || { echo "'pihole' command not found, is the Pi-hole even installed?"; exit 1; }

PIHOLE_VERSION="$(pihole version | grep -oP "[vV]ersion is v\K[0-9.]" | head -n 1)"

if [ -z "$PIHOLE_VERSION" ]; then
	echo "Failed to detect Pi-hole version, you're continuing at your own risk."
    read -rp "Press Enter to continue..."
fi

if [ -n "$PIHOLE_VERSION" ] && [[ -n "${OLD_VERSION_BRANCH_MAP[$PIHOLE_VERSION]}" ]]; then
	NEW_BRANCH="${OLD_VERSION_BRANCH_MAP[$PIHOLE_VERSION]}"

    echo "You are running Pi-hole V$PIHOLE_VERSION which is not supported on this branch."
    echo "This script can automatically fetch and execute the correct install script from '$NEW_BRANCH' branch."

	#shellcheck disable=SC2162
    read -p "Do you want to proceed? (y/N): " response

    if [[ $response =~ ^[Yy](es)?$ ]]; then
        exec wget -O - "$REMOTE_URL/$NEW_BRANCH/install.sh" | bash -s "$NEW_BRANCH"
    fi

	exit 1
fi

if [ "$PIHOLE_VERSION" -ne 6 ]; then
    echo "Unsupported Pi-hole version (V$PIHOLE_VERSION) detected, you're continuing at your own risk."
    read -rp "Press Enter to continue..."
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
		$MKDIR_CMD "$BIN_PATH" && $CHMOD_CMD 0755 "$BIN_PATH"
	fi

	if [ -f "$BIN_PATH/pihole-updatelists" ]; then
		if ! cmp -s "$SPATH/pihole-updatelists.php" "$BIN_PATH/pihole-updatelists"; then
			echo "Backing up previous version..."
			$CP_CMD "$BIN_PATH/pihole-updatelists" "$VAR_TMP_PATH/pihole-updatelists.old" && \
			$CHMOD_CMD -x "$VAR_TMP_PATH/pihole-updatelists.old"
		fi
	fi

	$CP_CMD "$SPATH/pihole-updatelists.php" "$BIN_PATH/pihole-updatelists" && \
	$CHMOD_CMD +x "$BIN_PATH/pihole-updatelists"

	if [ ! -f "$ETC_PATH/pihole-updatelists.conf" ]; then
		$CP_CMD "$SPATH/pihole-updatelists.conf" "$ETC_PATH/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		$CP_CMD "$SPATH/pihole-updatelists.service" "$ETC_PATH/systemd/system"
		$CP_CMD "$SPATH/pihole-updatelists.timer" "$ETC_PATH/systemd/system"
	fi

	if [ ! -d "$ETC_PATH/bash_completion.d" ]; then
		$MKDIR_CMD "$ETC_PATH/bash_completion.d"
	fi

	$CP_CMD "$SPATH/pihole-updatelists.bash" "$ETC_PATH/bash_completion.d/pihole-updatelists"

	# Convert line endings when dos2unix command is available
	command -v dos2unix >/dev/null 2>&1 && dos2unix "$BIN_PATH/pihole-updatelists" "$ETC_PATH/bash_completion.d/pihole-updatelists"
elif [ "$REMOTE_URL" != "" ] && [ "$GIT_BRANCH" != "" ]; then
	if [ ! -d "$BIN_PATH" ]; then
		$MKDIR_CMD "$BIN_PATH" && $CHMOD_CMD 0755 "$BIN_PATH"
	fi

	if [ -f "$BIN_PATH/pihole-updatelists" ]; then
		wget -nv -O /tmp/pihole-updatelists.php "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.php"

		if ! cmp -s "/tmp/pihole-updatelists.php" "$BIN_PATH/pihole-updatelists"; then
			echo "Backing up previous version..."
			$CP_CMD "$BIN_PATH/pihole-updatelists" "$VAR_TMP_PATH/pihole-updatelists.old" && \
			$CHMOD_CMD -x "$VAR_TMP_PATH/pihole-updatelists.old"
		fi

		$MV_CMD /tmp/pihole-updatelists.php "$BIN_PATH/pihole-updatelists" && \
		$CHMOD_CMD +x "$BIN_PATH/pihole-updatelists"
	else
		wget -nv -O "$BIN_PATH/pihole-updatelists" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.php" && \
		$CHMOD_CMD +x "$BIN_PATH/pihole-updatelists"
	fi

	if [ ! -f "$ETC_PATH/pihole-updatelists.conf" ]; then
		wget -nv -O "$ETC_PATH/pihole-updatelists.conf" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		wget -nv -O "$ETC_PATH/systemd/system/pihole-updatelists.service" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.service"
		wget -nv -O "$ETC_PATH/systemd/system/pihole-updatelists.timer" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.timer"
	fi

	if [ ! -d "$ETC_PATH/bash_completion.d" ]; then
		$MKDIR_CMD "$ETC_PATH/bash_completion.d"
	fi

	wget -nv -O "$ETC_PATH/bash_completion.d/pihole-updatelists" "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.bash"
else
	echo "Missing required files for installation!"
	exit 1
fi

if [ -f "$BIN_PATH/pihole-updatelists.old" ]; then
	$RM_CMD "$BIN_PATH/pihole-updatelists.old"
fi

# Install schedule related files
if [ "$SYSTEMD" == 1 ]; then
	if [ -f "$ETC_PATH/cron.d/pihole-updatelists" ]; then
		# Comment out the existing cron job
		sed "s/^#*/#/" -i "$ETC_PATH/cron.d/pihole-updatelists"
	fi

	if ! systemctl -q is-active pihole-updatelists.timer; then
		echo "Enabling and starting pihole-updatelists.timer..."
		systemctl enable pihole-updatelists.timer && systemctl start pihole-updatelists.timer
	else
		reloadSystemd
	fi
elif [ "$DOCKER" == 0 ]; then
	if [ -d "$ETC_PATH/cron.d" ]; then
		if [ ! -f "$ETC_PATH/cron.d/pihole-updatelists" ]; then
			echo "# Pi-hole's Lists Updater by Jack'lul
# https://github.com/jacklul/pihole-updatelists

#30 3 * * 6   root   $BIN_PATH/pihole-updatelists
" > "$ETC_PATH/cron.d/pihole-updatelists"
			sed "s/#30 /$((1 + RANDOM % 58)) /" -i "$ETC_PATH/cron.d/pihole-updatelists"

			echo "Created crontab ($ETC_PATH/cron.d/pihole-updatelists)"
		fi
	else
		echo "Missing $ETC_PATH/cron.d directory - crontab will not be installed!"
	fi
fi

# Docker-related tasks
if [ "$DOCKER" == 1 ]; then
    [ ! -f /usr/bin/php ] && { echo "Missing /usr/bin/php binary - was the 'php' package installed?"; exit 1; }
	[ ! -f /usr/bin/start.sh ] && { echo "Missing /usr/bin/start.sh script - not a Pi-hole container?"; exit 1; }

	if [ -f "$SPATH/docker.sh" ]; then
		cp -v "$SPATH/docker.sh" /usr/bin/pihole-updatelists.sh
	elif [ "$REMOTE_URL" != "" ]; then
		wget -nv -O /usr/bin/pihole-updatelists.sh "$REMOTE_URL/$GIT_BRANCH/docker.sh"
	else
		echo "Missing required file (docker.sh) for installation!"
		exit 1
	fi

	chmod -v +x /usr/bin/pihole-updatelists.sh
	mkdir -vp /etc/pihole-updatelists

	if ! grep -q "pihole-updatelists" /crontab.txt; then
		# Use the same schedule string to have it randomized on each launch
		CRONTAB=$(sed -n '/pihole updateGravity/s/\(.*\) PATH=.*/\1/p' /crontab.txt)

		#shellcheck disable=SC2140
		echo "$CRONTAB PATH="\$PATH:/usr/sbin:/usr/local/bin/" pihole-updatelists.sh" >> /crontab.txt
		echo "Created crontab entry in /crontab.txt"
	fi

	if ! grep -q "^#.*pihole updateGravity" crontab.txt; then
		sed -e '/pihole updateGravity/ s/^#*/#/' -i /crontab.txt
		echo "Disabled default gravity update entry in /crontab.txt"
	fi

	echo "Modifying /usr/bin/start.sh script..."
	sed '/^\s\+ftl_config/a pihole-updatelists.sh config' -i /usr/bin/start.sh
	sed '/^\s\+start_cron/i pihole-updatelists.sh cron' -i /usr/bin/start.sh

	if
		! grep -q "pihole-updatelists.sh config" /usr/bin/start.sh ||
		! grep -q "pihole-updatelists.sh cron" /usr/bin/start.sh
	then
		echo "Modification of /usr/bin/start.sh script failed!"
		exit 1
	fi

	echo "Modifying /usr/bin/bash_functions.sh script..."
	sed '/^\s\+pihole -g/a pihole-updatelists.sh run' -i /usr/bin/bash_functions.sh

	if ! grep -q "pihole-updatelists.sh run" /usr/bin/bash_functions.sh; then
		echo "Modification of /usr/bin/bash_functions.sh script failed!"
		exit 1
	fi
fi
