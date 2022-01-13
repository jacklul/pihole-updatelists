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
SYSTEMD=`pidof systemd >/dev/null && echo "1" || echo "0"` # Is systemd available?
SYSTEMD_INSTALLED=`[ -f "/etc/systemd/system/pihole-updatelists.timer" ] && echo "1" || echo "0"` # Is systemd timer installed already?
DOCKER=`[ "$(cat /proc/1/cgroup | grep "docker")" == "" ] && echo "0" || echo "1"` # Is this a Docker installation?

if [ "$1" == "uninstall" ]; then	# Simply remove the files and reload systemd (if available)
	rm -v /usr/local/sbin/pihole-updatelists
	rm -v /etc/bash_completion.d/pihole-updatelists
	rm -vf /etc/cron.d/pihole-updatelists
	rm -vf /etc/systemd/system/pihole-updatelists.service
	rm -vf /etc/systemd/system/pihole-updatelists.timer
	
	if [ -f "/usr/local/sbin/pihole-updatelists.old" ]; then
		rm -v /etc/bash_completion.d/pihole-updatelists.old
	fi

	if [ "$SYSTEMD" == 1 ]; then
		reloadSystemd
	fi

	exit 0
elif [ "$1" == "docker" ]; then	# Force Docker install
	DOCKER=1
elif [ "$1" == "systemd" ]; then # Force systemd unit files installation
	SYSTEMD=1
elif [ "$1" == "crontab" ]; then # Force crontab installation
	SYSTEMD=0
elif [ "$1" != "" ]; then	# Install using different branch
	GIT_BRANCH=$1

	wget -q --spider "$REMOTE_URL/$GIT_BRANCH/install.sh"
	if [ $? -ne 0 ] ; then
		echo "Invalid branch: ${GIT_BRANCH}"
		exit 1
	fi
fi

# Do not install systemd unit files inside Docker container
if [ "$DOCKER" == 1 ]; then
	SYSTEMD=0
fi

# We require some stuff before continuing
command -v php >/dev/null 2>&1 || { echo "This script requires PHP-CLI to run, install it with 'sudo apt install php-cli'."; exit 1; }
[[ $(php -v | head -n 1 | cut -d " " -f 2 | cut -f1 -d".") < 7 ]] && { echo "Detected PHP version lower than 7.0, make sure php-cli package is up to date!"; exit 1; }

# Use local files when possible, otherwise install from remote repository
if \
	[ "$1" == "" ] && \
	[ -f "$SPATH/pihole-updatelists.php" ] && \
	[ -f "$SPATH/pihole-updatelists.conf" ] && \
	[ -f "$SPATH/pihole-updatelists.service" ] && \
	[ -f "$SPATH/pihole-updatelists.timer" ] && \
	[ -f "$SPATH/pihole-updatelists.bash" ] \
; then
	if [ -f "/usr/local/sbin/pihole-updatelists" ]; then
		if ! cmp -s "$SPATH/pihole-updatelists.php" "/usr/local/sbin/pihole-updatelists"; then
			echo "Backing up previous version..."
			cp -v /usr/local/sbin/pihole-updatelists /usr/local/sbin/pihole-updatelists.old && \
			chmod -v -x /usr/local/sbin/pihole-updatelists.old
		fi
	fi

	cp -v $SPATH/pihole-updatelists.php /usr/local/sbin/pihole-updatelists && \
	chmod -v +x /usr/local/sbin/pihole-updatelists
	
	if [ ! -f "/etc/pihole-updatelists.conf" ]; then
		cp -v $SPATH/pihole-updatelists.conf /etc/pihole-updatelists.conf
	fi

	if [ "$SYSTEMD" == 1 ]; then
		cp -v $SPATH/pihole-updatelists.service /etc/systemd/system
		cp -v $SPATH/pihole-updatelists.timer /etc/systemd/system
	fi

	cp -v $SPATH/pihole-updatelists.bash /etc/bash_completion.d/pihole-updatelists

	# Convert line endings when dos2unix command is available
	command -v dos2unix >/dev/null 2>&1 && dos2unix /usr/local/sbin/pihole-updatelists
