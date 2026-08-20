# Makefile for Scumhouse deployment -- mirrors every other game's Makefile in this repo.
#
# Prereqs:
#   1. ~/.ssh/config has a host entry matching REMOTE_HOST (boarddames.com and
#      every game on it live on the beponika-droplet box)
#   2. inc/config.php exists on the server, created from inc/config.example.php
#      (never synced from here -- see the --exclude below)

# Override per deployment, e.g.
#   make deploy REMOTE_HOST=my-server REMOTE_PATH=/srv/scumhouse
# or export them in your environment.
REMOTE_HOST ?= beponika-droplet
REMOTE_PATH ?= /var/www/scumhouse

.PHONY: deploy dry-run ssh test

deploy:
	rsync -avz --delete \
		--exclude='.git*' \
		--exclude='inc/config.php' \
		--exclude='storage/sessions' \
		--exclude='public/uploads' \
		--exclude='dist' \
		--exclude='tests' \
		./ $(REMOTE_HOST):$(REMOTE_PATH)/

dry-run:
	rsync -avzn --delete \
		--exclude='.git*' \
		--exclude='inc/config.php' \
		--exclude='storage/sessions' \
		--exclude='public/uploads' \
		--exclude='dist' \
		--exclude='tests' \
		./ $(REMOTE_HOST):$(REMOTE_PATH)/

ssh:
	ssh $(REMOTE_HOST)

# The dev machine has no PHP, so the PHP suites run on the droplet against a
# throwaway copy of the tree. run_interop.sh does its own shuttling.
test:
	ssh $(REMOTE_HOST) 'rm -rf /tmp/shfull'
	rsync -a --exclude='.git*' --exclude='storage' ./ $(REMOTE_HOST):/tmp/shfull/
	ssh $(REMOTE_HOST) 'cd /tmp/shfull && php tests/simulate.php && php tests/privacy_check.php'
	./tools/integrity.sh --check
	./tests/run_interop.sh
