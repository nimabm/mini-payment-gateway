#!/bin/sh
#
# Everything a first run needs, so that `docker compose up` is the whole
# installation procedure.
set -e

DB_PATH="${DB_PATH:-/var/lib/gateway/gateway.sqlite}"
DB_DIR="$(dirname "$DB_PATH")"

mkdir -p "$DB_DIR"

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty. Generate one with 'make key' and put it in .env." >&2
    echo "It encrypts your gateway credentials — never change it once set." >&2
    exit 1
fi

# Migrations are idempotent, so running them on every boot is safe and means
# an upgrade is just a new image.
php /app/bin/console migrate

# First run only: create an administrator and a demo website so there is
# something to sign into. `seed` skips anything that already exists.
php /app/bin/console seed

# Done last, because the commands above are what create these.
#
#   /var/lib/gateway  SQLite writes the database file *and* creates its -wal
#                     and -shm siblings, so the directory has to belong to the
#                     web user, not just the file.
#   /app/var          the compiled container and the Twig cache, which the
#                     console just wrote as root and Apache is about to write
#                     as www-data.
chown -R www-data:www-data "$DB_DIR" /app/var

exec "$@"
