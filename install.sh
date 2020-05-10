#!/bin/bash

if [[ $(/usr/bin/id -u) -ne 0 ]]; then
	exec sudo -- "$0" "$@"
	exit
fi

[ -d "/etc/pihole" ] && [ -d "/opt/pihole" ] || { echo "Pi-hole doesn't seem to be installed."; exit 1; }
command -v php >/dev/null 2>&1 || { echo "This script requires PHP-CLI to run, install it with 'sudo apt install php-cli'."; exit 1; }
[[ $(php -v | head -n 1 | cut -d " " -f 2 | cut -f1 -d".") < 7 ]] && { echo "Detected PHP version lower than 7.0, make sure php-cli package is up to date!"; exit 1; }

SPATH=$(dirname $0)
REMOTE_URL=https://raw.githubusercontent.com/jacklul/pihole-updatelists/master
SYSTEMD=`pidof systemd >/dev/null && echo "1" || echo "0"`

[ "$SYSTEMD" == 0 ] && echo "! Systemd not detected, will not install service unit files !"

if [ -f "$SPATH/pihole-updatelists.php" ] && [ -f "$SPATH/pihole-updatelists.conf" ] && [ -f "$SPATH/pihole-updatelists.service" ] && [ -f "$SPATH/pihole-updatelists.timer" ]; then
	cp -v $SPATH/pihole-updatelists.php /usr/local/sbin/pihole-updatelists && \
	chmod +x /usr/local/sbin/pihole-updatelists
	
	if [ ! -f "/etc/pihole-updatelists.conf" ]; then
		cp -v $SPATH/pihole-updatelists.conf /etc/pihole-updatelists.conf
	fi

	if [ "$SYSTEMD" == 1 ]; then
    cp -v $SPATH/pihole-updatelists.service /etc/systemd/system
	  cp -v $SPATH/pihole-updatelists.timer /etc/systemd/system
	fi

	command -v dos2unix >/dev/null 2>&1 && dos2unix /usr/local/sbin/pihole-updatelists
elif [ "$REMOTE_URL" != "" ]; then
	wget -nv -O /usr/local/sbin/pihole-updatelists "$REMOTE_URL/pihole-updatelists.php" && \
	chmod +x /usr/local/sbin/pihole-updatelists
	
	if [ ! -f "/etc/pihole-updatelists.conf" ]; then
		wget -nv -O /etc/pihole-updatelists.conf "$REMOTE_URL/pihole-updatelists.conf"
	fi

	if [ "$SYSTEMD" == 1 ]; then
    wget -nv -O /etc/systemd/system/pihole-updatelists.service "$REMOTE_URL/pihole-updatelists.service"
	  wget -nv -O /etc/systemd/system/pihole-updatelists.timer "$REMOTE_URL/pihole-updatelists.timer"
	fi
else
	exit 1
fi

if [ "$SYSTEMD" == 1 ]; then
  echo "Enabling and starting pihole-updatelists.timer..."
  systemctl enable pihole-updatelists.timer && systemctl start pihole-updatelists.timer
fi
