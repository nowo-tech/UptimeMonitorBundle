#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
# Set via .env / Compose only — not baked into the image ENV.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /app/Caddyfile.dev ]; then
			cp /app/Caddyfile.dev /etc/caddy/Caddyfile
		elif [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/Caddyfile ]; then
			cp /app/Caddyfile /etc/caddy/Caddyfile
		fi
		# else keep image default Caddyfile (worker enabled)
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/uptime-monitor-bundle 2>/dev/null || true
mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var 2>/dev/null || true

# Worker mode exits if public/index.php boots before Composer install.
# Makefile runs `composer update` after `docker compose up -d`.
if [ ! -f /app/vendor/autoload_runtime.php ]; then
	echo "Waiting for Composer vendor (autoload_runtime.php)..."
	i=0
	while [ ! -f /app/vendor/autoload_runtime.php ]; do
		i=$((i + 1))
		if [ "$i" -gt 180 ]; then
			echo "Timed out waiting for /app/vendor/autoload_runtime.php" >&2
			exit 1
		fi
		sleep 1
	done
	echo "Vendor ready."
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
