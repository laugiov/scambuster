#!/usr/bin/env bash
#
# Validate the exported STIX bundle types against the OASIS-community
# stix2-validator (Spec 005 FR-001, Constitution V).
#
# Self-validation convinces nobody in a standards context. The project already
# checks its own extension schemas at build time; this script is the independent
# half — a third-party validator, and the OASIS schemas themselves, reading the
# same bundles a consumer would receive.
#
# Errors fail the run. Warnings are printed and do not: several are inherent to
# design decisions this project made deliberately and documents in
# docs/standards/interoperability-conformance.md — most notably that STIX ids are
# UUIDv5 rather than UUIDv4, which is what makes re-imports deduplicate.
#
# Two packaging details this script works around, both upstream issues rather than
# anything about this repository:
#   1. stix2-validator depends on `cpe`, whose setup.py fails to build under
#      setuptools >= 67. The virtualenv pins an older setuptools.
#   2. The published wheel does not ship the STIX JSON schemas — they live in a git
#      submodule — so the validator cannot find a schema for any object type. They
#      are cloned and dropped into the layout the validator expects.
#
# Bundle generation runs inside the backend-dev container when the stack is up,
# which is the supported path and the one CI takes. The host fallback boots the
# Symfony kernel directly and therefore needs the application's database reachable
# — so on a machine with no stack running, start the stack rather than expecting
# the fallback to work.
#
# Usage:
#   scripts/standards/validate-stix-bundles.sh [--keep-bundles DIR]
#
# Exit codes: 0 all bundles valid, 1 at least one invalid, 2 could not run.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

VENV_DIR="${STIX_VALIDATOR_VENV:-$REPO_ROOT/var/stix-validator}"
SCHEMA_REPO="https://github.com/oasis-open/cti-stix2-json-schemas"

# Pinned. A conformance gate that silently follows upstream turns a green build
# red with no change on our side, and nobody can tell a real regression from a
# new upstream release. Bump these deliberately, and re-run the gate when you do.
VALIDATOR_VERSION="3.3.1"
SCHEMA_REVISION="9af1db41b7b86c06324f899649ae83480134f66e"
BUNDLE_DIR="$(mktemp -d)"
KEEP_DIR=""

while [ $# -gt 0 ]; do
    case "$1" in
        --keep-bundles)
            KEEP_DIR="${2:?--keep-bundles needs a directory}"
            shift 2
            ;;
        *)
            echo "unknown argument: $1" >&2
            exit 2
            ;;
    esac
done

