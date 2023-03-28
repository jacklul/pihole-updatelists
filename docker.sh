#!/bin/bash
# This is the startup file (_updatelistsonboot.sh) for Docker installation

if [ ! -d "/etc/s6-overlay/s6-rc.d/_updatelistsonboot" ]; then
	echo "Missing /etc/s6-overlay/s6-rc.d/_updatelistsonboot - not a Docker installation?"
	exit
fi

# Respect PH_VERBOSE environment variable
if [ "${PH_VERBOSE:-0}" -gt 0 ]; then
	set -x
	SCRIPT_ARGS="--verbose --debug"
fi

# If the config file is missing in the target directory then recreate it
if [ ! -f "/etc/pihole-updatelists/pihole-updatelists.conf" ]; then
	cp -v /etc/pihole-updatelists.conf /etc/pihole-updatelists/pihole-updatelists.conf
fi

# Fix permissions when mounted as a volume
chown -v root:root /etc/pihole-updatelists/*
chmod -v 644 /etc/pihole-updatelists/*

# Disable default gravity schedule when enabled
if [ "$(cat /etc/cron.d/pihole grep 'pihole updateGravity' | cut -c1-1)" != "#" ]; then
	sed -e '/pihole updateGravity/ s/^#*/#/' -i /etc/cron.d/pihole
	echo "Disabled default gravity update schedule in /etc/cron.d/pihole"
fi

if [ ! -z "${SKIPGRAVITYONBOOT}" ]; then
	echo "Lists update skipped - SKIPGRAVITYONBOOT=true"
elif [ ! -f "/etc/pihole/gravity.db" ]; then
	echo "Lists update skipped - gravity database not found"
else
	/usr/bin/php /usr/local/sbin/pihole-updatelists --config=/etc/pihole-updatelists/pihole-updatelists.conf --env --no-gravity --no-reload ${SCRIPT_ARGS} > /var/log/pihole-updatelists-onboot.log
fi
