.PHONY: test vendor-clean

test:
	docker run --rm \
		-v $(CURDIR):/app \
		-w /app \
		composer:2 install --ignore-platform-reqs --quiet
	docker run --rm \
		-v $(CURDIR):/app \
		-w /app \
		php:8.4-cli php vendor/bin/phpunit
	$(MAKE) vendor-clean

vendor-clean:
	git checkout -- vendor/
	git clean -fd vendor/
