#!/bin/bash
# This is the startup file for Docker installation that runs before actual _postFTL service is started

if [ ! -d "/etc/s6-overlay/s6-rc.d/_postFTL" ]; then
	echo "Missing /etc/s6-overlay/s6-rc.d/_postFTL - not a Docker installation?"
	exit
fi

# Respect PH_VERBOSE environment variable
if [ "${PH_VERBOSE:-0}" -gt 0 ]; then
	set -x
	SCRIPT_ARGS="--verbose --debug"
fi

# Recreate the config file if it is missing
if [ ! -f "/etc/pihole-updatelists/pihole-updatelists.conf" ]; then
	cp /etc/pihole-updatelists.conf /etc/pihole-updatelists/pihole-updatelists.conf
	echo "Created /etc/pihole-updatelists/pihole-updatelists.conf"
fi

# Fix permissions (when config directory is mounted as a volume)
chown root:root /etc/pihole-updatelists/*
chmod 644 /etc/pihole-updatelists/*

if [ -n "$SKIPGRAVITYONBOOT" ]; then
	echo "Lists update skipped - SKIPGRAVITYONBOOT=true"
else
	if [ ! -f "/etc/pihole/gravity.db" ]; then
		echo "Gravity database not found - running 'pihole -g' command..."
		pihole -g
	fi

	if [ -z "$(printenv PHUL_LOG_FILE)" ]; then
		export PHUL_LOG_FILE="-/var/log/pihole-updatelists-boot.log"
	fi

	# shellcheck disable=SC2086
	/usr/bin/php /usr/local/sbin/pihole-updatelists --config=/etc/pihole-updatelists/pihole-updatelists.conf --env --no-gravity --no-reload ${SCRIPT_ARGS}
fi
