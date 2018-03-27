#!/usr/bin/env bash

PUID=${PUID:-911}
PGID=${PGID:-911}

if grep -q ":${PGID}:" /etc/group
then
     echo "group with id ${PGID} exists"
else
     addgroup -g ${PGID} app
fi

GROUPNAME=$(cut -d: -f1 < <(getent group ${PGID}))

adduser -h /app -D -H -s /bin/ash -u ${PUID} -G ${GROUPNAME} app
adduser app users

echo ${PUID}
echo ${PGID}

exec su-exec app "$@"
