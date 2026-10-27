# Build locally from the repository root; never built or pulled by the web app/runner.
FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server openssl libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli zip \
    && a2enmod ssl rewrite headers \
    && apt-get clean
COPY docker/wordpress-upgrade/ /opt/upgrade/
COPY cms_tools.php /opt/cms_tools.php
COPY docker/wordpress-upgrade/apache.conf /etc/apache2/sites-available/000-default.conf
ENV CMS_UPGRADE_OFFLINE_DIR=/cache \
    PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/run/php.d \
    APACHE_RUN_DIR=/run/apache2 APACHE_LOCK_DIR=/run/apache2 APACHE_PID_FILE=/run/apache2/apache2.pid \
    APACHE_LOG_DIR=/data/logs
ENTRYPOINT ["bash", "/opt/upgrade/entrypoint.sh"]
