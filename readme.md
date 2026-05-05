# Bulldozer

Bulldozer is a small PHP CLI script that organizes images into `yyyy/mm/dd` directories based on the image date.

It targets **PHP 8.2 or newer** and has no Composer dependencies.

By [Colin Devroe](http://cdevroe.com/projects/bulldozer)

## Setup

Edit the configuration block at the top of `bulldozer.php`:

```php
$config = [
    'source' => '/path/to/images_to_organize',

    'locations' => [
        'default' => '/path/to/image_library',
        'backup' => '/Volumes/BackupDrive/image_library',
    ],
];
```

The source and destination directories must already exist. Bulldozer creates the dated `yyyy/mm/dd` directories inside the destination as needed.

## Config File

You can also put configuration in JSON and load it with `--config`:

```json
{
  "source": "/path/to/images_to_organize",
  "destination": "/path/to/image_library",
  "date": "mtime",
  "timezone": "America/New_York",
  "duplicates": "rename"
}
```

Run with:

```sh
php bulldozer.php --config=/path/to/bulldozer.config.json --json
```

CLI flags override config-file values. For safety, config files cannot enable
`--run`; write operations still require the explicit `--run` CLI flag.

## Usage

Always start with a dry run:

```sh
php bulldozer.php
```

Actually copy files:

```sh
php bulldozer.php --run
```

Move files instead of copying them:

```sh
php bulldozer.php --mode=move --run
```

Use a configured destination:

```sh
php bulldozer.php --location=backup --run
```

Override the configured source and destination:

```sh
php bulldozer.php --source=/path/to/source --destination=/path/to/library --run
```

Legacy usage still works:

```sh
php bulldozer.php backup true
```

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
--duplicates=rename|skip   Rename or skip filename collisions. Default: rename.
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

## Agent Usage

Agents should dry-run first and use JSON plus a manifest:

```sh
php bulldozer.php \
  --source=/path/to/source \
  --destination=/path/to/library \
  --date=mtime \
  --timezone=America/New_York \
  --json \
  --manifest=/tmp/bulldozer-manifest.json
```

Then inspect the manifest before running:

```sh
php bulldozer.php \
  --source=/path/to/source \
  --destination=/path/to/library \
  --date=mtime \
  --timezone=America/New_York \
  --json \
  --manifest=/tmp/bulldozer-manifest.json \
  --run
```

Agent-specific guidance lives in `AGENTS.md`. More detailed references are in
`docs/cli-reference.md`, `docs/manifest-schema.md`, `docs/hooks.md`, and
`docs/agent-examples.md`.

## Exit Codes

```text
0  Success.
1  Usage, configuration, or validation error.
2  Run completed with file operation errors.
3  Run completed with hook, log, or manifest reporting errors.
```

## Dates

By default, Bulldozer tries EXIF image dates first:

1. `DateTimeOriginal`
2. `DateTimeDigitized`
3. `DateTime`

If no usable EXIF date is found, it falls back to filesystem modified time.

Use `--date=mtime` to skip EXIF and use modified time for every file.

Use `--timezone=America/New_York` if you want to force the timezone used when building date folders. Without this option, Bulldozer uses PHP's configured default timezone.

## Duplicates

The default duplicate behavior is `rename`:

```text
IMG_0001.jpg
IMG_0001-1.jpg
IMG_0001-2.jpg
```

Use `--duplicates=skip` to leave colliding files untouched.

When duplicates are skipped, Bulldozer records whether the files appear to be the same by comparing file size first, then SHA-256 hashes only when sizes match.

## Logging

Use `--log` to write a basic operation log:

```sh
php bulldozer.php --log --run
```

That writes to `bulldozer.log` next to the script. You can also choose a path:

```sh
php bulldozer.php --log=/tmp/bulldozer.log --run
```

The log includes run configuration, skipped files, directory creation, copy/move operations, duplicate handling, hook errors, and final stats.

## Hooks

A full plugin system would add too much complexity for a small CLI script. The script instead supports a deliberately small event hook file for developers who need to run extra code as files are planned, copied, moved, skipped, or when a run starts or finishes.

Create a hook file:

```php
<?php

return static function (BulldozerHooks $hooks): void {
    $hooks->on('file.copied', static function (array $event): void {
        echo "Copied {$event['source']} to {$event['destination']}" . PHP_EOL;
    });
};
```

Run with hooks:

```sh
php bulldozer.php --hooks=/path/to/bulldozer-hooks.php --run
```

Available events:

```text
run.start
directory.planned
directory.created
directory.error
file.planned
file.copied
file.moved
file.duplicate
file.skipped
file.error
run.finish
```

Hook failures are logged and counted as errors, but they do not stop the run.

## Notes

- The script is intended for images: JPG, PNG, HEIC, TIFF.
- EXIF reading is available for formats supported by PHP's `exif` extension,
  typically JPEG and TIFF. HEIC files usually fall back to modified time.
- Use `--all-files` if you want Bulldozer to process non-image files too.
- Move mode uses `rename()` first, then falls back to copy-and-delete when moving across filesystems.

## Version History

- **2026.05** - May 5, 2026
    - Target PHP 8.2+.
    - Added dry-run by default, explicit `--run`, copy/move modes, safer
      duplicate handling, EXIF date support, streaming traversal, logging, and
      optional event hooks.
    - Added agent-ready JSON output, manifest output, stable exit codes, CLI
      reference docs, and a smoke test.
- **2021.01** - January 25, 2021
    - Initial public release.
