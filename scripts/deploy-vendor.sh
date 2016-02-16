#!/usr/bin/env bash

# TODO: log all invalid paths end echo it after copying

SCRIPT_PATH=$(cd `dirname "${BASH_SOURCE[0]}"` && pwd)

DIR_SOURCE="vendor"
DIR_DEST="vendor-dist"

# delete old dist files
rm -Rf "$SCRIPT_PATH/../$DIR_DEST/*"


cd "$SCRIPT_PATH/../$DIR_SOURCE"

while IFS='' read -r line || [[ -n "$line" ]]; do
	if [[ -d "$line" ]]
	then
		echo "found dir $line"
		cp --parents --recursive "$line" "$SCRIPT_PATH/../$DIR_DEST/"
	fi
	if [[ -f "$line" ]]
	then
		echo "found file $line"
		cp --parents "$line" "$SCRIPT_PATH/../$DIR_DEST/"
	fi
    #echo "Text read from file: $line"
done < "$SCRIPT_PATH/deploy-vendor.txt"