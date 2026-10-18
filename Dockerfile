FROM pihole/pihole:latest

# Install our dependencies
RUN apk add --no-cache php php-pdo_sqlite php-curl php-openssl php-intl php-pcntl php-posix

# Installer checks Pi-hole version with 'pihole version' command but permissions
# are not set correctly for /etc/pihole at that time so we need to correct them as
# otherwise we will see 'mktemp: Permission denied' error
RUN chown -R pihole:pihole /etc/pihole/

# Copy required files
COPY install.sh docker.sh pihole-updatelists.* /tmp/pihole-updatelists/

# Run the installer and then clean up
RUN bash /tmp/pihole-updatelists/install.sh docker && rm -fr /tmp/pihole-updatelists
