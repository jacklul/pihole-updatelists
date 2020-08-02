#!/usr/bin/with-contenv bash
set -e

if [ "${PH_VERBOSE:-0}" -gt 0 ] ; then 
    set -x ;
fi

# create config if does not exists
if [ ! -e /etc/pihole-updatelists/pihole-updatelists.conf ]; then
    cp /tmp/pihole-updatelists/pihole-updatelists.conf /etc/pihole-updatelists/pihole-updatelists.conf
    chmod a+rw /etc/pihole-updatelists/pihole-updatelists.conf
fi

CRONTAB_EXPRESSION=$(sed -n '/^CRONTAB.*/p' /etc/pihole-updatelists/pihole-updatelists.conf)
if [ "${CRONTAB_EXPRESSION}" != "" ]; then
    ESCAPED_CRONTAB_EXPRESSION=$(printf '%s\n' "$CRONTAB_EXPRESSION" | sed -e 's/^CRONTAB=\(.*\)/\1/g' -e 's/[]\/$*.^[]/\\&/g');
    if [ $(grep -c "${ESCAPED_CRONTAB_EXPRESSION}" /etc/cron.d/pihole-updatelists) -eq 0 ]; then
		echo "# Pi-hole's Lists Updater by Jack'lul
# https://github.com/jacklul/pihole-updatelists

${CRONTAB_EXPRESSION#*=}   root   /usr/local/sbin/pihole-updatelists
" > "/etc/cron.d/pihole-updatelists"
        echo "Updated crontab entry in /etc/cron.d/pihole-updatelists"
    else
        echo "Crontab entry already up-to-date"
    fi 

fi

#execute updater at container start 
/usr/local/sbin/pihole-updatelists