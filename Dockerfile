FROM pihole/pihole:latest

COPY install.sh docker.sh pihole-updatelists.* /tmp/pihole-updatelists/

RUN apk add --no-cache php php-sqlite3 php-intl php-curl && \
    bash /tmp/pihole-updatelists/install.sh docker && \
    rm -fr /tmp/pihole-updatelists
