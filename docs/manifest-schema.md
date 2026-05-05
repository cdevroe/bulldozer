# Manifest Schema

Use `--manifest=PATH` to write a JSON manifest of planned or completed actions.
The manifest is valid JSON and is written incrementally so large runs do not
need to keep every action in memory.

## Top-Level Shape

```json
{
  "schema": "https://cdevroe.com/projects/bulldozer/manifest/v1",
  "generated_at": "2026-05-05T11:36:40+00:00",
  "runtime": {},
  "actions": [],
  "stats": {}
}
```

## Runtime

The `runtime` object records the effective command configuration:

```json
{
  "source": "/tmp/source",
  "destination": "/tmp/dest",
  "mode": "copy",
  "date": "mtime",
  "duplicates": "rename",
  "timezone": "America/New_York",
  "config_file": "/tmp/bulldozer.config.json",
  "dry_run": true,
  "all_files": false,
  "json": true,
  "quiet": false,
  "verbose": false,
  "location": "default",
  "manifest_file": "/tmp/manifest.json",
  "log_file": null,
  "hooks_file": null
}
```

## File Action

```json
{
  "timestamp": "2026-05-05T11:36:40+00:00",
  "event": "file.planned",
  "type": "file",
  "status": "planned",
  "operation": "copy",
  "source": "/tmp/source/a.jpg",
  "destination": "/tmp/dest/2024/05/05/a.jpg",
  "date_path": "2024/05/05",
  "date_source": "mtime",
  "date_value": "2024-05-05T05:05:00-04:00",
  "duplicate": false,
  "same_file": null,
  "dry_run": true,
  "error": null
}
```

## Directory Action

```json
{
  "timestamp": "2026-05-05T11:36:40+00:00",
  "event": "directory.planned",
  "type": "directory",
  "status": "planned",
  "directory": "/tmp/dest/2024/05/05",
  "dry_run": true
}
```

## Common Events

```text
directory.planned
directory.created
directory.error
file.planned
file.copied
file.moved
file.duplicate
file.skipped
file.error
```

## Stats

The `stats` object matches JSON stdout:

```json
{
  "processed": 2,
  "eligible": 1,
  "copied": 1,
  "moved": 0,
  "skipped": 1,
  "duplicates": 0,
  "directories_created": 1,
  "errors": 0,
  "file_errors": 0,
  "hook_errors": 0,
  "reporting_errors": 0
}
```
