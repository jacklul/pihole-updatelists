FROM pihole/pihole:latest

COPY install.sh docker.sh pihole-updatelists.* /tmp/pihole-updatelists/

RUN apk add --no-cache php php-pdo_sqlite php-curl php-openssl php-intl php-pcntl php-posix && \
    bash /tmp/pihole-updatelists/install.sh docker && \
    rm -fr /tmp/pihole-updatelists
