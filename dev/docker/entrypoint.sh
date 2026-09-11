#!/bin/bash
# Bootstraps a Craft project in /app on first start (create-project, install Craft, require the
# bind-mounted plugin from /plugin, seed a test volume + field + assets), then execs CMD.
set -euo pipefail
cd /app

MAJOR="${CRAFT_MAJOR:-5}"
STAMP=/app/storage/.asset-metadata-dev-ready

if [ ! -f composer.json ]; then
    echo "==> Creating Craft ${MAJOR} project in /app"
    composer create-project "craftcms/craft:^${MAJOR}" /tmp/craft-proj --no-interaction --no-scripts --no-install
    cp -a /tmp/craft-proj/. /app/ && rm -rf /tmp/craft-proj
    # The starter's post-create script would rename composer.json.default; do it ourselves.
    if [ -f composer.json.default ]; then mv composer.json.default composer.json; fi
    cp -n .env.example.dev .env 2>/dev/null || true
    composer config repositories.asset-metadata '{"type":"path","url":"/plugin","options":{"symlink":true}}'
    composer config --no-plugins allow-plugins.craftcms/plugin-installer true
    composer config --no-plugins allow-plugins.yiisoft/yii2-composer true
    composer config minimum-stability dev
    composer config prefer-stable true
    # Craft 4 pins Twig 3.19, which Composer's advisory policy would otherwise block.
    NO_BLOCKING=""; if [ "$MAJOR" = "4" ]; then NO_BLOCKING="--no-blocking"; fi
    composer require "craftcms/cms:^${MAJOR}.0" "carlcs/craft-assetmetadata:*@dev" --no-interaction --no-progress $NO_BLOCKING
    # PHPUnit inside the Craft project runs the plugin's integration tests against this installation.
    composer require --dev "phpunit/phpunit:^10.5" --no-interaction --no-progress --with-all-dependencies $NO_BLOCKING || true
fi

cp -f /scripts/app.web.php /app/config/app.web.php

if [ ! -d vendor ]; then
    echo "==> composer install"
    composer install --no-interaction --no-progress
fi

echo "==> Waiting for database ${CRAFT_DB_SERVER}"
for i in $(seq 1 60); do
    if mysqladmin ping --skip-ssl -h"${CRAFT_DB_SERVER}" -u"${CRAFT_DB_USER}" -p"${CRAFT_DB_PASSWORD}" --silent 2>/dev/null; then break; fi
    sleep 2
done

if [ ! -f "$STAMP" ]; then
    if ! php craft install/check >/dev/null 2>&1; then
        echo "==> Installing Craft ${MAJOR}"
        php craft install \
            --interactive=0 \
            --email="${DEV_ADMIN_EMAIL:-admin@example.com}" \
            --username="${DEV_ADMIN_USERNAME:-admin}" \
            --password="${DEV_ADMIN_PASSWORD:-password}" \
            --siteName="Asset Metadata Dev (Craft ${MAJOR})" \
            --siteUrl="${PRIMARY_SITE_URL}" \
            --language="en-US"
    fi
    echo "==> Installing plugin"
    php craft plugin/install asset-metadata || true
    echo "==> Seeding test volume, field and assets"
    php /scripts/seed.php
    mkdir -p /app/storage && touch "$STAMP"
fi

mkdir -p /app/storage /app/web/cpresources
exec "$@"
