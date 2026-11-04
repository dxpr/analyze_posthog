#!/bin/bash

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
# Coder is pinned to 8.x: 9.x needs phpcs 4.x, which phpcompatibility 9.3 rejects.
composer global require --dev \
  drupal/coder:^8.3 \
  phpcompatibility/php-compatibility:^9.3 \
  squizlabs/php_codesniffer:^3.13 \
  dealerdirect/phpcodesniffer-composer-installer \
  --with-all-dependencies || exit 1

export PATH="$PATH:$COMPOSER_HOME/vendor/bin"

composer global show -P
phpcs -i

phpcs --config-set colors 1
phpcs --config-set drupal_core_version "$TARGET_DRUPAL_CORE_VERSION"

phpcs --config-show
