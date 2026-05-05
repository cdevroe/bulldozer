#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
TMP_DIR="$(mktemp -d /private/tmp/bulldozer-smoke.XXXXXX)"

cleanup() {
    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

assert_file() {
    [ -f "$1" ] || fail "Expected file: $1"
}

assert_not_file() {
    [ ! -f "$1" ] || fail "Expected file to be absent: $1"
}

assert_json_expr() {
    local file="$1"
    local expr="$2"

    "$PHP_BIN" -r '
        $json = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($json)) {
            fwrite(STDERR, "Invalid JSON: {$argv[1]}\n");
            exit(1);
        }
        exit(eval("return " . $argv[2] . ";") ? 0 : 1);
    ' "$file" "$expr" || fail "JSON assertion failed for $file: $expr"
}

cd "$ROOT_DIR"

"$PHP_BIN" -l bulldozer.php >/dev/null

mkdir -p "$TMP_DIR/source/nested" "$TMP_DIR/dest"
printf 'photo' > "$TMP_DIR/source/photo.jpg"
printf 'other' > "$TMP_DIR/source/nested/other.png"
printf 'skip' > "$TMP_DIR/source/skip.txt"
touch -t 202405050505 "$TMP_DIR/source/photo.jpg"
touch -t 202312111314 "$TMP_DIR/source/nested/other.png"

"$PHP_BIN" bulldozer.php \
    --source="$TMP_DIR/source" \
    --destination="$TMP_DIR/dest" \
    --date=mtime \
    --timezone=America/New_York \
    --json \
    --manifest="$TMP_DIR/dry-manifest.json" \
    > "$TMP_DIR/dry.json"

assert_json_expr "$TMP_DIR/dry.json" '$json["ok"] === true'
assert_json_expr "$TMP_DIR/dry.json" '$json["dry_run"] === true'
assert_json_expr "$TMP_DIR/dry.json" '$json["stats"]["eligible"] === 2'
assert_json_expr "$TMP_DIR/dry-manifest.json" 'count($json["actions"]) >= 3'

[ "$(find "$TMP_DIR/dest" -type f | wc -l | tr -d ' ')" = "0" ] || fail "Dry run created files."

cat > "$TMP_DIR/config.json" <<JSON
{
  "source": "$TMP_DIR/source",
  "destination": "$TMP_DIR/dest",
  "date": "mtime",
  "timezone": "America/New_York",
  "manifest_file": "$TMP_DIR/config-manifest.json"
}
JSON

"$PHP_BIN" bulldozer.php \
    --config="$TMP_DIR/config.json" \
    --json \
    > "$TMP_DIR/config-run.json"

assert_json_expr "$TMP_DIR/config-run.json" '$json["ok"] === true'
assert_json_expr "$TMP_DIR/config-run.json" '$json["config_file"] !== null'
assert_json_expr "$TMP_DIR/config-run.json" '$json["stats"]["eligible"] === 2'
assert_json_expr "$TMP_DIR/config-manifest.json" '$json["runtime"]["config_file"] !== null'

"$PHP_BIN" bulldozer.php \
    --source="$TMP_DIR/source" \
    --destination="$TMP_DIR/dest" \
    --date=mtime \
    --timezone=America/New_York \
    --json \
    --manifest="$TMP_DIR/copy-manifest.json" \
    --log="$TMP_DIR/bulldozer.log" \
    --run \
    > "$TMP_DIR/copy.json"

assert_json_expr "$TMP_DIR/copy.json" '$json["ok"] === true'
assert_json_expr "$TMP_DIR/copy.json" '$json["stats"]["copied"] === 2'
assert_file "$TMP_DIR/dest/2024/05/05/photo.jpg"
assert_file "$TMP_DIR/dest/2023/12/11/other.png"
assert_file "$TMP_DIR/bulldozer.log"

"$PHP_BIN" bulldozer.php \
    --source="$TMP_DIR/source" \
    --destination="$TMP_DIR/dest" \
    --date=mtime \
    --timezone=America/New_York \
    --duplicates=skip \
    --json \
    --manifest="$TMP_DIR/skip-manifest.json" \
    --run \
    > "$TMP_DIR/skip.json"

assert_json_expr "$TMP_DIR/skip.json" '$json["ok"] === true'
assert_json_expr "$TMP_DIR/skip.json" '$json["stats"]["duplicates"] === 2'
assert_json_expr "$TMP_DIR/skip.json" '$json["stats"]["copied"] === 0'

mkdir -p "$TMP_DIR/move-source" "$TMP_DIR/move-dest"
printf 'move' > "$TMP_DIR/move-source/move.jpg"
touch -t 202101020304 "$TMP_DIR/move-source/move.jpg"

"$PHP_BIN" bulldozer.php \
    --source="$TMP_DIR/move-source" \
    --destination="$TMP_DIR/move-dest" \
    --mode=move \
    --date=mtime \
    --timezone=America/New_York \
    --json \
    --run \
    > "$TMP_DIR/move.json"

assert_json_expr "$TMP_DIR/move.json" '$json["ok"] === true'
assert_json_expr "$TMP_DIR/move.json" '$json["stats"]["moved"] === 1'
assert_file "$TMP_DIR/move-dest/2021/01/02/move.jpg"
assert_not_file "$TMP_DIR/move-source/move.jpg"

cat > "$TMP_DIR/hooks.php" <<PHP
<?php
return static function (BulldozerHooks \$hooks): void {
    \$hooks->on('file.planned', static function (array \$event): void {
        file_put_contents('$TMP_DIR/events.log', \$event['destination'] . PHP_EOL, FILE_APPEND);
    });
};
PHP

"$PHP_BIN" bulldozer.php \
    --source="$TMP_DIR/source" \
    --destination="$TMP_DIR/dest" \
    --date=mtime \
    --timezone=America/New_York \
    --hooks="$TMP_DIR/hooks.php" \
    --json \
    > "$TMP_DIR/hooks.json"

assert_json_expr "$TMP_DIR/hooks.json" '$json["ok"] === true'
assert_file "$TMP_DIR/events.log"

set +e
"$PHP_BIN" bulldozer.php --json --location=missing > "$TMP_DIR/invalid.json"
STATUS=$?
set -e

[ "$STATUS" -eq 1 ] || fail "Expected validation exit code 1, got $STATUS."
assert_json_expr "$TMP_DIR/invalid.json" '$json["ok"] === false'
assert_json_expr "$TMP_DIR/invalid.json" '$json["exit_code"] === 1'

cat > "$TMP_DIR/bad-config.json" <<JSON
{
  "run": true
}
JSON

set +e
"$PHP_BIN" bulldozer.php --config="$TMP_DIR/bad-config.json" --json > "$TMP_DIR/bad-config-result.json"
STATUS=$?
set -e

[ "$STATUS" -eq 1 ] || fail "Expected bad config exit code 1, got $STATUS."
assert_json_expr "$TMP_DIR/bad-config-result.json" '$json["ok"] === false'

echo "Smoke tests passed."
