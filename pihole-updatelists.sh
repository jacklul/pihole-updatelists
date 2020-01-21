#!/bin/bash
####################################
# Remote Lists updater for Pi-hole #
#  by Jack'lul <jacklul.github.io> #
#                                  #
# github.com/jacklul/raspberry-pi  #
#                                  #
# This script fetches remote lists #
# and merges them with local ones. #
####################################

if [[ $(/usr/bin/id -u) -ne 0 ]]; then
	exec sudo -- "$0" "$@"
	exit
fi

PIHOLE_CONFIG_DIR=/etc/pihole
CONFIG_FILE=/etc/pihole-updatelists.conf
TMP_PATH=/tmp/pihole-updatelists
ADLISTS_URL=
ADLISTS_TARGET=adlists.list
WHITELIST_URL=
WHITELIST_TARGET=whitelist.txt
REGEX_URL=
REGEX_TARGET=regex.list
BLACKLIST_URL=
BLACKLIST_TARGET=blacklist.txt
LISTS_TO_PROCESS=(ADLISTS WHITELIST BLACKLIST REGEX)
LOCKFILE=/tmp/$(basename $0).lock

if [ -f "${CONFIG_FILE}" ]; then
	. ${CONFIG_FILE}
fi

if [ ! -f "$LOCKFILE" ]; then
	touch $LOCKFILE
else
	echo "Already running. (LOCKFILE: ${LOCKFILE})"
	exit 6
fi

command -v curl >/dev/null 2>&1 || { echo "Please install cURL"; exit 1; }

function cleanup() {
	rm -fr ${TMP_PATH}
}

function onInterruptOrExit() {
	cleanup
	rm "$LOCKFILE" >/dev/null 2>&1
}
trap onInterruptOrExit EXIT

function process() {
	LIST_URL=$1
	LIST_TARGET=$2
	LIST_TARGET_TMP=${TMP_PATH}/$(basename $2)
	
	if [ "${LIST_URL}" == "" ]; then
		exit
	fi

	if [[ "${LIST_TARGET}" != /* ]]; then
		LIST_TARGET=${PIHOLE_CONFIG_DIR}/${LIST_TARGET}
	fi

	printf " Downloading '${LIST_URL}'..."
	CURL="$(curl -sS --retry 3 ${LIST_URL} -o ${LIST_TARGET_TMP}.remote 2>&1)"

	if [ $? -eq 0 ]; then
		printf " \u2713\n"

		if [ ! -f "${LIST_TARGET}" ] || [[ ! -s "${LIST_TARGET}" ]]; then
			touch ${LIST_TARGET}

			if [[ -s "${LIST_TARGET}.default" ]]; then
				cp ${LIST_TARGET}.default ${LIST_TARGET}
			fi
		fi
		
		# Copy current file
		cp ${LIST_TARGET} ${LIST_TARGET_TMP}.tmp
		
		if [ -f "${LIST_TARGET}.remote" ]; then
			cp ${LIST_TARGET_TMP}.remote ${LIST_TARGET_TMP}.remote_tmp
			
			# Grab entries that got removed from the remote file since the last update
			grep -vFf ${LIST_TARGET_TMP}.remote_tmp ${LIST_TARGET}.remote > ${LIST_TARGET_TMP}.removed
			
			# Disable removed entries
			if [[ -s "${LIST_TARGET_TMP}.removed" ]]; then
				awk 'FNR==NR {a[$0];next} ($0 in a){$0="#" $0} 1' ${LIST_TARGET_TMP}.removed ${LIST_TARGET} > ${LIST_TARGET_TMP}.tmp
			fi
			
			# Insert remote entries
			cat ${LIST_TARGET_TMP}.remote >> ${LIST_TARGET_TMP}.tmp
			
			# Fetch disabled entries
			grep "^#" ${LIST_TARGET} > ${LIST_TARGET_TMP}.disabled && sed -i '/^#.*/s/^#//' ${LIST_TARGET_TMP}.disabled
			
			# Make sure disabled entries stay disabled
			if [[ -s "${LIST_TARGET_TMP}.disabled" ]]; then
				grep -xvFf ${LIST_TARGET_TMP}.disabled ${LIST_TARGET_TMP}.tmp > ${LIST_TARGET_TMP}.tmp2
				mv ${LIST_TARGET_TMP}.tmp2 ${LIST_TARGET_TMP}.tmp
			fi
		fi

		printf " Sorting and removing duplicates..."
		
		# Prevent interrupt
		trap '' INT
		
		# Backup current list
		cp ${LIST_TARGET} ${LIST_TARGET}.bak

		# Save new remote file
		cp ${LIST_TARGET_TMP}.remote ${LIST_TARGET}.remote
		
		# Remove duplicates and finally write to the target file
		cat ${LIST_TARGET_TMP}.tmp | awk '!x[$0]++' > ${LIST_TARGET_TMP} && cat ${LIST_TARGET_TMP} > ${LIST_TARGET} && printf " \u2713\n" || printf " \u2717\n"
		
		# Restore interrupt possibility
		trap interrupt INT
	else
		printf " \u2717  $CURL\n"
	fi
}

renice -n -20 $$ > /dev/null

mkdir -p ${TMP_PATH}
cd ${PIHOLE_CONFIG_DIR}

for LIST in ${LISTS_TO_PROCESS[*]}
do
	LIST_URL=${LIST}_URL
	LIST_TARGET=${LIST}_TARGET

	if [ "${!LIST_URL}" != "" ]; then
		printf "Updating '${!LIST_TARGET}':\n"
		process ${!LIST_URL} ${!LIST_TARGET}
	fi
done

cleanup

if [ "$1" != "" ]; then
	exit
fi

printf "Updating Pi-hole's Gravity...\n\n"
pihole updateGravity
