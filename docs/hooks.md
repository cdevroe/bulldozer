# Hooks

Bulldozer has a small event hook system, not a full plugin framework. This keeps
the CLI simple while still allowing developers to react to file operations.

## Usage

Create a PHP file that returns a callable:

```php
<?php

return static function (BulldozerHooks $hooks): void {
    $hooks->on('file.copied', static function (array $event): void {
        file_put_contents(
            '/tmp/copied-files.log',
            $event['source'] . ' -> ' . $event['destination'] . PHP_EOL,
            FILE_APPEND
        );
    });
};
```

Run with:

```sh
php bulldozer.php --hooks=/path/to/hooks.php --run
```

## Alternative Registration

A hooks file may define `bulldozer_register_hooks()` instead of returning a
callable:

```php
<?php

function bulldozer_register_hooks(BulldozerHooks $hooks): void
{
    $hooks->on('run.finish', static function (array $event): void {
        print_r($event['stats']);
    });
}
```

## Events

```text
run.start
directory.planned
directory.created
file.planned
file.copied
file.moved
file.duplicate
file.skipped
file.error
run.finish
```

`directory.error` is written to the manifest for directory creation failures.
Hook callbacks receive the event payload as the first argument and the event name
as the second argument.

## Failure Behavior

Hook exceptions are caught. They are logged, counted as `hook_errors`, and cause
exit code `3` unless a file operation error also occurred.

Hooks should avoid writing to stdout when the caller uses `--json`, because that
would pollute machine-readable output.
