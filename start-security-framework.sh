#!/usr/bin/env bash

# =============================================================================
# EF.NEXT SECURITY FRAMEWORK — MASTER TEST-SKRIPT
# =============================================================================
#
# Was macht dieses Skript?
# ------------------------
# Führt die komplette Qualitätsprüfung des Frameworks ausschließlich in Docker
# aus. Auf dem Mac wird nichts außer Docker Desktop benötigt – kein PHP, kein
# Composer. Die Schritte laufen in dieser Reihenfolge:
#   1. Docker prüfen
#   2. Datenbanken starten (PostgreSQL 16, MySQL 8.4, MariaDB 11) und auf die
#      Healthchecks warten
#   3. PHP-Image bauen und Composer-Abhängigkeiten installieren (nur wenn nötig)
#   4. Prüfungen ausführen: Selfcheck, Pint, PHPStan und die Testsuite gegen
#      sqlite, pgsql, mysql und mariadb
#
# Vorausgesetzte Verzeichnisstruktur:
#   security-framework/
#     compose.yaml                    ← PHP-Container + drei Datenbanken
#     docker/php/Dockerfile           ← php:8.3-cli mit pdo_pgsql, pdo_mysql, pcntl, pcov
#     bin/composer, bin/test, bin/check ← Bausteine, die dieses Skript orchestriert
#     start-security-framework.sh     ← dieses Skript
#     .claude/                        ← Ausgaben der Läufe (gitignored)
#
# Einmalige Einrichtung:
#   chmod +x start-security-framework.sh
#
# Nutzung:
#   ./start-security-framework.sh                        # = check
#   ./start-security-framework.sh check                  # Selfcheck + Pint + PHPStan + Tests (alle DBs)
#   ./start-security-framework.sh test                   # nur Tests, alle Datenbanken
#   ./start-security-framework.sh test mysql             # nur Tests, eine Datenbank
#   ./start-security-framework.sh test mysql --filter=Chain
#   ./start-security-framework.sh concurrency            # nur die Concurrency-Tests (pgsql, mysql, mariadb)
#   ./start-security-framework.sh matrix                 # Tests unter PHP 8.3, 8.4 und 8.5 (alle Datenbanken)
#   ./start-security-framework.sh mutate                 # Mutation Testing auf dem Sicherheitskern (sqlite)
#   ./start-security-framework.sh load mysql             # Lasttest: viele parallele Writer (AUDIT_LOAD_WRITERS, Standard 48)
#   ./start-security-framework.sh install                # nur Composer install im Container
#   ./start-security-framework.sh shell                  # Bash im PHP-Container (Datenbanken laufen)
#   ./start-security-framework.sh down                   # Container stoppen und entfernen
#   ./start-security-framework.sh help
#
# Kompatibilitäts-Dimensionen (Umgebungsvariablen):
#   PHP_VERSION=8.4 ./start-security-framework.sh check          # anderes PHP-Image (8.3 Standard, 8.4, 8.5)
#   PHP_VERSIONS="8.3 8.5" ./start-security-framework.sh matrix  # Auswahl für den Matrix-Modus
#   DEPS=lowest ./start-security-framework.sh install            # niedrigste erlaubte Abhängigkeiten installieren
#   LARAVEL=13 ./start-security-framework.sh install             # gegen Laravel 13 installieren (Standard: höchste erlaubte)
#   Danach normal 'check' oder 'test' ausführen; 'install' ohne Variablen stellt den Standard wieder her.
#
# Ergebnisse landen in .claude/check-output.txt und .claude/test-output.txt,
# damit sie auch von Tooling (z. B. Claude) gelesen werden können.
# =============================================================================

# 'set -e' bricht das Skript sofort ab, sobald ein Befehl fehlschlägt – etwa
# wenn Docker nicht läuft. Die Prüfschritte selbst werden bewusst ohne
# 'set -e' ausgeführt, damit am Ende eine Zusammenfassung erscheint.
set -e

