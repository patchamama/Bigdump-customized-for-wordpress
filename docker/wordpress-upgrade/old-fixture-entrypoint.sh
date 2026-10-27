#!/usr/bin/env bash
# Offline only; parent must attest the container/mount/capability envelope first.
set -euo pipefail
umask 077
exec 3>&2 2>/dev/null
finish() {
    local status=$?
    if [[ -n ${dbpid:-} ]]; then
        kill -TERM "$dbpid" 2>/dev/null || true
        for ((j=0;j<50;j++)); do
            if ! kill -0 "$dbpid" 2>/dev/null; then break; fi
            sleep 0.1
        done
        kill -KILL "$dbpid" 2>/dev/null || true
        wait "$dbpid" 2>/dev/null || true
    fi
    if [[ $status != 0 ]]; then printf 'Old fixture failed closed.\n' >&3; exit 1; fi
}
trap finish EXIT
[[ $# == 0 && $EUID == 0 && -d /data && ! -L /data ]]
# Refuse all previous state, including partial failed attempts. Never repair or replay.
[[ -z $(find /data -mindepth 1 -maxdepth 1 -print -quit) ]]
(set -o noclobber; printf 'one-shot\n' > /data/fixture-started)
mkdir /data/logs /data/mysql /run/mysqld
chmod 700 /data/logs
# mysql needs traverse-only access; UID 33 needs artifact creation, not DB access.
chmod 771 /data
chgrp www-data /data
chmod 755 /run/mysqld
chown mysql:mysql /run/mysqld /data/mysql
# Logs are private artifacts, never echoed, including on failure.
exec 2>/data/logs/fixture-private.log
timeout 90 mariadb-install-db --user=mysql --datadir=/data/mysql --auth-root-authentication-method=socket > /data/logs/database-init.log 2>&1
mariadbd --user=mysql --datadir=/data/mysql --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid --skip-networking --local-infile=0 --skip-log-bin --general-log=0 --slow-query-log=0 --skip-name-resolve > /data/logs/database.log 2>&1 &
dbpid=$!
ready=0
for ((i=0;i<100;i++)); do
    if timeout 1 mariadb-admin --socket=/run/mysqld/mysqld.sock --user=root ping >/dev/null 2>&1; then ready=1; break; fi
    sleep 0.1
done
[[ $ready == 1 ]]
timeout 90 php /cache/tools/old-fixture.php prepare >>/data/logs/fixture-private.log 2>&1
timeout 180 runuser -u www-data -- php /cache/tools/old-fixture.php install >>/data/logs/fixture-private.log 2>&1
# WordPress can terminate PHP with status 0 on an error page: require final artifacts.
[[ -s /data/seed.sql && -s /data/fixture-metadata.json ]]
printf '{"ok":true,"phase":"installed","version":"6.8.3","metadata":"/data/fixture-metadata.json"}\n'