cleanup() {
    if [ -n "$KEEP_DIR" ] && compgen -G "$BUNDLE_DIR/*.json" >/dev/null; then
        mkdir -p "$KEEP_DIR"
        cp "$BUNDLE_DIR"/*.json "$KEEP_DIR"/
        echo "Bundles kept in $KEEP_DIR"
    fi
    rm -rf "$BUNDLE_DIR"
}
trap cleanup EXIT

# ---------------------------------------------------------------- validator ---

if [ ! -x "$VENV_DIR/bin/stix2_validator" ]; then
    echo "Installing stix2-validator into $VENV_DIR ..."
    python3 -m venv "$VENV_DIR"
    # cpe (a transitive dependency) cannot build under modern setuptools.
    "$VENV_DIR/bin/pip" install --quiet "setuptools<67" wheel
    "$VENV_DIR/bin/pip" install --quiet "stix2-validator==$VALIDATOR_VERSION"
fi

VALIDATOR_PKG="$("$VENV_DIR/bin/python" -c 'import os, stix2validator; print(os.path.dirname(stix2validator.__file__))')"
SCHEMA_DIR="$VALIDATOR_PKG/schemas-2.1/schemas"

if [ ! -d "$SCHEMA_DIR" ]; then
    echo "Fetching the OASIS STIX 2.1 JSON schemas ..."
    SCHEMA_CLONE="$(mktemp -d)"
    git init --quiet "$SCHEMA_CLONE"
    git -C "$SCHEMA_CLONE" remote add origin "$SCHEMA_REPO"
    git -C "$SCHEMA_CLONE" fetch --quiet --depth 1 origin "$SCHEMA_REVISION"
    git -C "$SCHEMA_CLONE" checkout --quiet FETCH_HEAD
    mkdir -p "$VALIDATOR_PKG/schemas-2.1"
    cp -r "$SCHEMA_CLONE/schemas" "$VALIDATOR_PKG/schemas-2.1/"
    rm -rf "$SCHEMA_CLONE"
fi

# ------------------------------------------------------------------ bundles ---

echo "Building conformance fixture bundles ..."

# The bundles are built by PHP and validated by Python. The container is tried
# first, and deliberately so: it is the environment the application is configured
# for, and it bind-mounts the project, so whatever it writes is visible here.
#
# Preferring the host would be wrong even where it looks available. The bind mount
# means backend-symfony/vendor/ exists on the host as soon as the container has
# installed it, and `php` exists on a CI runner — so a naive host-first check picks
# a PHP with no app environment and a var/ owned by the container's user, and fails
# on a cache directory it cannot create.
#
# The host path stays as the fallback for a machine with dependencies installed
# natively and no stack running.
CONFORMANCE_DIR="$REPO_ROOT/backend-symfony/var/conformance"

container_running() {
    command -v docker >/dev/null 2>&1 \
        && [ -n "$(docker compose ps -q backend-dev 2>/dev/null)" ]
}

if container_running; then
    docker compose exec -T backend-dev bin/console scambuster:stix:conformance-fixtures --quiet
elif [ -f "$REPO_ROOT/backend-symfony/vendor/autoload.php" ] && command -v php >/dev/null 2>&1; then
    (cd "$REPO_ROOT/backend-symfony" && php bin/console scambuster:stix:conformance-fixtures --quiet)
else
    echo "error: need a running backend-dev container, or PHP with the dependencies installed." >&2
    exit 2
fi

if ! compgen -G "$CONFORMANCE_DIR/*.json" >/dev/null; then
    echo "error: no bundles were produced in $CONFORMANCE_DIR" >&2
    exit 2
fi

cp "$CONFORMANCE_DIR"/*.json "$BUNDLE_DIR/"

# ----------------------------------------------------------------- validate ---

FAILED=0

for bundle in "$BUNDLE_DIR"/*.json; do
    name="$(basename "$bundle")"
    echo ""
    echo "── $name ──────────────────────────────────────────────"

    if output="$("$VENV_DIR/bin/stix2_validator" --version 2.1 "$bundle" 2>&1)"; then
        echo "$output" | grep -E '^\s*\[!\]' || true
        echo "VALID"
    else
        echo "$output"
        echo "INVALID"
        FAILED=1
    fi
done

echo ""

if [ "$FAILED" -ne 0 ]; then
    echo "FAIL: at least one bundle does not conform to STIX 2.1."
    echo "See docs/standards/interoperability-conformance.md for what each claim rests on."
    exit 1
fi

# ---------------------------------------------------------------- self-test ---
# A gate that cannot fail is not a gate. Break a bundle on purpose and confirm the
# validator rejects it, so a green run means the check ran rather than that the
# validator silently degraded to a no-op (Spec 005 SC-001).

echo "── gate self-test ──────────────────────────────────────────"

BROKEN="$BUNDLE_DIR/deliberately-broken.json"
python3 - "$BUNDLE_DIR/ioc-bundle.json" "$BROKEN" <<'PY'
import json, sys

with open(sys.argv[1], encoding="utf-8") as handle:
    bundle = json.load(handle)

# Drop `pattern` from an indicator. STIX 2.1 makes it required, so its absence is an
# unambiguous spec-level error rather than a best-practice warning — which is what
# makes a rejection here proof that the gate checks the spec and not just JSON syntax.
for obj in bundle["objects"]:
    if obj.get("type") == "indicator":
        del obj["pattern"]
        break

with open(sys.argv[2], "w", encoding="utf-8") as handle:
    json.dump(bundle, handle)
PY

if "$VENV_DIR/bin/stix2_validator" --version 2.1 "$BROKEN" >/dev/null 2>&1; then
    echo "FAIL: the validator accepted a bundle with a required property removed."
    echo "The conformance gate is not actually checking anything."
    exit 1
fi

echo "OK: the validator rejects a deliberately broken bundle."
echo ""
echo "OK: every exported bundle type passes the external STIX 2.1 validator."
exit 0