elif [ "$REMOTE_URL" != "" ] && [ "$GIT_BRANCH" != "" ]; then
	if [ -f "/usr/local/sbin/pihole-updatelists" ]; then
		wget -nv -O /tmp/pihole-updatelists.php "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.php"

		if ! cmp -s "/tmp/pihole-updatelists.php" "/usr/local/sbin/pihole-updatelists"; then
			echo "Backing up previous version..."
			cp -v /usr/local/sbin/pihole-updatelists /usr/local/sbin/pihole-updatelists.old && \
			chmod -v -x /usr/local/sbin/pihole-updatelists.old
		fi

		mv -v /tmp/pihole-updatelists.php /usr/local/sbin/pihole-updatelists && \
		chmod -v +x /usr/local/sbin/pihole-updatelists
	else
		wget -nv -O /usr/local/sbin/pihole-updatelists "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.php" && \
		chmod -v +x /usr/local/sbin/pihole-updatelists
	fi

	if [ ! -f "/etc/pihole-updatelists.conf" ]; then
		wget -nv -O /etc/pihole-updatelists.conf "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		wget -nv -O /etc/systemd/system/pihole-updatelists.service "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.service"
		wget -nv -O /etc/systemd/system/pihole-updatelists.timer "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.timer"
	fi

	wget -nv -O /etc/bash_completion.d/pihole-updatelists "$REMOTE_URL/$GIT_BRANCH/pihole-updatelists.bash"
else
	echo "Missing required files for installation!"
	exit 1
fi

# Install schedule related files
if [ "$SYSTEMD" == 1 ]; then
	if [ -f "/etc/cron.d/pihole-updatelists" ]; then
		# Comment out the existing cron job
		sed -e 's/^#*/#/' -i /etc/cron.d/pihole-updatelists
	fi

	if [ "$SYSTEMD_INSTALLED" == 0 ]; then
		echo "Enabling and starting pihole-updatelists.timer..."
		systemctl enable pihole-updatelists.timer && systemctl start pihole-updatelists.timer
	else
		reloadSystemd
	fi
else
	if [ ! -f "/etc/cron.d/pihole-updatelists" ]; then
		echo "# Pi-hole's Lists Updater by Jack'lul
# https://github.com/jacklul/pihole-updatelists

#30 3 * * 6   root   /usr/local/sbin/pihole-updatelists
" > /etc/cron.d/pihole-updatelists
		sed -i "s/#30 /$((1 + RANDOM % 58)) /" /etc/cron.d/pihole-updatelists

		echo "Created crontab (/etc/cron.d/pihole-updatelists)"
	fi
fi

# Docker-related tasks
if [ "$DOCKER" == 1 ]; then
	# Create directory that will contain the configuration
	if [ ! -d "/etc/pihole-updatelists" ]; then
		mkdir -v /etc/pihole-updatelists
	fi

	# Create or update container startup script
	echo "#!/usr/bin/env bash
set -e

if [ \"\${PH_VERBOSE:-0}\" -gt 0 ]; then
    set -x
	SCRIPT_ARGS=\"--verbose --debug\"
fi

if [ ! -L \"/etc/pihole-updatelists.conf\" ]; then
	if [ ! -e \"/etc/pihole-updatelists/pihole-updatelists.conf\" ]; then
		mv -v /etc/pihole-updatelists.conf /etc/pihole-updatelists/pihole-updatelists.conf
	else
		rm -v /etc/pihole-updatelists.conf
	fi

	ln -sv /etc/pihole-updatelists/pihole-updatelists.conf /etc/pihole-updatelists.conf
fi

if [ ! -L \"/etc/cron.d/pihole-updatelists\" ]; then
	if [ ! -e \"/etc/pihole-updatelists/crontab\" ]; then
		mv -v /etc/cron.d/pihole-updatelists /etc/pihole-updatelists/crontab
	else
		rm -v /etc/cron.d/pihole-updatelists
	fi

	ln -sv /etc/pihole-updatelists/crontab /etc/cron.d/pihole-updatelists
fi

chown -v root:root /etc/pihole-updatelists/*
chmod -v 644 /etc/pihole-updatelists/*

if [[ ! -z \"\${SKIPGRAVITYONBOOT}\" ]]; then
	[ ! -e \"/etc/cron.d/updatelists-on-boot\" ] || rm -v /etc/cron.d/updatelists-on-boot

	echo \"Lists update skipped!\"
elif [ -e \"/etc/pihole/gravity.db\" ]; then
	echo '@reboot root /usr/bin/php /usr/local/sbin/pihole-updatelists --no-gravity --no-reload \${SCRIPT_ARGS} > /var/log/pihole-updatelists.log' > /etc/cron.d/updatelists-on-boot
	
	echo \"Modifying /etc/cron.d/gravity-on-boot to delay the gravity update for 60 seconds so that pihole-updatelists has a chance to run before it!\"
	sed -e 's/root PATH=/root sleep 60 ; PATH=/' -i /etc/cron.d/gravity-on-boot
else
	echo \"Gravity database not found, skipping lists update!\"
fi
" > /etc/cont-init.d/30-pihole-updatelists.sh

	echo "Installed container startup script (/etc/cont-init.d/30-pihole-updatelists.sh)"
fi
