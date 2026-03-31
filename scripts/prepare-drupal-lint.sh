#!/bin/bash
set -e

if [ -z "$TARGET_DRUPAL_CORE_VERSION" ]; then
  TARGET_DRUPAL_CORE_VERSION=10
fi

echo "php --version"
php --version
echo "composer --version"
composer --version

echo "\$COMPOSER_HOME: $COMPOSER_HOME"
echo "TARGET_DRUPAL_CORE_VERSION: $TARGET_DRUPAL_CORE_VERSION"

# Add this line to avoid the plugin prompt
composer config --global allow-plugins.dealerdirect/phpcodesniffer-composer-installer true

# Install all PHPCS standards together to avoid dependency conflicts
# between drupal/coder (requires phpcs 4.x) and phpcompatibility.
composer global require --dev \
  drupal/coder \
  phpcompatibility/php-compatibility:"dev-develop as 9.3.5" \
  dealerdirect/phpcodesniffer-composer-installer \
  --with-all-dependencies

export PATH="$PATH:$COMPOSER_HOME/vendor/bin"

composer global show -P
phpcs -i

phpcs --config-set colors 1
phpcs --config-set drupal_core_version 11$TARGET_DRUPAL_CORE_VERSION

phpcs --config-show
