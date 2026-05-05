# Agent Guide

Bulldozer is a PHP 8.2+ CLI script that organizes files into `yyyy/mm/dd`
directories. Treat it as a filesystem-mutating tool and default to dry runs.

## Operating Rules

- Always run `php -l bulldozer.php` after editing the script.
- Always dry-run before using `--run`.
- Do not run against a real photo library with `--run` unless the user has
  explicitly approved the source, destination, mode, duplicate behavior, and
  timezone.
- Prefer explicit `--source`, `--destination`, `--date`, and `--timezone`
  arguments in agent workflows instead of relying on local config paths.
- Use `--config=PATH` when a repeatable run needs many settings, but keep
  `--run` as an explicit CLI flag. Config files are not allowed to enable
  writes.
- Prefer `--json` for command parsing and `--manifest=PATH` for reviewing
  planned or completed actions.
- Use temporary fixture directories under `/private/tmp` or another disposable
  location for tests.
- Do not edit user photo libraries, mounted backup drives, or configured
  destination paths during tests.

## Canonical Checks

```sh
php -l bulldozer.php
tests/smoke.sh
git diff --check
```

## Safe Dry Run Pattern

```sh
php bulldozer.php \
  --source=/path/to/source \
  --destination=/path/to/library \
  --date=mtime \
  --timezone=America/New_York \
  --json \
  --manifest=/tmp/bulldozer-manifest.json
```

Inspect the JSON result and manifest before running the same command with
`--run`.

## Exit Codes

```text
0  Success.
1  Usage, configuration, or validation error.
2  Run completed with file operation errors.
3  Run completed with hook, log, or manifest reporting errors.
```

If `--json` is used, parse `exit_code`, `ok`, and `stats` from stdout.

## Agent-Focused References

- `readme.md` has the human-facing overview.
- `docs/cli-reference.md` has option and exit-code details.
- `docs/manifest-schema.md` describes manifest output.
- `docs/hooks.md` describes the small event hook system.
- `docs/agent-examples.md` has safe command examples.
- `examples/bulldozer.config.json` shows the JSON config-file shape.

## Skill File

This repo intentionally uses `AGENTS.md` rather than a Codex `SKILL.md`.
`AGENTS.md` is the right entrypoint for agents working inside this repository.
A separate skill file only makes sense if Bulldozer should become an installable
capability that agents use across unrelated repositories.
