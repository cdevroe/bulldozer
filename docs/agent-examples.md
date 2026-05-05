# Agent Examples

These examples use explicit paths and dry runs so agents can inspect results
before mutating files.

## Dry Run With JSON And Manifest

```sh
php bulldozer.php \
  --source=/tmp/bulldozer/source \
  --destination=/tmp/bulldozer/library \
  --date=mtime \
  --timezone=America/New_York \
  --json \
  --manifest=/tmp/bulldozer/manifest.json
```

Agent checks:

```sh
php -r '$j=json_decode(file_get_contents("/tmp/bulldozer/manifest.json"), true); echo count($j["actions"]) . PHP_EOL;'
```

## Dry Run With Config File

```json
{
  "source": "/tmp/bulldozer/source",
  "destination": "/tmp/bulldozer/library",
  "date": "mtime",
  "timezone": "America/New_York",
  "duplicates": "rename",
  "manifest_file": "/tmp/bulldozer/config-manifest.json"
}
```

```sh
php bulldozer.php --config=/tmp/bulldozer/bulldozer.config.json --json
```

To perform writes, add `--run` on the command line. Do not put `run` in the
config file.

## Approved Copy Run

```sh
php bulldozer.php \
  --source=/tmp/bulldozer/source \
  --destination=/tmp/bulldozer/library \
  --date=mtime \
  --timezone=America/New_York \
  --duplicates=rename \
  --json \
  --manifest=/tmp/bulldozer/copy-manifest.json \
  --log=/tmp/bulldozer/bulldozer.log \
  --run
```

## Approved Move Run

Only use move mode when the user explicitly asked to move files:

```sh
php bulldozer.php \
  --source=/tmp/bulldozer/source \
  --destination=/tmp/bulldozer/library \
  --mode=move \
  --date=mtime \
  --timezone=America/New_York \
  --json \
  --manifest=/tmp/bulldozer/move-manifest.json \
  --run
```

## Duplicate Review

Dry-run with `--duplicates=skip` to see which colliding files would be skipped:

```sh
php bulldozer.php \
  --source=/tmp/bulldozer/source \
  --destination=/tmp/bulldozer/library \
  --duplicates=skip \
  --date=mtime \
  --timezone=America/New_York \
  --json \
  --manifest=/tmp/bulldozer/duplicate-review.json
```

Inspect `file.duplicate` and `file.skipped` actions in the manifest.

## Verification Commands

```sh
php -l bulldozer.php
tests/smoke.sh
git diff --check
```
