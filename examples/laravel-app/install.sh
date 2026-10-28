#!/usr/bin/env bash
# Materialise the example on a fresh Laravel skeleton and prove it works.
#
#   examples/laravel-app/install.sh [target-dir]      default target: build/example-app
#
# What it does, in the order a person (or an agent) would:
#   1. composer create-project laravel/laravel        a stock Laravel 13 application
#   2. copy the files in this directory over it       models, migrations, routes, tests
#   3. composer require efnext/security-framework     from this repository (path repository)
#      + laravel/sanctum                              the example authenticates with tokens
#   4. audit:key:generate -> .env, migrate            keys, tables, triggers
#   5. php artisan test                               the example's own tests
#   6. audit:anchor, audit:verify, security:doctor    the operator's commands
#
# Needs PHP >= 8.3, Composer and network access to Packagist. Inside the framework's
# Docker image:  ./start-security-framework.sh example [pgsql|mysql|mariadb]
#
# Environment:
#   DB_CONNECTION   sqlite (default) | pgsql | mysql | mariadb — the database the
#                   application AND its test suite use (the tests get the same five
#                   DB_* variables explicitly, so a value inherited from the shell can
#                   never half-override .env.testing); on sqlite security:doctor reports
#                   database_driver as FAIL, which is correct and tolerated here.
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, or the framework's
#                   PGSQL_* / MYSQL_* / MARIADB_* variables from compose.yaml.
#   LARAVEL         skeleton major version (default 13).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
TARGET="${1:-$REPO/build/example-app}"
LARAVEL="${LARAVEL:-13}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"

step() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }

if [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ]; then
    echo "Target directory $TARGET exists and is not empty. Remove it or pass another directory." >&2
    exit 1
fi

step "1/6 Creating a Laravel $LARAVEL application in $TARGET"
composer create-project "laravel/laravel:^$LARAVEL.0" "$TARGET" --no-interaction --no-progress --prefer-dist --no-scripts
cd "$TARGET"
[ -f .env ] || cp .env.example .env
php artisan key:generate --ansi --no-interaction

step "2/6 Copying the example files over the skeleton"
(cd "$HERE" && tar --exclude=./install.sh --exclude=./README.md -cf - .) | tar -xf - -C "$TARGET"

step "3/6 Requiring the framework from $REPO and Sanctum"
composer config repositories.security-framework path "$REPO"
composer require "efnext/security-framework:@dev" "laravel/sanctum:^4.0" --no-interaction --no-progress --prefer-dist
php artisan vendor:publish --tag=sanctum-migrations --no-interaction --ansi
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config --no-interaction --ansi

step "4/6 Configuring .env (database: $DB_CONNECTION) and migrating"
# The five DB_* variables the tests run with. Passed explicitly on the command line so
# that neither phpunit.xml (<env> does not override existing variables) nor .env.testing
# (Dotenv never overrides them either) is left with a half-applied mix such as
# DB_CONNECTION=pgsql together with DB_DATABASE=:memory:.
test_env=(DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_HOST= DB_PORT= DB_USERNAME= DB_PASSWORD=)
if [ "$DB_CONNECTION" != "sqlite" ]; then
    prefix="$(printf '%s' "$DB_CONNECTION" | tr '[:lower:]' '[:upper:]')"
    default_port=5432
    [ "$DB_CONNECTION" = "pgsql" ] || default_port=3306
    host="${DB_HOST:-$(eval "printf '%s' \"\${${prefix}_HOST:-127.0.0.1}\"")}"
    port="${DB_PORT:-$(eval "printf '%s' \"\${${prefix}_PORT:-$default_port}\"")}"
    database="${DB_DATABASE:-$(eval "printf '%s' \"\${${prefix}_DATABASE:-security}\"")}"
    username="${DB_USERNAME:-$(eval "printf '%s' \"\${${prefix}_USERNAME:-security}\"")}"
    password="${DB_PASSWORD:-$(eval "printf '%s' \"\${${prefix}_PASSWORD:-secret}\"")}"
    sed -i.bak -e "s/^DB_CONNECTION=.*/DB_CONNECTION=$DB_CONNECTION/" .env && rm -f .env.bak
    {
        echo "DB_HOST=$host"
        echo "DB_PORT=$port"
        echo "DB_DATABASE=$database"
        echo "DB_USERNAME=$username"
        echo "DB_PASSWORD=$password"
    } >> .env
    test_env=(DB_CONNECTION="$DB_CONNECTION" DB_HOST="$host" DB_PORT="$port" DB_DATABASE="$database" DB_USERNAME="$username" DB_PASSWORD="$password")
else
    [ -f database/database.sqlite ] || touch database/database.sqlite
fi
{
    echo ""
    echo "# ef.next Security Framework (see the framework's .env.example for every variable)"
    echo "SECURITY_SERVICE_NAME=billing"
    php artisan audit:key:generate --id=k1 --no-ansi | grep -E '^AUDIT_'
} >> .env
php artisan migrate --force --no-interaction --ansi

step "5/6 Running the example's tests (database: $DB_CONNECTION)"
# RefreshDatabase migrates the configured database from scratch and wraps every test
# in a transaction; on a server database the tests therefore run against the real
# triggers and constraints, not against SQLite's.
env "${test_env[@]}" php artisan test --no-ansi

step "6/6 Operator commands"
php artisan audit:anchor --ansi
php artisan audit:verify --ansi
if [ "$DB_CONNECTION" = "sqlite" ]; then
    # database_driver is a FAIL on SQLite by design (exit 1), so the human-readable run
    # is shown without failing the script; the JSON is captured first (not piped — with
    # pipefail the doctor's exit code would win) and every other check must be OK or WARN.
    php artisan security:doctor --ansi || true
    report="$(php artisan security:doctor --json --no-verify || true)"
    printf '%s' "$report" | php -r '
        $r = json_decode(stream_get_contents(STDIN), true);
        $bad = array_filter($r["checks"] ?? [], fn ($c) => $c["status"] === "fail" && $c["id"] !== "database_driver");
        if ($bad !== []) { fwrite(STDERR, "unexpected doctor failures: ".implode(", ", array_column($bad, "id"))."\n"); exit(1); }
        echo "doctor: only the expected SQLite finding (database_driver) failed\n";'
else
    php artisan security:doctor --ansi
fi

printf '\n\033[1m✅ Example application ready in %s\033[0m\n' "$TARGET"
echo "   php artisan db:seed --class=DemoSeeder      # demo tenants, a user, an API token and curl lines"
echo "   php artisan serve                           # then call the API with the printed headers"
