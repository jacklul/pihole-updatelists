FROM pihole/pihole:latest

COPY src/* /tmp/pihole-updatelists/

RUN chmod +x /tmp/pihole-updatelists/install.sh && /tmp/pihole-updatelists/install.sh DOCKER