print_usage() {
    echo "ef.next Security Framework – Qualitätsprüfung in Docker"
    echo ""
    echo "Verwendung:"
    echo "  ./start-security-framework.sh [check]              Selfcheck, Pint, PHPStan und Tests gegen alle Datenbanken"
    echo "  ./start-security-framework.sh test [db] [args]     Tests; db = sqlite | pgsql | mysql | mariadb; args gehen an Pest"
    echo "  ./start-security-framework.sh concurrency          Concurrency-Tests (echte Parallelität) auf pgsql, mysql, mariadb"
    echo "  ./start-security-framework.sh matrix               Tests unter PHP \$PHP_VERSIONS (Standard: 8.3 8.4 8.5), alle Datenbanken"
    echo "  ./start-security-framework.sh mutate [--min=N]     Mutation Testing auf dem Sicherheitskern (Pest, sqlite)"
    echo "  ./start-security-framework.sh load [db]            Lasttest mit AUDIT_LOAD_WRITERS Writern (Standard 48) auf einer Datenbank"
    echo "  ./start-security-framework.sh install              Composer install im Container (DEPS=lowest, LARAVEL=13 möglich)"
    echo "  ./start-security-framework.sh example [db]         Beispiel-App (examples/laravel-app) frisch aufsetzen und testen; db = sqlite | pgsql | mysql | mariadb"
    echo "  ./start-security-framework.sh boost                Laravel Boost in einer frischen App: lädt es Richtlinie und Skill des Frameworks?"
    echo "  ./start-security-framework.sh shell                Bash im PHP-Container"
    echo "  ./start-security-framework.sh down                 Container stoppen und entfernen"
    echo "  ./start-security-framework.sh help                 Diese Hilfe"
    echo ""
    echo "Beispiele:"
    echo "  ./start-security-framework.sh test mysql --group=concurrency"
    echo "  ./start-security-framework.sh test sqlite --filter=BelongsToTenant"
    echo "  PHP_VERSION=8.5 ./start-security-framework.sh check"
    echo "  LARAVEL=13 ./start-security-framework.sh install && ./start-security-framework.sh test"
    echo "  ./start-security-framework.sh example pgsql"
}

MODE="${1:-check}"
if [ "$#" -gt 0 ]; then
    shift
fi

case "$MODE" in
    check|test|concurrency|matrix|mutate|load|install|shell|example|boost|down)
        ;;
    help|-h|--help)
        print_usage
        exit 0
        ;;
    *)
        echo "❌ Unbekannter Modus: $MODE"
        echo ""
        print_usage
        exit 1
        ;;
esac

# =============================================================================
# KONFIGURATION
# =============================================================================
# '$(dirname "$0")' ist das Verzeichnis dieses Skripts (= das Repository).
# Alle Docker-Befehle laufen von dort, damit compose.yaml gefunden wird.

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$REPO_DIR"

# Datenbanken mit Healthcheck in compose.yaml; sqlite läuft im PHP-Container.
DATABASES="pgsql mysql mariadb"
ALL_TARGETS="sqlite pgsql mysql mariadb"

# Kompatibilitäts-Dimensionen; compose.yaml liest PHP_VERSION für das Image.
export PHP_VERSION="${PHP_VERSION:-8.3}"
PHP_VERSIONS="${PHP_VERSIONS:-8.3 8.4 8.5}"
DEPS="${DEPS:-highest}"        # highest | lowest
LARAVEL="${LARAVEL:-}"         # leer = höchste erlaubte Version, sonst z. B. 12 oder 13

# Ausgabedateien (gitignored)
OUTPUT_DIR=".claude"
CHECK_OUTPUT="$OUTPUT_DIR/check-output.txt"
TEST_OUTPUT_FILE="$OUTPUT_DIR/test-output.txt"
CONCURRENCY_OUTPUT="$OUTPUT_DIR/concurrency-output.txt"

mkdir -p "$OUTPUT_DIR"

