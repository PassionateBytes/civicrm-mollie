.PHONY: all install test clean

all: install test clean
	@printf "\nDone.\n"

install:
	@printf "\nInstalling development dependencies...\n"
	docker run --rm \
		-v $(CURDIR):/app \
		-w /app \
		composer:2 install --ignore-platform-reqs --quiet

test:
	@printf "\nExecuting test suite...\n"
	docker run --rm \
		-v $(CURDIR):/app \
		-w /app \
		php:8.4-cli php vendor/bin/phpunit --testdox

clean:
	@printf "\nCleaning bundled dependency directory...\n"
	git checkout -- vendor/
	git clean -fd vendor/
