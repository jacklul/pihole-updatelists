#!/bin/bash

# Try re-running with sudo
if [[ $(/usr/bin/id -u) -ne 0 ]]; then
	exec sudo -- "$0" "$@"
	exit
fi

# Required to do when unit files are changed or removed
function reloadSystemd() {
	echo "Reloading systemd manager configuration..." 
	systemctl daemon-reload
}

SPATH=$(dirname $0) # Path to the script
REMOTE_URL=https://raw.githubusercontent.com/jacklul/pihole-updatelists # Remote URL that serves raw files from the repository
GIT_BRANCH=master # Git branch to use, user can specify custom branch as first argument
SYSTEMD=`pidof systemd >/dev/null && echo "1" || echo "0"` # Is systemd available?
SYSTEMD_INSTALLED=`[ -f "/etc/systemd/system/pihole-updatelists.timer" ] && echo "1" || echo "0"` # Is systemd timer installed already?
DOCKER_INSTALL=0

if [ "$1" == "uninstall" ]; then	# Simply remove the files and reload systemd (if available)
	rm -v /usr/local/sbin/pihole-updatelists
	rm -v /etc/bash_completion.d/pihole-updatelists
	
	if [ "$SYSTEMD" == 1 ]; then
		rm -v /etc/systemd/system/pihole-updatelists.service
		rm -v /etc/systemd/system/pihole-updatelists.timer
		reloadSystemd
	else
		rm -v /etc/cron.d/pihole-updatelists
	fi

	exit 0
elif [ "$1" == "DOCKER" ]; then	# Docker install
	echo "Installing in a Docker environment"
	DOCKER_INSTALL=1
	shift # remove this argument
elif [ "$1" != "" ]; then	# Install using different branch
	GIT_BRANCH=$1

	wget -q --spider "$REMOTE_URL/$GIT_BRANCH/install.sh"
	if [ $? -ne 0 ] ; then
		echo "Invalid branch: ${GIT_BRANCH}"
		exit 1
	fi
fi

# We require some stuff before continuing
command -v php >/dev/null 2>&1 || { echo "This script requires PHP-CLI to run, install it with 'sudo apt install php-cli'."; exit 1; }
[[ $(php -v | head -n 1 | cut -d " " -f 2 | cut -f1 -d".") < 7 ]] && { echo "Detected PHP version lower than 7.0, make sure php-cli package is up to date!"; exit 1; }

# Stop on first error
set -e

# Use local files when possible, otherwise install from remote repository
if \
	[ "$1" == "" ] && \
	[ -f "$SPATH/pihole-updatelists.php" ] && \
	[ -f "$SPATH/pihole-updatelists.conf" ] && \
	[ -f "$SPATH/pihole-updatelists.service" ] && \
	[ -f "$SPATH/pihole-updatelists.timer" ] && \
	[ -f "$SPATH/pihole-updatelists.bash" ] && \
	([ "$DOCKER_INSTALL" == 0 ] || ([ "$DOCKER_INSTALL" == 1 ] && [ -f "$SPATH/60-pihole-updatelists.sh" ]))  \
; then
	cp -v $SPATH/pihole-updatelists.php /usr/local/sbin/pihole-updatelists && \
	chmod -v +x /usr/local/sbin/pihole-updatelists
	
	if [ ! -d "/etc/pihole-updatelists" ]; then
		mkdir /etc/pihole-updatelists
	fi
	
	if [ ! -f "/etc/pihole-updatelists/pihole-updatelists.conf" ]; then
		cp -v $SPATH/pihole-updatelists.conf /etc/pihole-updatelists/pihole-updatelists.conf
	fi

	if [ "$SYSTEMD" == 1 ]; then
		cp -v $SPATH/pihole-updatelists.service /etc/systemd/system
		cp -v $SPATH/pihole-updatelists.timer /etc/systemd/system
	fi

	cp -v $SPATH/pihole-updatelists.bash /etc/bash_completion.d/pihole-updatelists
	
	if [ "$DOCKER_INSTALL" == 1 ]; then
		cp -v $SPATH/60-pihole-updatelists.sh /etc/cont-init.d/
	fi

	# Convert line endings when dos2unix command is available
	command -v dos2unix >/dev/null 2>&1 && dos2unix /usr/local/sbin/pihole-updatelists
elif [ "$REMOTE_URL" != "" ] && [ "$GIT_BRANCH" != "" ]; then
	wget -nv -O /usr/local/sbin/pihole-updatelists "$REMOTE_URL/$GIT_BRANCH/src/pihole-updatelists.php" && \
	chmod -v +x /usr/local/sbin/pihole-updatelists
	
	if [ ! -d "/etc/pihole-updatelists" ]; then
		mkdir /etc/pihole-updatelists
	fi
	
	if [ ! -f "/etc/pihole-updatelists/pihole-updatelists.conf" ]; then
		wget -nv -O /etc/pihole-updatelists/pihole-updatelists.conf "$REMOTE_URL/$GIT_BRANCH/src/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		wget -nv -O /etc/systemd/system/pihole-updatelists.service "$REMOTE_URL/$GIT_BRANCH/src/pihole-updatelists.service"
		wget -nv -O /etc/systemd/system/pihole-updatelists.timer "$REMOTE_URL/$GIT_BRANCH/src/pihole-updatelists.timer"
	fi

	wget -nv -O /etc/bash_completion.d/pihole-updatelists "$REMOTE_URL/$GIT_BRANCH/src/pihole-updatelists.bash"

	if [ "$DOCKER_INSTALL" == 1 ]; then
		wget -nv -O /etc/cont-init.d/60-pihole-updatelists.sh "$REMOTE_URL/$GIT_BRANCH/src/60-pihole-updatelists.sh"
	fi
else
	exit 1
fi

if [ "$SYSTEMD" == 1 ]; then
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
" > "/etc/cron.d/pihole-updatelists"
		sed -i "s/#30 /$((1 + RANDOM % 58)) /" /etc/cron.d/pihole-updatelists

		echo "Created crontab entry in /etc/cron.d/pihole-updatelists"
	fi
fi
