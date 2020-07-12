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
SYSTEMD=`pidof systemd >/dev/null && echo "1" || echo "0"` # Is systemd available?
REMOTE_URL=https://raw.githubusercontent.com/jacklul/pihole-updatelists/master # Remote URL that serves raw files from the repository

# This will simply remove the files and reload systemd (if available)
if [ "$1" == "uninstall" ]; then
	rm -v /usr/local/sbin/pihole-updatelists
	rm -v /etc/bash_completion.d/pihole-updatelists
	
	if [ "$SYSTEMD" == 1 ]; then
		rm -v /etc/systemd/system/pihole-updatelists.service
		rm -v /etc/systemd/system/pihole-updatelists.timer
		reloadSystemd
	fi

	exit 0
fi

# We require some stuff before continuing
[ -d "/etc/pihole" ] && [ -d "/opt/pihole" ] || { echo "Pi-hole doesn't seem to be installed."; exit 1; }
command -v php >/dev/null 2>&1 || { echo "This script requires PHP-CLI to run, install it with 'sudo apt install php-cli'."; exit 1; }
[[ $(php -v | head -n 1 | cut -d " " -f 2 | cut -f1 -d".") < 7 ]] && { echo "Detected PHP version lower than 7.0, make sure php-cli package is up to date!"; exit 1; }

# Stop on first error
set -e

# Use local files when available, otherwise install from remote repository
if \
	[ -f "$SPATH/pihole-updatelists.php" ] && \
	[ -f "$SPATH/pihole-updatelists.conf" ] && \
	[ -f "$SPATH/pihole-updatelists.service" ] && \
	[ -f "$SPATH/pihole-updatelists.timer" ] && \
	[ -f "$SPATH/pihole-updatelists.bash" ] \
; then
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
elif [ "$REMOTE_URL" != "" ]; then
	wget -nv -O /usr/local/sbin/pihole-updatelists "$REMOTE_URL/pihole-updatelists.php" && \
	chmod -v +x /usr/local/sbin/pihole-updatelists
	
	if [ ! -f "/etc/pihole-updatelists.conf" ]; then
		wget -nv -O /etc/pihole-updatelists.conf "$REMOTE_URL/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
		wget -nv -O /etc/systemd/system/pihole-updatelists.service "$REMOTE_URL/pihole-updatelists.service"
		wget -nv -O /etc/systemd/system/pihole-updatelists.timer "$REMOTE_URL/pihole-updatelists.timer"
	fi

	wget -nv -O /etc/bash_completion.d/pihole-updatelists "$REMOTE_URL/pihole-updatelists.bash"
else
	echo "REMOTE_URL is not set and required files are not present in current directory, unable to install!"
	exit 1
fi

if [ "$SYSTEMD" == 1 ]; then
	if [ `systemctl is-enabled pihole-updatelists.timer` != 'enabled' ]; then
		echo "Enabling and starting pihole-updatelists.timer..."
		systemctl enable pihole-updatelists.timer && systemctl start pihole-updatelists.timer
	else
		reloadSystemd
	fi
else
	echo "To enable schedule you will have to add cron entry manually, example:"
	echo "30 3 * * 6   root   /usr/local/sbin/pihole-updatelists"
fi
