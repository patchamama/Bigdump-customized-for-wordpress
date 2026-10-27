#!/usr/bin/env bash
# Root prepares owned resources only. Apache PHP workers and MariaDB are non-root.
set -euo pipefail
umask 077
mkdir -p /run/tls /run/php.d /run/mysqld /run/apache2 /run/lock /data/logs /data/mysql
chmod 755 /data /run/mysqld
chgrp www-data /data/logs
chmod 710 /data/logs
chown mysql:mysql /run/mysqld /data/mysql
# Host creates this archive from validated regular copies; no configs/logs/links included.
tar --extract --file=/data/input.tar --directory=/data --no-same-owner --no-same-permissions
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=CMS-Upgrade-Ephemeral-CA -keyout /run/tls/ca.key -out /run/tls/ca.crt 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj /CN=staging.invalid -keyout /run/tls/site.key -out /run/tls/site.csr 2>/dev/null
printf 'subjectAltName=DNS:staging.invalid\nextendedKeyUsage=serverAuth\n' > /run/tls/extensions
openssl x509 -req -in /run/tls/site.csr -CA /run/tls/ca.crt -CAkey /run/tls/ca.key -CAcreateserial -days 1 -extfile /run/tls/extensions -out /run/tls/site.crt 2>/dev/null
chmod 644 /run/tls/ca.crt
chmod 755 /run/tls
printf 'openssl.cafile=/run/tls/ca.crt\nmysqli.default_socket=/run/mysqld/mysqld.sock\npdo_mysql.default_socket=/run/mysqld/mysqld.sock\nmemory_limit=256M\nmax_execution_time=120\nupload_max_filesize=32M\npost_max_size=32M\ndisplay_errors=Off\nlog_errors=On\nerror_log=/data/logs/php-error.log\n' > /run/php.d/99-offline.ini
chmod 644 /run/php.d/99-offline.ini
mariadb-install-db --user=mysql --datadir=/data/mysql --auth-root-authentication-method=socket > /data/logs/database-init.log 2>&1
mariadbd --user=mysql --datadir=/data/mysql --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid --skip-networking --local-infile=0 --skip-log-bin --general-log=0 --slow-query-log=0 --skip-name-resolve > /data/logs/database.log 2>&1 &
for ((i=0;i<100;i++)); do
    if php /opt/upgrade/setup.php > /data/logs/setup.log 2>&1; then
        touch /run/upgrade-ready
        exec apache2-foreground
    fi
    # Only connection startup is retryable; setup creates this marker before any SQL mutation.
    if [[ -e /run/upgrade-setup-started ]]; then exit 1; fi
    sleep 0.1
done
exit 1
