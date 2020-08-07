FROM pihole/pihole:latest

COPY install.sh pihole-updatelists.* /tmp/pihole-updatelists/

RUN chmod +x /tmp/pihole-updatelists/install.sh && \
    /tmp/pihole-updatelists/install.sh && \
    rm -fr /tmp/pihole-updatelists
