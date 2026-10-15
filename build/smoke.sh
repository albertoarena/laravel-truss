#!/usr/bin/env bash
#
# The PHAR smoke lane.
#
# A built PHAR cannot be exercised in-process, so this shells out to it, and it
# lives in a script rather than inline in a workflow for the same reason the
# diff renderer lives in a class rather than in a command: the testable part
# should not sit inside the part nobody can run. A developer can run this after
# a local compile, and CI runs exactly the same thing.
#
# Without this lane, the first thing anybody runs is the first thing nobody
# tested. Homebrew's own `test do` block is a third, weaker check and does not
# replace it.
#
# Usage: build/smoke.sh <path-to-truss.phar> [expected-version]
#
# The expected version is passed on a tagged build, where --version must report
# the tag exactly. Omitted, the version is only required to be non-empty and
# not the unreplaced placeholder.

set -euo pipefail

PHAR="${1:?usage: build/smoke.sh <path-to-truss.phar> [expected-version]}"
EXPECTED="${2:-}"

if [ ! -f "$PHAR" ]; then
    echo "FAIL: no PHAR at $PHAR" >&2
    exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0

ok() {
    PASS=$((PASS + 1))
    echo "  ok  $1"
}

fail() {
    echo "  FAIL  $1" >&2
    exit 1
}

# Two databases, differing in ways each command has something to say about: an
# unindexed foreign key for the doctor, an extra table and column for the diff,
# and one row of data that must never appear in any output.
php -r '
$prod = new PDO("sqlite:" . $argv[1] . "/prod.sqlite");
$prod->exec("create table users (id integer primary key, email text not null)");
$prod->exec("create table orders (id integer primary key, user_id integer references users(id), total integer)");
$prod->exec("insert into users (email) values (\"canary@example.invalid\")");
$stage = new PDO("sqlite:" . $argv[1] . "/staging.sqlite");
$stage->exec("create table users (id integer primary key, email text not null, nickname text)");
$stage->exec("create table orders (id integer primary key, user_id integer references users(id), total integer)");
$stage->exec("create index orders_user_id_index on orders (user_id)");
$stage->exec("create table feature_flags (id integer primary key, name text)");
' "$WORK"

PROD="sqlite:$WORK/prod.sqlite"
STAGE="sqlite:$WORK/staging.sqlite"

echo "Smoking $PHAR on PHP $(php -r 'echo PHP_VERSION;')"

# --- the version, which is the one thing only a build can get right ----------

VERSION="$(php "$PHAR" --version)"

[ -n "$VERSION" ] || fail '--version printed nothing'

# An unreplaced token means the build did not stamp the version, which is a
# broken build that otherwise passes every other check in this file.
case "$VERSION" in
    *@truss_version@*) fail "--version reports an unreplaced placeholder: $VERSION" ;;
esac

if [ -n "$EXPECTED" ]; then
    case "$VERSION" in
        *"$EXPECTED"*) ok "--version reports the tag: $VERSION" ;;
        *) fail "--version is '$VERSION', expected it to contain '$EXPECTED'" ;;
    esac
else
    ok "--version reports $VERSION"
fi

# --- the four commands -------------------------------------------------------

php "$PHAR" show --dsn="$PROD" | grep -q 'orders' || fail 'show did not list a table'
ok 'show lists tables'

php "$PHAR" export --dsn="$PROD" --format=dbml | grep -q 'Table orders' || fail 'dbml export is empty'
ok 'export writes DBML'

php "$PHAR" export --dsn="$PROD" --format=json | php -r 'json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);' \
    || fail 'json export is not valid JSON'
ok 'export writes valid JSON'

php "$PHAR" diff --dsn="$STAGE" --against="$PROD" | grep -q 'feature_flags' || fail 'diff missed an added table'
ok 'diff compares two live databases'

# The HTML document is the path that needs resources/ bundled, Blade compiled to
# a writable directory and the view namespace registered, and all three fail at
# runtime rather than at build time.
# Guarded, and the real error is printed before the verdict: unguarded, a
# missing resources/ aborts the script under set -e with Symfony's usage dump
# and no indication of which check was running.
if ! php "$PHAR" export --dsn="$PROD" --format=html --output="$WORK/schema.html" > "$WORK/html.log" 2>&1; then
    sed 's/^/      /' "$WORK/html.log" >&2
    fail 'html export did not complete: is resources/ bundled into the archive?'
fi

[ -s "$WORK/schema.html" ] || fail 'html export wrote nothing'
grep -q 'data-truss-payload' "$WORK/schema.html" || fail 'html export has no embedded payload'
grep -q 'mermaid' "$WORK/schema.html" || fail 'html export did not inline Mermaid'
ok "html export is self-contained ($(wc -c < "$WORK/schema.html" | tr -d ' ') bytes)"

# --- exit codes, which a CI job reads and cannot ask twice -------------------

# Captured rather than chained: the exact code is the assertion, and a chain
# that only knows "non-zero" cannot tell findings from an unreachable host,
# which is the distinction these three checks exist to hold.
set +e
php "$PHAR" doctor --dsn="$PROD" > /dev/null 2>&1
FINDINGS=$?
php "$PHAR" doctor --dsn="$STAGE" > /dev/null 2>&1
CLEAN=$?
php "$PHAR" doctor --dsn='mysql://u:p@127.0.0.1:1/nothing' > /dev/null 2>&1
UNREACHABLE=$?
set -e

[ "$FINDINGS" -eq 1 ] || fail "doctor exited $FINDINGS on a schema with an error finding, expected 1"
ok 'doctor exits 1 on findings'

[ "$CLEAN" -eq 0 ] || fail "doctor exited $CLEAN on a clean schema, expected 0"
ok 'doctor exits 0 when clean'

[ "$UNREACHABLE" -eq 2 ] || fail "an unreachable database exited $UNREACHABLE, expected 2 (1 means findings)"
ok 'an unreachable database exits 2, not 1'

# --- the promises that are not about working ---------------------------------

if php "$PHAR" show --dsn="$PROD" 2>&1 | grep -q 'canary@example.invalid'; then
    fail 'a row of data reached the output'
fi
if grep -q 'canary@example.invalid' "$WORK/schema.html"; then
    fail 'a row of data reached the html export'
fi
ok 'no row data in any output'

set +e
php "$PHAR" diff --dsn="$PROD" --against='mysql://truss:hunter2@127.0.0.1:1/nothing' 2>&1 | grep -q 'hunter2'
LEAKED=$?
set -e
[ "$LEAKED" -ne 0 ] || fail 'a password appeared in an error message'
ok 'no credentials in error messages'

# The archive itself: what is in it is a release promise too.
php -r '
$phar = new Phar($argv[1]);
$bad = [];
foreach (new RecursiveIteratorIterator($phar) as $file) {
    $path = str_replace("phar://" . realpath($argv[1]) . "/", "", $file->getPathname());
    foreach (["tests/", "/pestphp/", "/orchestra/", "laravel/framework", "/phpunit/"] as $forbidden) {
        if (str_contains($path, $forbidden)) { $bad[] = $path; }
    }
}
if ($bad !== []) { fwrite(STDERR, "archive contains: " . implode(", ", array_slice($bad, 0, 5)) . "\n"); exit(1); }
' "$PHAR" || fail 'the archive carries tests or development dependencies'
ok 'the archive carries no tests and no dev dependencies'

echo
echo "$PASS checks passed"
