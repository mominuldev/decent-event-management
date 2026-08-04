#!/bin/sh
# Config/route/view caching happens here, at container start, rather than
# baked into the image at build time — env vars (DB/Redis/gateway
# credentials) are injected by the orchestrator per-deployment, not known at
# build time, and `config:cache` snapshots whatever `env()` sees at the
# moment it runs. Every app, horizon, and scheduler container built from
# this image runs this same entrypoint, so all three cache from the same
# environment consistently.
#
# Deliberately does NOT run migrations — with more than one replica behind
# a load balancer (docs/07 §7.3's "autoscaling 2→6"), every replica running
# this entrypoint concurrently would race to migrate the same database.
# Migration is a separate, single-run step in the deploy pipeline (see
# .github/workflows/deploy-image.yml) that runs once, before traffic is
# ever routed to the new image.
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
