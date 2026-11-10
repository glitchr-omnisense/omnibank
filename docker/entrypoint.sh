#!/bin/sh
# Composes the harness (this core + every provider), installs it when it
# changed, then runs the command: the console by default, "test" for PHPUnit,
# "bare" for the script that uses the packages with no bundle, no container
# and no console, "phpstan" for PHPStan on every package that has a
# phpstan.neon, "composer ..." or "sh" as they are.
set -e
php /omnibank/core/docker/harness/setup.php
cd /harness
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
elif [ composer.json -nt composer.lock ]; then
    composer update --no-interaction --no-progress
fi
case "${1:-}" in
    test) shift; exec vendor/bin/phpunit "$@" ;;
    bare) shift; exec php /omnibank/core/docker/harness/bin/bare "$@" ;;
    phpstan)
        shift
        status=0
        for dir in /harness/packages/*; do
            [ -f "$dir/phpstan.neon" ] || continue
            echo "== $dir"
            vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$dir/phpstan.neon" --autoload-file=/harness/vendor/autoload.php "$@" "$dir" || status=1
        done
        exit $status ;;
    composer|sh|php) exec "$@" ;;
    *) exec php /omnibank/core/docker/harness/bin/omnibank "$@" ;;
esac
