#!/usr/bin/env bash
# Installs nowo-tech/search-kit-bundle (require-dev, never in composer.json or composer.lock) on PHP 8.3 or later.
# SearchKit needs PHP 8.3+, the bundle still supports PHP 8.2: there the script does nothing, the SearchKit provider
# and its tests are skipped, and the lock is untouched. Run it after `composer install` / `composer update`.
set -euo pipefail

if [ "$(php -r 'echo PHP_VERSION_ID >= 80300 ? 1 : 0;')" != "1" ]; then
  echo "PHP < 8.3: SearchKit is not installed, the SearchKit tests skip."
  exit 0
fi

# composer.json pins platform.php to the lowest supported PHP for the lock; resolve SearchKit against the running PHP.
composer config platform.php "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . ".99";')"

composer require --dev "nowo-tech/search-kit-bundle:^1.9" --no-interaction --no-progress --prefer-dist
