#!/bin/sh
set -e

# Write the runtime environment variables to /app/.env.local so that cron-run
# console commands (which do not inherit the container env through php-fpm) see
# them. The container env still feeds php-fpm directly.
cat > /app/.env.local <<EOF
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
EOF

# Start nginx, php-fpm and cron under supervisor.
exec /usr/bin/supervisord -c /etc/supervisord.conf
