#!/bin/sh

set -eu

FLAGS="--no-interaction --prefer-dist"

if [ "${DEPENDENCY_SET:-latest}" = "lowest" ]; then
    FLAGS="$FLAGS --prefer-lowest --prefer-stable"
else
    FLAGS="$FLAGS --prefer-stable"
fi

composer update \
    --with "illuminate/console:^${LARAVEL_VERSION}.0" \
    --with "illuminate/contracts:^${LARAVEL_VERSION}.0" \
    --with "illuminate/database:^${LARAVEL_VERSION}.0" \
    --with "illuminate/http:^${LARAVEL_VERSION}.0" \
    --with "illuminate/support:^${LARAVEL_VERSION}.0" \
    --with "illuminate/validation:^${LARAVEL_VERSION}.0" \
    --with "orchestra/testbench:^${TESTBENCH_VERSION}.0" \
    --with-all-dependencies \
    $FLAGS
