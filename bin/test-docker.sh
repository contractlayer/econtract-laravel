#!/bin/sh
# Runs the test suite in Docker (no local PHP needed). The source is copied into the container, so
# nothing is written to your checkout.
set -e
cd "$(dirname "$0")/.."
docker run --rm -v "$PWD":/src:ro composer:2 sh -c '
  mkdir -p /work && cd /src && tar --exclude=./vendor --exclude="._*" -cf - . | tar -xf - -C /work &&
  cd /work && composer install -q --no-interaction --no-progress && vendor/bin/phpunit'
