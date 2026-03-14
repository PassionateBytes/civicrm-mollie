.PHONY: default install install-dev test lint format clean

PHP_IMAGE = php:8.4-cli
COMPOSER_IMAGE = composer:2
DOCKER_RUN = docker run --rm -v $(CURDIR):/app -w /app

default: install-dev lint test clean
	@printf "\nDone.\n"

install:
	@printf "\nInstalling production dependencies...\n"
	$(DOCKER_RUN) $(COMPOSER_IMAGE) install --no-dev --ignore-platform-reqs --quiet

install-dev:
	@printf "\nInstalling development dependencies...\n"
	$(DOCKER_RUN) $(COMPOSER_IMAGE) install --ignore-platform-reqs --quiet

test:
	@printf "\nExecuting test suite...\n"
	$(DOCKER_RUN) $(PHP_IMAGE) php vendor/bin/phpunit --testdox

lint:
	@printf "\nChecking code style...\n"
	$(DOCKER_RUN) $(PHP_IMAGE) php vendor/bin/php-cs-fixer fix --dry-run --diff

format:
	@printf "\nFormatting code...\n"
	$(DOCKER_RUN) $(PHP_IMAGE) php vendor/bin/php-cs-fixer fix

clean:
	@printf "\nCleaning bundled dependency directory...\n"
	$(DOCKER_RUN) $(PHP_IMAGE) find /app/vendor -not -user $(shell id -u) -exec rm -rf {} + 2>/dev/null; true
	git checkout -- vendor/
	git clean -fd vendor/
