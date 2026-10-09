#!/usr/bin/env bash
#
# The PHP floor guard, asserted against a real archive on a real old PHP.
#
# **This script is only meaningful when run on a PHP below the floor.** On a
# supported PHP the binary works, so every assertion here fails, and that is
# correct rather than a bug: CI runs it in a job pinned to 8.1 and nowhere
# else. Run locally on a modern PHP it will tell you it expected a refusal and
# did not get one.
#
# What it exists to prove, which no unit test can: the guard wins the race. The
# Homebrew formula installs no interpreter, so the binary runs on whatever PHP
# the user has, and the entry point's check must happen before the Composer
# autoloader parses a class file written for a newer PHP. If it loses that race
# the user gets a parse error from inside Illuminate, naming a file they have
# never heard of, after a `brew install` that reported success.
#
# Usage: build/floor.sh <path-to-truss.phar>

# Deliberately not -e: a non-zero exit from the binary is the expected result.
set -uo pipefail

PHAR="${1:?usage: build/floor.sh <path-to-truss.phar>}"
FLOOR='8.2'
RUNNING="$(php -r 'echo PHP_VERSION;')"

if [ ! -f "$PHAR" ]; then
    echo "FAIL: no PHAR at $PHAR" >&2
    exit 1
fi

echo "Asserting the floor guard on PHP ${RUNNING} (floor is ${FLOOR})"

OUTPUT="$(php "$PHAR" --version 2>&1)"
STATUS=$?

fail() {
    echo "  FAIL  $1" >&2
    echo "  exit status: ${STATUS}" >&2
    echo "  output:" >&2
    echo "$OUTPUT" | sed 's/^/      /' >&2
    exit 1
}

[ "$STATUS" -ne 0 ] || fail 'the binary ran: this lane must be run on a PHP below the floor'

case "$OUTPUT" in
    *"$FLOOR"*) ;;
    *) fail "the refusal does not name the required version (${FLOOR})" ;;
esac

case "$OUTPUT" in
    *"$RUNNING"*) ;;
    *) fail "the refusal does not name the version found (${RUNNING})" ;;
esac

case "$OUTPUT" in
    *Truss*) ;;
    *) fail 'the refusal does not say which tool is refusing' ;;
esac

# The whole point: our sentence, not somebody else's crash. A parse error or a
# stack trace here means the autoloader got there first.
for noise in 'Fatal error' 'Parse error' 'Stack trace' 'Illuminate' 'vendor/'; do
    case "$OUTPUT" in
        *"$noise"*) fail "the output contains '${noise}', so something failed before the guard could speak" ;;
    esac
done

echo "  ok  refused with: ${OUTPUT}"
echo
echo '1 check passed'
