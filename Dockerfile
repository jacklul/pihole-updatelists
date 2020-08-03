FROM pihole/pihole:latest

COPY install.sh /tmp/pihole-updatelists/install.sh
COPY pihole-updatelists.* /tmp/pihole-updatelists/

RUN chmod +x /tmp/pihole-updatelists/install.sh && /tmp/pihole-updatelists/install.sh
