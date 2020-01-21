# Pi-hole - update lists from remote servers

This script will update your lists using remote ones.

It will automatically merge with existing entries on the lists and disable entries that have been removed from the remote list.

Specify remote lists in `/etc/pihole-updatelists.conf` file.

### Install

```bash
wget -q -O - https://raw.githubusercontent.com/jacklul/pihole-updatelists/master/install.sh | sudo bash
```