# Die bin/-Skripte laufen im Container direkt (bin/check, bin/test, ...). Ein Kopiervorgang,
# der Dateimodi verliert (Sync-Tools, manche Editoren), endet sonst in "Permission denied".
chmod +x bin/* examples/laravel-app/install.sh 2>/dev/null || true

# =============================================================================
# MODUS: DOWN
# =============================================================================
# Stoppt und entfernt die Container dieses Projekts. Die Datenbanken laufen
# auf tmpfs, es gehen also keine Daten verloren, die man behalten wollte.

if [ "$MODE" = "down" ]; then
    echo "▶ Stoppe Container des Security Frameworks..."
    docker compose down --remove-orphans
    echo "  ✅ Container entfernt."
    exit 0
fi

echo "============================================================"
echo "  🔐 EF.NEXT SECURITY FRAMEWORK — $MODE"
echo "  📁 $REPO_DIR"
echo "  🐘 PHP $PHP_VERSION"
echo "============================================================"

# =============================================================================
# SCHRITT 1: DOCKER PRÜFEN
# =============================================================================
# 'docker info' schlägt fehl, wenn Docker Desktop nicht läuft. 'docker compose'
# (v2, mit Leerzeichen) wird benötigt; das alte 'docker-compose' reicht nicht,
# weil '--wait' fehlt.

echo ""
echo "▶ [1/4] Prüfe Docker..."

if ! command -v docker >/dev/null 2>&1; then
    echo "  ❌ 'docker' nicht gefunden. Bitte Docker Desktop installieren."
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "  ❌ Docker läuft nicht. Bitte Docker Desktop starten und erneut versuchen."
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "  ❌ 'docker compose' (v2) nicht verfügbar."
    exit 1
fi

echo "  ✅ Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '?') / $(docker compose version --short 2>/dev/null | sed 's/^/compose /')"

# =============================================================================
# SCHRITT 2: DATENBANKEN STARTEN
# =============================================================================
# 'docker compose up -d --wait' startet die Datenbanken im Hintergrund und
# kehrt erst zurück, wenn alle Healthchecks grün sind (pg_isready,
# mysqladmin ping, healthcheck.sh). Mehrfaches Ausführen ist unschädlich.
#
# MySQL und MariaDB laufen mit --log-bin-trust-function-creators=1, damit die
# Audit-Trigger ohne SUPER-Rechte angelegt werden können.

echo ""
echo "▶ [2/4] Starte Datenbanken (PostgreSQL 16, MySQL 8.4, MariaDB 11)..."

# shellcheck disable=SC2086
if ! docker compose up -d --wait --quiet-pull $DATABASES; then
    echo "  ❌ Mindestens eine Datenbank wurde nicht gesund. Status:"
    docker compose ps
    exit 1
fi

echo "  ✅ Datenbanken laufen und sind gesund."

# =============================================================================
# SCHRITT 3: PHP-IMAGE UND COMPOSER-ABHÄNGIGKEITEN
# =============================================================================
# Das PHP-Image (docker/php/Dockerfile) wird beim ersten Mal gebaut (einige
# Minuten, danach aus dem Cache). Composer läuft ausschließlich im Container;
# vendor/ liegt trotzdem im Repository-Ordner, weil er als Volume gemountet
# ist – die IDE sieht die Klassen also.
#
# Installiert wird nur, wenn vendor/ fehlt oder composer.json neuer ist als
# der letzte Installationsstand.

echo ""
echo "▶ [3/4] Prüfe PHP-Image und Abhängigkeiten..."

docker compose build --quiet php
echo "  ✅ PHP-Image bereit."

needs_install=false
if [ ! -f vendor/autoload.php ] || [ ! -f vendor/composer/installed.json ]; then
    needs_install=true
elif [ composer.json -nt vendor/composer/installed.json ]; then
    needs_install=true
fi

if [ "$MODE" = "install" ]; then
    needs_install=true
fi

if [ "$needs_install" = true ]; then
    if [ "$DEPS" = "lowest" ] || [ -n "$LARAVEL" ]; then
        # Abweichende Auflösung für die Kompatibilitätsmatrix: niedrigste erlaubte
        # Versionen und/oder eine bestimmte Laravel-Hauptversion (über --with, ohne
        # composer.json anzufassen). 'install' ohne Variablen stellt den Standard her.
        update_args="--no-interaction --prefer-dist --no-progress"
        if [ "$DEPS" = "lowest" ]; then
            update_args="$update_args --prefer-lowest --prefer-stable"
        fi
        if [ -n "$LARAVEL" ]; then
            update_args="$update_args --with laravel/framework:^$LARAVEL.0"
        fi
        echo "  ℹ️  Installiere Abhängigkeiten (DEPS=$DEPS${LARAVEL:+, Laravel $LARAVEL})..."
        # shellcheck disable=SC2086
        bin/composer update $update_args
    else
        echo "  ℹ️  Installiere Composer-Abhängigkeiten im Container..."
        bin/composer install --no-interaction --prefer-dist --no-progress
    fi
    echo "  ✅ Abhängigkeiten installiert."
else
    echo "  ✅ Abhängigkeiten sind aktuell."
fi

if [ "$MODE" = "install" ]; then
    exit 0
fi

# =============================================================================
# MODUS: SHELL
# =============================================================================
# Interaktive Bash im PHP-Container mit laufenden Datenbanken, z. B. für
#   vendor/bin/pest --filter=Concurrency     (DB_CONNECTION=mysql vorangestellt)
#   vendor/bin/phpstan analyse

if [ "$MODE" = "shell" ]; then
    echo ""
    echo "▶ Öffne Bash im PHP-Container (exit zum Beenden)..."
    exec docker compose run --rm -e DB_CONNECTION="${DB_CONNECTION:-sqlite}" php bash
fi

# =============================================================================
# SCHRITT 4: PRÜFUNGEN AUSFÜHREN
# =============================================================================
# Ab hier kein 'set -e' mehr: jeder Schritt wird ausgeführt und am Ende
# zusammengefasst, damit man alle Befunde auf einmal sieht.
set +e

overall=0

# Kleine Helfer für die Ergebnisanzeige
report() {
    # $1 = Name, $2 = Exit-Code
    if [ "$2" -eq 0 ]; then
        echo "  ✅ $1"
    else
        echo "  ❌ $1 (Exit-Code $2)"
        overall=1
    fi
}

echo ""
case "$MODE" in
    check)
        echo "▶ [4/4] Selfcheck, Pint, PHPStan und Tests..."
        echo ""

        # Framework-freie Invarianten (Hashing, Sanitizer, Identifier) – läuft
        # ohne Composer in Sekunden und deckt die kryptographischen Kernpfade ab.
        echo "--- selfcheck"
        docker compose run --rm --no-deps php php bin/selfcheck 2>&1 | tee "$OUTPUT_DIR/selfcheck-output.txt"
        selfcheck_rc=${PIPESTATUS[0]}

        # bin/check = Pint (--test), PHPStan Level 8, danach bin/test über alle
        # Datenbanken. Schreibt check-output.txt und test-output.txt.
        echo ""
        echo "--- pint, phpstan, tests"
        bin/check
        check_rc=$?

        echo ""
        echo "============================================================"
        echo "  📋 ZUSAMMENFASSUNG"
        echo "============================================================"
        report "Selfcheck" "$selfcheck_rc"
        report "Pint + PHPStan + Tests ($ALL_TARGETS)" "$check_rc"
        ;;

    test)
        target="${1:-}"
        if [ -n "$target" ] && [ "${target#--}" = "$target" ]; then
            shift
            echo "▶ [4/4] Tests gegen $target..."
            bin/test "$target" "$@"
            report "Tests ($target)" "$?"
        else
            echo "▶ [4/4] Tests gegen $ALL_TARGETS..."
            bin/test "$@"
            report "Tests ($ALL_TARGETS)" "$?"
        fi
        ;;

    matrix)
        # Dieselbe Testsuite unter mehreren PHP-Versionen. Jede Version bekommt
        # ihr eigenes Image (compose taggt nach PHP_VERSION); vendor/ wird geteilt,
        # weil die Abhängigkeiten für alle drei Versionen dieselben sind.
        echo "▶ [4/4] Tests unter PHP $PHP_VERSIONS..."
        for version in $PHP_VERSIONS; do
            echo ""
            echo "--- PHP $version"
            if ! PHP_VERSION="$version" docker compose build --quiet php; then
                report "PHP $version (Image-Build)" 1
                continue
            fi
            PHP_VERSION="$version" TEST_OUTPUT="$OUTPUT_DIR/matrix-php$version.txt" bin/test
            report "PHP $version ($ALL_TARGETS)" "$?"
        done
        ;;

    mutate)
        # Mutation Testing: Pest verändert den Quellcode gezielt (Operatoren,
        # Rückgaben, Bedingungen) und prüft, ob die Tests es merken. Überlebende
        # Mutanten sind ungetestetes Verhalten. Läuft auf sqlite mit pcov.
        echo "▶ [4/4] Mutation Testing auf dem Sicherheitskern..."
        docker compose run --rm --no-deps -e DB_CONNECTION=sqlite php bin/mutate "$@" 2>&1 | tee "$OUTPUT_DIR/mutation-output.txt"
        report "Mutation Testing" "${PIPESTATUS[0]}"
        ;;

    load)
        # Lasttest: deutlich mehr Writer als CPU-Kerne, zwei Ketten, gemischte
        # Transaktionsstile. Standard 48 Writer x 20 Events; über
        # AUDIT_LOAD_WRITERS / AUDIT_LOAD_EVENTS steuerbar.
        target="${1:-mysql}"
        echo "▶ [4/4] Lasttest auf $target (AUDIT_LOAD_WRITERS=${AUDIT_LOAD_WRITERS:-48}, AUDIT_LOAD_EVENTS=${AUDIT_LOAD_EVENTS:-20})..."
        AUDIT_LOAD_WRITERS="${AUDIT_LOAD_WRITERS:-48}" AUDIT_LOAD_EVENTS="${AUDIT_LOAD_EVENTS:-20}" \
            TEST_OUTPUT="$OUTPUT_DIR/load-$target.txt" bin/test "$target" --group=load
        report "Lasttest ($target)" "$?"
        ;;

    concurrency)
        # Echte Parallelität: pcntl_fork im Container, mehrere Prozesse
        # schreiben gleichzeitig in dieselbe Audit-Kette. Genau der Fall, der
        # auf InnoDB früher Deadlocks erzeugt hat. SQLite kann das nicht.
        # Jede Datenbank schreibt ihre eigene Datei; concurrency-output.txt
        # fasst alle drei zusammen (bin/test würde sich sonst überschreiben).
        echo "▶ [4/4] Concurrency-Tests auf $DATABASES..."
        : > "$CONCURRENCY_OUTPUT"
        for db in $DATABASES; do
            echo ""
            echo "--- $db"
            TEST_OUTPUT="$OUTPUT_DIR/concurrency-$db.txt" bin/test "$db" --group=concurrency
            rc=$?
            cat "$OUTPUT_DIR/concurrency-$db.txt" >> "$CONCURRENCY_OUTPUT"
            report "Concurrency ($db)" "$rc"
        done
        ;;

    example)
        # Beispiel-App: examples/laravel-app/install.sh legt in build/example-app eine
        # frische Laravel-12-Anwendung an, kopiert die Beispieldateien darüber, installiert
        # das Framework aus diesem Repository (path repository) und lässt Tests, anchor,
        # verify und doctor laufen. Genau das macht auch der CI-Job "example".
        # build/ ist gitignored; ein vorhandenes build/example-app wird vorher entfernt.
        target="${1:-sqlite}"
        echo "▶ [4/4] Beispiel-App auf $target..."
        rm -rf build/example-app
        if [ "$target" = "sqlite" ]; then
            docker compose run --rm --no-deps -e DB_CONNECTION=sqlite php examples/laravel-app/install.sh /app/build/example-app 2>&1 | tee "$OUTPUT_DIR/example-output.txt"
        else
            docker compose run --rm -e DB_CONNECTION="$target" php examples/laravel-app/install.sh /app/build/example-app 2>&1 | tee "$OUTPUT_DIR/example-output.txt"
        fi
        report "Beispiel-App ($target)" "${PIPESTATUS[0]}"
        ;;

    boost)
        # Laravel Boost: bin/boost-check legt in build/boost-app eine frische Laravel-App an,
        # installiert das Framework (path repository) und laravel/boost, führt
        # boost:install --guidelines --skills ohne Rückfragen aus (Agenten Claude Code und
        # Codex über boost.json) und prüft AGENTS.md, die Skills und boost:update.
        echo "▶ [4/4] Laravel-Boost-Integration..."
        rm -rf build/boost-app
        docker compose run --rm --no-deps php bin/boost-check /app/build/boost-app 2>&1 | tee "$OUTPUT_DIR/boost-output.txt"
        report "Laravel Boost" "${PIPESTATUS[0]}"
        ;;
esac

echo ""
if [ "$MODE" = "concurrency" ]; then
    echo "  📄 Ausgaben: $CONCURRENCY_OUTPUT (alle), $OUTPUT_DIR/concurrency-<db>.txt (einzeln)"
elif [ "$MODE" = "matrix" ]; then
    echo "  📄 Ausgaben: $OUTPUT_DIR/matrix-php<version>.txt"
elif [ "$MODE" = "mutate" ]; then
    echo "  📄 Ausgaben: $OUTPUT_DIR/mutation-output.txt"
elif [ "$MODE" = "load" ]; then
    echo "  📄 Ausgaben: $OUTPUT_DIR/load-<db>.txt"
elif [ "$MODE" = "boost" ]; then
    echo "  📄 Ausgaben: $OUTPUT_DIR/boost-output.txt, Anwendung in build/boost-app"
elif [ "$MODE" = "example" ]; then
    echo "  📄 Ausgaben: $OUTPUT_DIR/example-output.txt, Anwendung in build/example-app"
else
    echo "  📄 Ausgaben: $CHECK_OUTPUT, $TEST_OUTPUT_FILE"
fi
if [ "$overall" -eq 0 ]; then
    echo "  🎉 Alles grün."
else
    echo "  ⚠️  Es gibt Befunde – Details in den Ausgabedateien."
fi

exit "$overall"
