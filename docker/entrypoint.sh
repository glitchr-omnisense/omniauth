#!/bin/sh
# Composes the harness (this core + every method package), installs it when
# it changed, then runs the command: "test" for PHPUnit, "bare" for the
# script that signs in with no framework at all, "phpstan" for PHPStan at the
# highest level on each package that has a phpstan.neon (its baseline
# included), "composer ..." or "sh" as they are.
set -e
php /omniauth/core/docker/harness/setup.php
cd /harness
if [ ! -f vendor/autoload.php ]; then
    composer install --no-progress
elif [ composer.json -nt composer.lock ]; then
    composer update --no-progress
fi
case "${1:-test}" in
    test) shift || true; exec vendor/bin/phpunit "$@" ;;
    bare) shift; exec php /omniauth/core/docker/harness/bin/bare "$@" ;;
    phpstan)
        status=0
        for dir in /omniauth/core /omniauth/plugins/*; do
            [ -f "$dir/phpstan.neon" ] || continue
            [ "$dir" != /omniauth/plugins/core ] || continue
            echo "== $dir"
            vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$dir/phpstan.neon" --autoload-file=/harness/vendor/autoload.php "$dir" || status=1
        done
        exit $status ;;
    composer|sh|php) exec "$@" ;;
    *) echo "test, bare, phpstan, composer, sh or php" >&2; exit 2 ;;
esac
