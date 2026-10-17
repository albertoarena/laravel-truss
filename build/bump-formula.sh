#!/usr/bin/env bash
#
# The Homebrew formula bump.
#
# Rewrites the two lines of Formula/truss.rb that name a release, the url and
# the sha256, and nothing else. A tap that still names the previous version
# installs the old binary while `brew upgrade` reports nothing to do, so it
# fails silently in both directions; that is why CI does this on every release
# and a human never does.
#
# It lives here rather than inline in the release workflow for the reason
# smoke.sh and floor.sh do: the workflow only runs when a release is cut, and
# this can be run by hand against a checkout of the tap. Given the release the
# formula already pins, it must change nothing, which is the dry run.
#
# Usage: build/bump-formula.sh <path-to-truss.rb> <tag> <sha256>
#
# Each anchor must match exactly one line. Zero means the formula changed shape
# and the bump would silently do nothing; two means it cannot know which one
# ships. Both stop here with the file untouched. It never adds a version line:
# Homebrew reads the version from the url.

set -euo pipefail

FORMULA="${1:-}"
TAG="${2:-}"
SHA="${3:-}"

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

[ -n "$FORMULA" ] || fail 'usage: build/bump-formula.sh <path-to-truss.rb> <tag> <sha256>'
[ -f "$FORMULA" ] || fail "no formula at $FORMULA"

# A plain release, never a prerelease: the tap installs what `releases/latest`
# names, and a suffix in the tag would also be a suffix in the url.
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "tag [$TAG] is not a release version like v1.15.1"
[[ "$SHA" =~ ^[0-9a-f]{64}$ ]] || fail "digest [$SHA] is not 64 lowercase hex characters"

URL_ANCHOR='^  url "https://github\.com/albertoarena/laravel-truss/releases/download/v[^/"]+/truss\.phar"$'
SHA_ANCHOR='^  sha256 "[0-9a-f]{64}"$'

count() {
    grep -cE "$1" "$FORMULA" || true
}

URLS="$(count "$URL_ANCHOR")"
SHAS="$(count "$SHA_ANCHOR")"

[ "$URLS" = 1 ] || fail "expected one url line naming a truss.phar release asset, found $URLS"
[ "$SHAS" = 1 ] || fail "expected one sha256 line, found $SHAS"

# Write a copy and move it over the original only once both edits are made, so
# a failure part way through cannot leave half a bump behind.
NEXT="$(mktemp)"
trap 'rm -f "$NEXT"' EXIT

sed -E \
    -e "s#^(  url \"https://github\.com/albertoarena/laravel-truss/releases/download/)v[^/\"]+(/truss\.phar\")\$#\1${TAG}\2#" \
    -e "s#^(  sha256 \")[0-9a-f]{64}(\")\$#\1${SHA}\2#" \
    "$FORMULA" > "$NEXT"

cat "$NEXT" > "$FORMULA"

echo "  url    ${TAG}"
echo "  sha256 ${SHA}"
