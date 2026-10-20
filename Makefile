.PHONY: help up down test test-panel test-agent lint lint-panel lint-agent

help:
	@echo "Available commands:"
	@echo "  make up          - Start local development services with Docker Compose"
	@echo "  make down        - Stop local development services"
	@echo "  make test        - Run panel and agent test suites"
	@echo "  make lint        - Run style checks and static analysis"

up:
	docker compose up -d

down:
	docker compose down

test: test-panel test-agent

test-panel:
	./vendor/bin/pest

test-agent:
	cd agent && go test -v ./...

lint: lint-panel lint-agent

lint-panel:
	./vendor/bin/pint --test
	./vendor/bin/phpstan analyse --memory-limit=1G

lint-agent:
	cd agent && go vet ./...

