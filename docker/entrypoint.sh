#!/bin/sh
set -e

# php-fpm serves requests as the www-data user, but /app is owned by root (the
# image layer) and /var/lib/cr-dashboard is a named volume that inherits root
# ownership. Without write access, Symfony cannot create its cache/log dir on
# the first request ("Unable to create the cache directory"), and the SQLite
# wrapper cannot create the database file. Fix both at runtime as root before
# starting the daemons; the data volume may already exist from a prior run, so
# chown -R handles any files it already holds.
mkdir -p /app/var/cache /app/var/log
chown -R www-data:www-data /app/var
chown -R www-data:www-data /var/lib/cr-dashboard

# Materialize the runtime environment as /app/.env, the base file Symfony's
# runtime boots from (autoload_runtime.php -> Dotenv::bootEnv('/app/.env')).
# bootEnv throws a PathException if neither /app/.env nor /app/.env.dist exists,
# even when the vars are already in the process env, so the file is required.
# It also covers cron-run console commands: dcron strips the container env for
# jobs, so they rely on this file rather than inheriting it from php-fpm.
cat > /app/.env <<EOF
APP_ENV=${APP_ENV:-prod}
APP_DEBUG=${APP_DEBUG:-0}
APP_SECRET=${APP_SECRET:-change-me}
DATABASE_PATH=${DATABASE_PATH:-/var/lib/cr-dashboard/dashboard.sqlite}
GITLAB_URL=${GITLAB_URL:-}
GITLAB_GROUP=${GITLAB_GROUP:-}
GITLAB_TOKEN=${GITLAB_TOKEN:-}
GITLAB_RPS=${GITLAB_RPS:-8}
GITLAB_PROJECTS=${GITLAB_PROJECTS:-}
RETENTION_DAYS=${RETENTION_DAYS:-90}
REQUIRED_APPROVALS=${REQUIRED_APPROVALS:-2}
JIRA_URL=${JIRA_URL:-}
SLACK_TOKEN=${SLACK_TOKEN:-}
SLACK_CHANNEL=${SLACK_CHANNEL:-}
APP_URL=${APP_URL:-}
MERCURE_URL=${MERCURE_URL:-http://127.0.0.1:3000/.well-known/mercure}
MERCURE_JWT_SECRET=${MERCURE_JWT_SECRET:-change-me}
EOF

# Exported (rather than only written to .env) so supervisord's mercure program
# can read it via %(ENV_MERCURE_JWT_SECRET)s and sign/verify with the same
# secret as the PHP-side publisher (App\Mercure\HmacTokenProvider).
export MERCURE_JWT_SECRET=${MERCURE_JWT_SECRET:-change-me}

# Create/upgrade the schema from the Doctrine migrations before any daemon
# starts. Runs as www-data so the SQLite file and doctrine_migration_versions
# stay writable by php-fpm and the cron workers; a no-op on restarts once the
# baseline version is recorded.
su-exec www-data php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Start nginx, php-fpm and cron under supervisor.
exec /usr/bin/supervisord -c /etc/supervisord.conf
