docker run --rm -v ${PWD}:/app -w /app -u "$(id -u):$(id -g)" php:8.1-cli php vendor/bin/phpunit tests/
