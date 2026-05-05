# CLI Reference

Bulldozer targets PHP 8.2 or newer.

## Usage

```sh
php bulldozer.php [options]
php bulldozer.php backup true
```

Legacy positional usage is still supported. The first positional argument is a
configured location name. The second positional argument is parsed as a boolean
run confirmation.

## Options

```text
--config=PATH              Load a JSON config file. CLI flags override config values.
--source=PATH              Source directory. Defaults to the configured source.
--destination=PATH         Destination directory. Overrides --location.
--location=NAME            Configured destination name. Default: default.
--run                      Actually copy or move files. Default is dry run.
--dry-run                  Force dry run.
--mode=copy|move           Copy or move files. Default: copy.
--date=exif|mtime          Date source. Default: exif with mtime fallback.
--duplicates=rename|skip   Rename or skip destination filename collisions.
--timezone=TIMEZONE        Timezone for EXIF and mtime dates.
--all-files                Process every file except ignored system files.
--json                     Print machine-readable JSON instead of text summary.
--manifest=PATH            Write a JSON manifest of planned or completed actions.
--quiet                    Suppress verbose per-file output.
--verbose                  Print per-file progress. Ignored when --json is used.
--log[=PATH]               Write a log file.
--hooks[=PATH]             Load a PHP hooks file.
--help                     Show help.
```

## Config Files

`--config=PATH` loads a JSON object. CLI flags override config-file values.

```json
{
  "source": "/tmp/source",
  "destination": "/tmp/dest",
  "mode": "copy",
  "date": "mtime",
  "duplicates": "rename",
  "timezone": "America/New_York",
  "all_files": false,
  "manifest_file": "/tmp/bulldozer-manifest.json",
  "log_file": "/tmp/bulldozer.log"
}
```

Supported config keys:

```text
source
destination
locations
location
mode
date
duplicates
timezone
all_files
allowed_extensions
ignored_filenames
directory_mode
manifest_file
log_file
hooks_file
default_log_file
default_hooks_file
```

For safety, config files cannot include `run`. Use `--run` explicitly.

## JSON Output

Use `--json` when another program or agent is calling Bulldozer.

```json
{
  "ok": true,
  "exit_code": 0,
  "summary": "Dry run complete. 2 files processed...",
  "dry_run": true,
  "mode": "copy",
  "date": "mtime",
  "duplicates": "rename",
  "timezone": "America/New_York",
  "source": "/tmp/source",
  "destination": "/tmp/dest",
  "manifest_file": "/tmp/manifest.json",
  "log_file": null,
  "stats": {
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
}
```

Validation errors also return JSON when `--json` is present:

```json
{
  "ok": false,
  "exit_code": 1,
  "error": "Unknown location \"missing\"."
}
```

## Exit Codes

```text
0  Success.
1  Usage, configuration, or validation error.
2  Run completed with file operation errors.
3  Run completed with hook, log, or manifest reporting errors.
```

If file errors and reporting errors both happen, Bulldozer exits `2` because the
filesystem operation failure is the primary result.

## Safety Defaults

- Dry run is the default.
- Copy is the default mode.
- Duplicate filename collisions are renamed by default.
- Source and destination must already exist.
- Destination cannot be the source directory or inside the source directory.
