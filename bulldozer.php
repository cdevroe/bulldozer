#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bulldozer
 *
 * Organize image files into YYYY/MM/DD directories using image metadata when
 * available, with filesystem modified time as a fallback.
 *
 * PHP version 8.2+
 *
 * Copyright (C) 2021-2026 Colin Devroe
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @author  Colin Devroe
 * @license GPL-3.0-or-later
 * @link    https://cdevroe.com/projects/bulldozer
 * @see     readme.md
 */

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, 'Bulldozer requires PHP 8.2 or newer.' . PHP_EOL);
    exit(1);
}

const BULLDOZER_EXIT_SUCCESS = 0;
const BULLDOZER_EXIT_USAGE = 1;
const BULLDOZER_EXIT_FILE_ERRORS = 2;
const BULLDOZER_EXIT_REPORTING_ERRORS = 3;

/* Configuration */
$config = [
    'source' => '/Users/cdevroe/Desktop/bulldozer_images_to_copy',
    'destination' => null,

    'locations' => [
        'default' => '/Users/cdevroe/Desktop/bulldozer_image_library',
        'backup' => '/Volumes/Name_Of_Volume/',
    ],

    'location' => 'default',
    'mode' => 'copy',
    'date' => 'exif',
    'duplicates' => 'rename',
    'all_files' => false,
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'heic', 'tif', 'tiff'],
    'ignored_filenames' => ['.DS_Store'],
    'directory_mode' => 0755,
    'timezone' => null,
    'manifest_file' => null,
    'log_file' => null,
    'hooks_file' => null,
    'default_log_file' => __DIR__ . DIRECTORY_SEPARATOR . 'bulldozer.log',
    'default_hooks_file' => __DIR__ . DIRECTORY_SEPARATOR . 'bulldozer-hooks.php',
];
/* End Configuration */

final class BulldozerHooks
{
    /** @var array<string, list<callable(array<string, mixed>, string): void>> */
    private array $listeners = [];

    /** @var list<string> */
    private array $errors = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function fire(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload, $event);
            } catch (Throwable $throwable) {
                $this->errors[] = sprintf(
                    'Hook failed for "%s": %s',
                    $event,
                    $throwable->getMessage()
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    public function pullErrors(): array
    {
        $errors = $this->errors;
        $this->errors = [];

        return $errors;
    }
}

final class BulldozerLogger
{
    private ?string $path;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(?string $path)
    {
        $this->path = $path;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context = []): void
    {
        if ($this->path === null) {
            return;
        }

        $line = sprintf('%s [%s] %s', date('c'), $level, $message);

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line .= PHP_EOL;

        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            $error = sprintf('Could not write to log file: %s', $this->path);
            $this->errors[] = $error;
            fwrite(STDERR, $error . PHP_EOL);
        }
    }

    /**
     * @return list<string>
     */
    public function pullErrors(): array
    {
        $errors = $this->errors;
        $this->errors = [];

        return $errors;
    }
}

final class BulldozerManifestWriter
{
    /** @var resource|null */
    private $handle = null;

    private ?string $path;

    private bool $needsComma = false;

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $runtime
     */
    public function __construct(?string $path, array $runtime)
    {
        $this->path = $path;

        if ($this->path === null) {
            return;
        }

        $handle = fopen($this->path, 'wb');

        if ($handle === false) {
            $this->errors[] = sprintf('Could not open manifest file: %s', $this->path);
            return;
        }

        $this->handle = $handle;

        $header = [
            'schema' => 'https://cdevroe.com/projects/bulldozer/manifest/v1',
            'generated_at' => date('c'),
            'runtime' => eventRuntimeContext($runtime),
        ];

        $this->write('{' . PHP_EOL);
        $this->write('  "schema": ' . encodeJson($header['schema']) . ',' . PHP_EOL);
        $this->write('  "generated_at": ' . encodeJson($header['generated_at']) . ',' . PHP_EOL);
        $this->write('  "runtime": ' . encodeJson($header['runtime']) . ',' . PHP_EOL);
        $this->write('  "actions": [' . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $action
     */
    public function addAction(array $action): void
    {
        if ($this->handle === null) {
            return;
        }

        $action = ['timestamp' => date('c')] + $action;
        $prefix = $this->needsComma ? ',' . PHP_EOL : '';

        $this->write($prefix . '    ' . encodeJson($action));
        $this->needsComma = true;
    }

    /**
     * @param array<string, int> $stats
     */
    public function finish(array $stats): void
    {
        if ($this->handle === null) {
            return;
        }

        $this->write(PHP_EOL . '  ],' . PHP_EOL);
        $this->write('  "stats": ' . encodeJson($stats) . PHP_EOL);
        $this->write('}' . PHP_EOL);

        if (fclose($this->handle) === false) {
            $this->errors[] = sprintf('Could not close manifest file: %s', $this->path);
        }

        $this->handle = null;
    }

    /**
     * @return list<string>
     */
    public function pullErrors(): array
    {
        $errors = $this->errors;
        $this->errors = [];

        return $errors;
    }

    private function write(string $content): void
    {
        if ($this->handle === null) {
            return;
        }

        if (fwrite($this->handle, $content) === false) {
            $this->errors[] = sprintf('Could not write to manifest file: %s', $this->path);
        }
    }
}

/**
 * @param list<string> $argv
 * @param array<string, mixed> $config
 */
function main(array $argv, array $config): int
{
    try {
        $options = parseArguments($argv, $config);
        $config = loadEffectiveConfig($config, $options['config_file']);

        if ($options['help'] === true) {
            fwrite(STDOUT, helpText($config));

            return 0;
        }

        $runtime = buildRuntimeConfig($options, $config);
        $logger = new BulldozerLogger($runtime['log_file']);
        $hooks = new BulldozerHooks();

        loadHooks($runtime['hooks_file'], $hooks, $logger);

        $manifest = new BulldozerManifestWriter($runtime['manifest_file'], $runtime);
        $stats = runBulldozer($runtime, $logger, $hooks, $manifest);

        $manifest->finish($stats);
        collectReportingErrors($stats, $logger, $manifest);

        $exitCode = determineExitCode($stats);

        if ($runtime['json'] === true) {
            fwrite(STDOUT, encodeJson(jsonResult($stats, $runtime, $exitCode)) . PHP_EOL);
        } else {
            fwrite(STDOUT, summaryText($stats, $runtime) . PHP_EOL);
        }

        return $exitCode;
    } catch (RuntimeException $exception) {
        if (argvWantsJson($argv)) {
            fwrite(STDOUT, encodeJson([
                'ok' => false,
                'exit_code' => BULLDOZER_EXIT_USAGE,
                'error' => $exception->getMessage(),
            ]) . PHP_EOL);

            return BULLDOZER_EXIT_USAGE;
        }

        fwrite(STDERR, sprintf("Error: %s%s%s", $exception->getMessage(), PHP_EOL, PHP_EOL));
        fwrite(STDERR, helpText($config));

        return BULLDOZER_EXIT_USAGE;
    }
}

/**
 * @param list<string> $argv
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function parseArguments(array $argv, array $config): array
{
    $options = [
        'help' => false,
        'config_file' => null,
        'source' => null,
        'destination' => null,
        'location' => null,
        'run' => false,
        'mode' => null,
        'date' => null,
        'duplicates' => null,
        'timezone' => null,
        'all_files' => null,
        'json' => false,
        'quiet' => false,
        'verbose' => false,
        'manifest_file' => null,
        'log_file' => null,
        'hooks_file' => null,
    ];

    $positionals = [];

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if (isNamedOption($argument, 'config')) {
            $options['config_file'] = readOptionValue($argument, $argv, $index, 'config');
            continue;
        }

        if ($argument === '--run') {
            $options['run'] = true;
            continue;
        }

        if ($argument === '--dry-run') {
            $options['run'] = false;
            continue;
        }

        if ($argument === '--all-files') {
            $options['all_files'] = true;
            continue;
        }

        if ($argument === '--json') {
            $options['json'] = true;
            continue;
        }

        if ($argument === '--quiet') {
            $options['quiet'] = true;
            continue;
        }

        if ($argument === '--verbose') {
            $options['verbose'] = true;
            continue;
        }

        if (isNamedOption($argument, 'source')) {
            $options['source'] = readOptionValue($argument, $argv, $index, 'source');
            continue;
        }

        if (isNamedOption($argument, 'destination')) {
            $options['destination'] = readOptionValue($argument, $argv, $index, 'destination');
            continue;
        }

        if (isNamedOption($argument, 'location')) {
            $options['location'] = readOptionValue($argument, $argv, $index, 'location');
            continue;
        }

        if (isNamedOption($argument, 'mode')) {
            $options['mode'] = readOptionValue($argument, $argv, $index, 'mode');
            continue;
        }

        if (isNamedOption($argument, 'date')) {
            $options['date'] = readOptionValue($argument, $argv, $index, 'date');
            continue;
        }

        if (isNamedOption($argument, 'duplicates')) {
            $options['duplicates'] = readOptionValue($argument, $argv, $index, 'duplicates');
            continue;
        }

        if (isNamedOption($argument, 'timezone')) {
            $options['timezone'] = readOptionValue($argument, $argv, $index, 'timezone');
            continue;
        }

        if (isNamedOption($argument, 'manifest')) {
            $options['manifest_file'] = readOptionValue($argument, $argv, $index, 'manifest');
            continue;
        }

        if (isNamedOption($argument, 'log')) {
            $options['log_file'] = readOptionalOptionValue($argument, $argv, $index, true);
            continue;
        }

        if (isNamedOption($argument, 'hooks')) {
            $options['hooks_file'] = readOptionalOptionValue($argument, $argv, $index, true);
            continue;
        }

        if (str_starts_with($argument, '--')) {
            throw new RuntimeException(sprintf('Unknown option: %s', $argument));
        }

        $positionals[] = $argument;
    }

    if (isset($positionals[0])) {
        $options['location'] = $positionals[0];
    }

    if (isset($positionals[1])) {
        $options['run'] = filter_var($positionals[1], FILTER_VALIDATE_BOOLEAN);
    }

    return $options;
}

function isNamedOption(string $argument, string $name): bool
{
    return $argument === '--' . $name || str_starts_with($argument, '--' . $name . '=');
}

/**
 * @param list<string> $argv
 */
function readOptionValue(string $argument, array $argv, int &$index, string $name): string
{
    if (str_contains($argument, '=')) {
        [, $value] = explode('=', $argument, 2);

        if ($value === '') {
            throw new RuntimeException(sprintf('Option --%s requires a value.', $name));
        }

        return $value;
    }

    if (!isset($argv[$index + 1]) || str_starts_with($argv[$index + 1], '--')) {
        throw new RuntimeException(sprintf('Option --%s requires a value.', $name));
    }

    $index++;

    return $argv[$index];
}

/**
 * @param list<string> $argv
 * @param mixed $default
 * @return mixed
 */
function readOptionalOptionValue(string $argument, array $argv, int &$index, $default)
{
    if (str_contains($argument, '=')) {
        [, $value] = explode('=', $argument, 2);

        return $value !== '' ? $value : $default;
    }

    if (isset($argv[$index + 1]) && !str_starts_with($argv[$index + 1], '--')) {
        $index++;

        return $argv[$index];
    }

    return $default;
}

/**
 * @param array<string, mixed> $baseConfig
 * @return array<string, mixed>
 */
function loadEffectiveConfig(array $baseConfig, ?string $configFile): array
{
    if ($configFile === null) {
        return $baseConfig;
    }

    return mergeConfig($baseConfig, loadConfigFile($configFile));
}

/**
 * @return array<string, mixed>
 */
function loadConfigFile(string $configFile): array
{
    $path = normalizeReadableFilePath($configFile, 'config');
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('Could not read config file: %s', $path));
    }

    try {
        $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(sprintf('Could not parse config JSON: %s', $exception->getMessage()));
    }

    if (!is_array($config)) {
        throw new RuntimeException('Config file must contain a JSON object.');
    }

    validateConfigFile($config);

    return $config;
}

/**
 * @param array<string, mixed> $config
 */
function validateConfigFile(array $config): void
{
    if (array_key_exists('run', $config)) {
        throw new RuntimeException('Config files cannot enable --run. Use the CLI flag explicitly.');
    }

    $allowedKeys = [
        'source',
        'destination',
        'locations',
        'location',
        'mode',
        'date',
        'duplicates',
        'timezone',
        'all_files',
        'allowed_extensions',
        'ignored_filenames',
        'directory_mode',
        'manifest_file',
        'log_file',
        'hooks_file',
        'default_log_file',
        'default_hooks_file',
    ];

    foreach (array_keys($config) as $key) {
        if (!in_array($key, $allowedKeys, true)) {
            throw new RuntimeException(sprintf('Unknown config key "%s".', $key));
        }
    }

    foreach (['source', 'destination', 'location', 'mode', 'date', 'duplicates', 'timezone', 'manifest_file', 'log_file', 'hooks_file', 'default_log_file', 'default_hooks_file'] as $key) {
        if (array_key_exists($key, $config) && $config[$key] !== null && !is_string($config[$key])) {
            throw new RuntimeException(sprintf('Config key "%s" must be a string or null.', $key));
        }
    }

    foreach (['allowed_extensions', 'ignored_filenames'] as $key) {
        if (array_key_exists($key, $config) && !is_string_list($config[$key])) {
            throw new RuntimeException(sprintf('Config key "%s" must be an array of strings.', $key));
        }
    }

    if (array_key_exists('locations', $config)) {
        if (!is_array($config['locations'])) {
            throw new RuntimeException('Config key "locations" must be an object of name/path pairs.');
        }

        foreach ($config['locations'] as $name => $path) {
            if (!is_string($name) || !is_string($path)) {
                throw new RuntimeException('Config key "locations" must be an object of name/path pairs.');
            }
        }
    }

    if (array_key_exists('all_files', $config) && !is_bool($config['all_files'])) {
        throw new RuntimeException('Config key "all_files" must be a boolean.');
    }

    if (array_key_exists('directory_mode', $config) && !is_int($config['directory_mode'])) {
        throw new RuntimeException('Config key "directory_mode" must be an integer.');
    }
}

/**
 * @param mixed $value
 */
function is_string_list($value): bool
{
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $item) {
        if (!is_string($item)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $baseConfig
 * @param array<string, mixed> $fileConfig
 * @return array<string, mixed>
 */
function mergeConfig(array $baseConfig, array $fileConfig): array
{
    foreach ($fileConfig as $key => $value) {
        if ($key === 'locations') {
            $baseConfig['locations'] = array_merge((array) ($baseConfig['locations'] ?? []), (array) $value);
            continue;
        }

        $baseConfig[$key] = $value;
    }

    return $baseConfig;
}

/**
 * @param array<string, mixed> $options
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function buildRuntimeConfig(array $options, array $config): array
{
    $source = (string) ($options['source'] ?? $config['source']);
    $location = (string) ($options['location'] ?? $config['location'] ?? 'default');

    if ($options['destination'] !== null) {
        $destination = (string) $options['destination'];
    } elseif (($config['destination'] ?? null) !== null) {
        $destination = (string) $config['destination'];
    } else {
        $locations = $config['locations'];

        if (!is_array($locations) || !array_key_exists($location, $locations)) {
            throw new RuntimeException(sprintf('Unknown location "%s".', $location));
        }

        $destination = (string) $locations[$location];
    }

    $mode = strtolower((string) ($options['mode'] ?? $config['mode'] ?? 'copy'));
    $dateMode = strtolower((string) ($options['date'] ?? $config['date'] ?? 'exif'));
    $duplicateMode = strtolower((string) ($options['duplicates'] ?? $config['duplicates'] ?? 'rename'));
    $timezone = (string) ($options['timezone'] ?? $config['timezone'] ?? date_default_timezone_get());

    if (!in_array($mode, ['copy', 'move'], true)) {
        throw new RuntimeException('--mode must be copy or move.');
    }

    if (!in_array($dateMode, ['exif', 'mtime'], true)) {
        throw new RuntimeException('--date must be exif or mtime.');
    }

    if (!in_array($duplicateMode, ['rename', 'skip'], true)) {
        throw new RuntimeException('--duplicates must be rename or skip.');
    }

    if ($options['quiet'] === true && $options['verbose'] === true) {
        throw new RuntimeException('--quiet and --verbose cannot be used together.');
    }

    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new RuntimeException(sprintf('Unknown timezone "%s".', $timezone));
    }

    $sourceReal = realpath($source);
    $destinationReal = realpath($destination);

    if ($sourceReal === false || !is_dir($sourceReal)) {
        throw new RuntimeException(sprintf('Source directory does not exist: %s', $source));
    }

    if (!is_readable($sourceReal)) {
        throw new RuntimeException(sprintf('Source directory is not readable: %s', $sourceReal));
    }

    if ($destinationReal === false || !is_dir($destinationReal)) {
        throw new RuntimeException(sprintf('Destination directory does not exist: %s', $destination));
    }

    if (samePath($sourceReal, $destinationReal)) {
        throw new RuntimeException('Source and destination cannot be the same directory.');
    }

    if (isPathInside($sourceReal, $destinationReal)) {
        throw new RuntimeException('Destination cannot be inside the source directory.');
    }

    if ($options['run'] === true && !is_writable($destinationReal)) {
        throw new RuntimeException(sprintf('Destination directory is not writable: %s', $destinationReal));
    }

    $logFile = resolveOptionalWritablePath($options['log_file'], $config, 'log_file', 'default_log_file', 'log');
    $hooksFile = resolveOptionalReadablePath($options['hooks_file'], $config, 'hooks_file', 'default_hooks_file', 'hooks');
    $manifestFile = resolveOptionalWritablePath($options['manifest_file'], $config, 'manifest_file', null, 'manifest');
    $allFiles = $options['all_files'] !== null
        ? $options['all_files'] === true
        : (bool) ($config['all_files'] ?? false);

    return [
        'config_file' => $options['config_file'],
        'source' => $sourceReal,
        'destination' => $destinationReal,
        'location' => $location,
        'run' => $options['run'] === true,
        'mode' => $mode,
        'date' => $dateMode,
        'duplicates' => $duplicateMode,
        'timezone' => $timezone,
        'all_files' => $allFiles,
        'json' => $options['json'] === true,
        'quiet' => $options['quiet'] === true,
        'verbose' => $options['verbose'] === true,
        'allowed_extensions' => array_map('strtolower', (array) $config['allowed_extensions']),
        'ignored_filenames' => (array) $config['ignored_filenames'],
        'directory_mode' => (int) $config['directory_mode'],
        'manifest_file' => $manifestFile,
        'log_file' => $logFile,
        'hooks_file' => $hooksFile,
    ];
}

function normalizeWritableFilePath(string $path, string $label): string
{
    $directory = dirname($path);

    if (is_dir($path)) {
        throw new RuntimeException(sprintf('The %s file path is a directory: %s', $label, $path));
    }

    if (!is_dir($directory)) {
        throw new RuntimeException(sprintf('The %s file directory does not exist: %s', $label, $directory));
    }

    if (!is_writable($directory)) {
        throw new RuntimeException(sprintf('The %s file directory is not writable: %s', $label, $directory));
    }

    if (file_exists($path) && !is_writable($path)) {
        throw new RuntimeException(sprintf('The %s file is not writable: %s', $label, $path));
    }

    return $path;
}

function normalizeReadableFilePath(string $path, string $label): string
{
    $realPath = realpath($path);

    if ($realPath === false || !is_file($realPath)) {
        throw new RuntimeException(sprintf('The %s file does not exist: %s', $label, $path));
    }

    if (!is_readable($realPath)) {
        throw new RuntimeException(sprintf('The %s file is not readable: %s', $label, $realPath));
    }

    return $realPath;
}

/**
 * @param mixed $optionValue
 * @param array<string, mixed> $config
 */
function resolveOptionalWritablePath(
    $optionValue,
    array $config,
    string $configKey,
    ?string $defaultConfigKey,
    string $label
): ?string {
    $path = null;

    if ($optionValue !== null) {
        $path = $optionValue === true && $defaultConfigKey !== null
            ? ($config[$defaultConfigKey] ?? null)
            : $optionValue;
    } elseif (($config[$configKey] ?? null) !== null) {
        $path = $config[$configKey];
    }

    return $path !== null ? normalizeWritableFilePath((string) $path, $label) : null;
}

/**
 * @param mixed $optionValue
 * @param array<string, mixed> $config
 */
function resolveOptionalReadablePath(
    $optionValue,
    array $config,
    string $configKey,
    ?string $defaultConfigKey,
    string $label
): ?string {
    $path = null;

    if ($optionValue !== null) {
        $path = $optionValue === true && $defaultConfigKey !== null
            ? ($config[$defaultConfigKey] ?? null)
            : $optionValue;
    } elseif (($config[$configKey] ?? null) !== null) {
        $path = $config[$configKey];
    }

    return $path !== null ? normalizeReadableFilePath((string) $path, $label) : null;
}

function samePath(string $first, string $second): bool
{
    return rtrim($first, DIRECTORY_SEPARATOR) === rtrim($second, DIRECTORY_SEPARATOR);
}

function isPathInside(string $parent, string $child): bool
{
    $parent = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $child = rtrim($child, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return str_starts_with($child, $parent);
}

function loadHooks(?string $hooksFile, BulldozerHooks $hooks, BulldozerLogger $logger): void
{
    if ($hooksFile === null) {
        return;
    }

    $registration = require $hooksFile;

    if (is_callable($registration)) {
        $registration($hooks);
        $logger->info('Loaded hooks file.', ['file' => $hooksFile]);

        return;
    }

    if (function_exists('bulldozer_register_hooks')) {
        bulldozer_register_hooks($hooks);
        $logger->info('Loaded hooks file.', ['file' => $hooksFile]);

        return;
    }

    throw new RuntimeException('Hooks file must return a callable or define bulldozer_register_hooks().');
}

/**
 * @param array<string, mixed> $runtime
 * @return array<string, int>
 */
function runBulldozer(
    array $runtime,
    BulldozerLogger $logger,
    BulldozerHooks $hooks,
    BulldozerManifestWriter $manifest
): array
{
    $stats = [
        'processed' => 0,
        'eligible' => 0,
        'copied' => 0,
        'moved' => 0,
        'skipped' => 0,
        'duplicates' => 0,
        'directories_created' => 0,
        'errors' => 0,
        'file_errors' => 0,
        'hook_errors' => 0,
        'reporting_errors' => 0,
    ];

    $plannedDirectories = [];

    $logger->info('Bulldozer started.', runtimeLogContext($runtime));
    fireEvent($hooks, $logger, 'run.start', ['runtime' => eventRuntimeContext($runtime)], $stats);

    $directoryIterator = new RecursiveDirectoryIterator(
        (string) $runtime['source'],
        FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS
    );

    $iterator = new RecursiveIteratorIterator(
        $directoryIterator,
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || $fileInfo->isLink()) {
            continue;
        }

        $sourcePath = $fileInfo->getPathname();
        $stats['processed']++;

        if (shouldProcessFile($fileInfo, $runtime) === false) {
            $stats['skipped']++;
            $logger->info('Skipped file.', ['source' => $sourcePath, 'reason' => 'filtered']);
            addManifestAction($manifest, [
                'event' => 'file.skipped',
                'type' => 'file',
                'status' => 'skipped',
                'source' => $sourcePath,
                'reason' => 'filtered',
            ]);
            writeVerbose($runtime, sprintf('Skipped filtered file: %s', $sourcePath));
            fireEvent($hooks, $logger, 'file.skipped', ['source' => $sourcePath, 'reason' => 'filtered'], $stats);
            continue;
        }

        $stats['eligible']++;

        try {
            $date = getFileDatePath($sourcePath, (string) $runtime['date'], (string) $runtime['timezone']);
        } catch (RuntimeException $exception) {
            $stats['errors']++;
            $stats['file_errors']++;
            $logger->error('Could not determine file date.', ['source' => $sourcePath, 'error' => $exception->getMessage()]);
            addManifestAction($manifest, [
                'event' => 'file.error',
                'type' => 'file',
                'status' => 'error',
                'source' => $sourcePath,
                'error' => $exception->getMessage(),
            ]);
            fireEvent($hooks, $logger, 'file.error', ['source' => $sourcePath, 'error' => $exception->getMessage()], $stats);
            continue;
        }

        $targetDirectory = joinPaths((string) $runtime['destination'], $date['path']);

        if (ensureTargetDirectory($targetDirectory, $runtime, $logger, $hooks, $manifest, $stats, $plannedDirectories) === false) {
            continue;
        }

        $targetPath = joinPaths($targetDirectory, basename($sourcePath));
        $duplicateSameFile = null;

        if (file_exists($targetPath)) {
            $stats['duplicates']++;
            $duplicateSameFile = $runtime['duplicates'] === 'skip'
                ? filesAreSame($sourcePath, $targetPath)
                : null;

            fireEvent($hooks, $logger, 'file.duplicate', [
                'source' => $sourcePath,
                'destination' => $targetPath,
                'same_file' => $duplicateSameFile,
            ], $stats);
            addManifestAction($manifest, [
                'event' => 'file.duplicate',
                'type' => 'file',
                'status' => 'duplicate',
                'source' => $sourcePath,
                'destination' => $targetPath,
                'same_file' => $duplicateSameFile,
            ]);
            writeVerbose($runtime, sprintf('Duplicate found: %s', $sourcePath));

            if ($runtime['duplicates'] === 'skip') {
                $stats['skipped']++;
                $logger->info('Skipped duplicate.', [
                    'source' => $sourcePath,
                    'destination' => $targetPath,
                    'same_file' => $duplicateSameFile,
                ]);
                fireEvent($hooks, $logger, 'file.skipped', [
                    'source' => $sourcePath,
                    'destination' => $targetPath,
                    'reason' => 'duplicate',
                    'same_file' => $duplicateSameFile,
                ], $stats);
                addManifestAction($manifest, [
                    'event' => 'file.skipped',
                    'type' => 'file',
                    'status' => 'skipped',
                    'source' => $sourcePath,
                    'destination' => $targetPath,
                    'reason' => 'duplicate',
                    'same_file' => $duplicateSameFile,
                ]);
                writeVerbose($runtime, sprintf('Skipped duplicate file: %s', $sourcePath));
                continue;
            }

            $targetPath = uniqueTargetPath($targetPath);
        }

        $payload = [
            'source' => $sourcePath,
            'destination' => $targetPath,
            'date_path' => $date['path'],
            'date_source' => $date['source'],
            'date_value' => $date['value'],
            'duplicate' => $duplicateSameFile !== null,
            'same_file' => $duplicateSameFile,
            'mode' => $runtime['mode'],
            'dry_run' => $runtime['run'] === false,
        ];

        if ($runtime['run'] === false) {
            $stats[$runtime['mode'] === 'move' ? 'moved' : 'copied']++;
            $logger->info(sprintf('Would %s file.', $runtime['mode']), $payload);
            addManifestAction($manifest, manifestFileAction('file.planned', 'planned', $payload));
            writeVerbose($runtime, sprintf('Would %s: %s -> %s', $runtime['mode'], $sourcePath, $targetPath));
            fireEvent($hooks, $logger, 'file.planned', $payload, $stats);
            continue;
        }

        $operationError = null;
        $worked = $runtime['mode'] === 'move'
            ? moveFile($sourcePath, $targetPath, $operationError)
            : copyFile($sourcePath, $targetPath, $operationError);

        if ($worked === false) {
            $stats['errors']++;
            $stats['file_errors']++;
            $logger->error(sprintf('Could not %s file.', $runtime['mode']), $payload + ['error' => $operationError]);
            addManifestAction($manifest, manifestFileAction('file.error', 'error', $payload + ['error' => $operationError]));
            fireEvent($hooks, $logger, 'file.error', $payload + ['error' => $operationError], $stats);
            continue;
        }

        if ($runtime['mode'] === 'move') {
            $stats['moved']++;
            $logger->info('Moved file.', $payload);
            addManifestAction($manifest, manifestFileAction('file.moved', 'moved', $payload));
            writeVerbose($runtime, sprintf('Moved: %s -> %s', $sourcePath, $targetPath));
            fireEvent($hooks, $logger, 'file.moved', $payload, $stats);
        } else {
            $stats['copied']++;
            $logger->info('Copied file.', $payload);
            addManifestAction($manifest, manifestFileAction('file.copied', 'copied', $payload));
            writeVerbose($runtime, sprintf('Copied: %s -> %s', $sourcePath, $targetPath));
            fireEvent($hooks, $logger, 'file.copied', $payload, $stats);
        }
    }

    fireEvent($hooks, $logger, 'run.finish', ['stats' => $stats], $stats);
    $logger->info('Bulldozer finished.', ['stats' => $stats]);

    return $stats;
}

/**
 * @param array<string, mixed> $runtime
 */
function shouldProcessFile(SplFileInfo $fileInfo, array $runtime): bool
{
    if (in_array($fileInfo->getFilename(), $runtime['ignored_filenames'], true)) {
        return false;
    }

    if ($runtime['all_files'] === true) {
        return true;
    }

    $extension = strtolower($fileInfo->getExtension());

    return $extension !== '' && in_array($extension, $runtime['allowed_extensions'], true);
}

/**
 * @param array<string, mixed> $runtime
 * @param array<string, int> $stats
 * @param array<string, true> $plannedDirectories
 */
function ensureTargetDirectory(
    string $targetDirectory,
    array $runtime,
    BulldozerLogger $logger,
    BulldozerHooks $hooks,
    BulldozerManifestWriter $manifest,
    array &$stats,
    array &$plannedDirectories
): bool {
    if (is_dir($targetDirectory)) {
        return true;
    }

    if ($runtime['run'] === false) {
        if (!isset($plannedDirectories[$targetDirectory])) {
            $plannedDirectories[$targetDirectory] = true;
            $stats['directories_created']++;
            $logger->info('Would create directory.', ['directory' => $targetDirectory]);
            addManifestAction($manifest, [
                'event' => 'directory.planned',
                'type' => 'directory',
                'status' => 'planned',
                'directory' => $targetDirectory,
                'dry_run' => true,
            ]);
            writeVerbose($runtime, sprintf('Would create directory: %s', $targetDirectory));
            fireEvent($hooks, $logger, 'directory.planned', ['directory' => $targetDirectory], $stats);
        }

        return true;
    }

    $error = null;
    $created = filesystemOperation(
        static fn (): bool => mkdir($targetDirectory, (int) $runtime['directory_mode'], true),
        $error
    );

    if ($created === false && !is_dir($targetDirectory)) {
        $stats['errors']++;
        $stats['file_errors']++;
        $logger->error('Could not create directory.', ['directory' => $targetDirectory, 'error' => $error]);
        addManifestAction($manifest, [
            'event' => 'directory.error',
            'type' => 'directory',
            'status' => 'error',
            'directory' => $targetDirectory,
            'error' => $error,
        ]);
        fireEvent($hooks, $logger, 'file.error', ['directory' => $targetDirectory, 'error' => $error], $stats);

        return false;
    }

    $stats['directories_created']++;
    $logger->info('Created directory.', ['directory' => $targetDirectory]);
    addManifestAction($manifest, [
        'event' => 'directory.created',
        'type' => 'directory',
        'status' => 'created',
        'directory' => $targetDirectory,
        'dry_run' => false,
    ]);
    writeVerbose($runtime, sprintf('Created directory: %s', $targetDirectory));
    fireEvent($hooks, $logger, 'directory.created', ['directory' => $targetDirectory], $stats);

    return true;
}

/**
 * @return array{path: string, source: string, value: string}
 */
function getFileDatePath(string $sourcePath, string $dateMode, string $timezone): array
{
    if ($dateMode === 'exif') {
        $exifDate = readExifDate($sourcePath, $timezone);

        if ($exifDate !== null) {
            return $exifDate;
        }
    }

    $modifiedTime = filemtime($sourcePath);

    if ($modifiedTime === false) {
        throw new RuntimeException('filemtime() failed.');
    }

    $date = (new DateTimeImmutable('@' . $modifiedTime))->setTimezone(new DateTimeZone($timezone));

    return [
        'path' => $date->format('Y/m/d'),
        'source' => 'mtime',
        'value' => $date->format('c'),
    ];
}

/**
 * @return array{path: string, source: string, value: string}|null
 */
function readExifDate(string $sourcePath, string $timezone): ?array
{
    if (!function_exists('exif_read_data')) {
        return null;
    }

    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

    if (!in_array($extension, ['jpg', 'jpeg', 'tif', 'tiff'], true)) {
        return null;
    }

    $exif = null;
    $error = null;
    $read = filesystemOperation(
        static function () use ($sourcePath, &$exif): bool {
            $exif = exif_read_data($sourcePath, null, true);

            return is_array($exif);
        },
        $error
    );

    if ($read === false || !is_array($exif)) {
        return null;
    }

    $candidates = [
        'exif:DateTimeOriginal' => $exif['EXIF']['DateTimeOriginal'] ?? null,
        'exif:DateTimeDigitized' => $exif['EXIF']['DateTimeDigitized'] ?? null,
        'exif:DateTime' => $exif['IFD0']['DateTime'] ?? null,
    ];

    foreach ($candidates as $source => $value) {
        if (!is_string($value) || trim($value) === '') {
            continue;
        }

        $date = parseExifDate(trim($value), $timezone);

        if ($date instanceof DateTimeImmutable) {
            return [
                'path' => $date->format('Y/m/d'),
                'source' => $source,
                'value' => trim($value),
            ];
        }
    }

    return null;
}

function parseExifDate(string $value, string $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y:m:d H:i:s',
        $value,
        new DateTimeZone($timezone)
    );

    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    return $date instanceof DateTimeImmutable && $hasErrors === false ? $date : null;
}

function filesAreSame(string $sourcePath, string $destinationPath): bool
{
    $sourceSize = filesize($sourcePath);
    $destinationSize = filesize($destinationPath);

    if ($sourceSize === false || $destinationSize === false || $sourceSize !== $destinationSize) {
        return false;
    }

    $sourceHash = hash_file('sha256', $sourcePath);
    $destinationHash = hash_file('sha256', $destinationPath);

    return is_string($sourceHash)
        && is_string($destinationHash)
        && hash_equals($sourceHash, $destinationHash);
}

function uniqueTargetPath(string $targetPath): string
{
    if (!file_exists($targetPath)) {
        return $targetPath;
    }

    $directory = dirname($targetPath);
    $extension = pathinfo($targetPath, PATHINFO_EXTENSION);
    $filename = pathinfo($targetPath, PATHINFO_FILENAME);
    $suffix = 1;

    do {
        $candidate = $directory . DIRECTORY_SEPARATOR . $filename . '-' . $suffix;

        if ($extension !== '') {
            $candidate .= '.' . $extension;
        }

        $suffix++;
    } while (file_exists($candidate));

    return $candidate;
}

function copyFile(string $sourcePath, string $targetPath, ?string &$error): bool
{
    return filesystemOperation(static fn (): bool => copy($sourcePath, $targetPath), $error);
}

function moveFile(string $sourcePath, string $targetPath, ?string &$error): bool
{
    $renameError = null;

    if (filesystemOperation(static fn (): bool => rename($sourcePath, $targetPath), $renameError)) {
        return true;
    }

    $copyError = null;

    if (filesystemOperation(static fn (): bool => copy($sourcePath, $targetPath), $copyError) === false) {
        $error = $copyError ?? $renameError;

        return false;
    }

    $unlinkError = null;

    if (filesystemOperation(static fn (): bool => unlink($sourcePath), $unlinkError) === false) {
        $cleanupError = null;
        filesystemOperation(static fn (): bool => unlink($targetPath), $cleanupError);
        $error = $unlinkError ?? $renameError;

        return false;
    }

    return true;
}

function filesystemOperation(callable $operation, ?string &$error): bool
{
    $error = null;

    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
        $error = $message;

        return true;
    });

    try {
        return (bool) $operation();
    } finally {
        restore_error_handler();
    }
}

function joinPaths(string ...$parts): string
{
    $path = array_shift($parts) ?? '';

    foreach ($parts as $part) {
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($part, DIRECTORY_SEPARATOR);
    }

    return $path;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function manifestFileAction(string $event, string $status, array $payload): array
{
    return [
        'event' => $event,
        'type' => 'file',
        'status' => $status,
        'operation' => $payload['mode'] ?? null,
        'source' => $payload['source'] ?? null,
        'destination' => $payload['destination'] ?? null,
        'date_path' => $payload['date_path'] ?? null,
        'date_source' => $payload['date_source'] ?? null,
        'date_value' => $payload['date_value'] ?? null,
        'duplicate' => $payload['duplicate'] ?? false,
        'same_file' => $payload['same_file'] ?? null,
        'dry_run' => $payload['dry_run'] ?? false,
        'error' => $payload['error'] ?? null,
    ];
}

/**
 * @param array<string, mixed> $action
 */
function addManifestAction(BulldozerManifestWriter $manifest, array $action): void
{
    $manifest->addAction($action);
}

/**
 * @param array<string, mixed> $runtime
 */
function writeVerbose(array $runtime, string $message): void
{
    if ($runtime['verbose'] !== true || $runtime['quiet'] === true || $runtime['json'] === true) {
        return;
    }

    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * @param array<string, int> $stats
 */
function collectReportingErrors(
    array &$stats,
    BulldozerLogger $logger,
    BulldozerManifestWriter $manifest
): void {
    foreach (array_merge($logger->pullErrors(), $manifest->pullErrors()) as $error) {
        $stats['errors']++;
        $stats['reporting_errors']++;
        fwrite(STDERR, $error . PHP_EOL);
    }
}

/**
 * @param array<string, int> $stats
 */
function determineExitCode(array $stats): int
{
    if (($stats['file_errors'] ?? 0) > 0) {
        return BULLDOZER_EXIT_FILE_ERRORS;
    }

    if (($stats['hook_errors'] ?? 0) > 0 || ($stats['reporting_errors'] ?? 0) > 0) {
        return BULLDOZER_EXIT_REPORTING_ERRORS;
    }

    return BULLDOZER_EXIT_SUCCESS;
}

/**
 * @param array<string, int> $stats
 * @param array<string, mixed> $runtime
 * @return array<string, mixed>
 */
function jsonResult(array $stats, array $runtime, int $exitCode): array
{
    return [
        'ok' => $exitCode === BULLDOZER_EXIT_SUCCESS,
        'exit_code' => $exitCode,
        'summary' => summaryText($stats, $runtime),
        'dry_run' => $runtime['run'] === false,
        'mode' => $runtime['mode'],
        'date' => $runtime['date'],
        'duplicates' => $runtime['duplicates'],
        'timezone' => $runtime['timezone'],
        'config_file' => $runtime['config_file'],
        'source' => $runtime['source'],
        'destination' => $runtime['destination'],
        'manifest_file' => $runtime['manifest_file'],
        'log_file' => $runtime['log_file'],
        'stats' => $stats,
    ];
}

function argvWantsJson(array $argv): bool
{
    return in_array('--json', $argv, true);
}

/**
 * @param mixed $value
 */
function encodeJson($value): string
{
    try {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (JsonException $exception) {
        return json_encode([
            'json_error' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * @param array<string, mixed> $runtime
 * @param array<string, mixed> $payload
 * @param array<string, int> $stats
 */
function fireEvent(
    BulldozerHooks $hooks,
    BulldozerLogger $logger,
    string $event,
    array $payload,
    array &$stats
): void {
    $hooks->fire($event, $payload);

    foreach ($hooks->pullErrors() as $error) {
        $stats['errors']++;
        $stats['hook_errors']++;
        $logger->error($error);
    }
}

/**
 * @param array<string, mixed> $runtime
 * @return array<string, mixed>
 */
function runtimeLogContext(array $runtime): array
{
    return [
        'source' => $runtime['source'],
        'destination' => $runtime['destination'],
        'mode' => $runtime['mode'],
        'date' => $runtime['date'],
        'duplicates' => $runtime['duplicates'],
        'timezone' => $runtime['timezone'],
        'dry_run' => $runtime['run'] === false,
        'all_files' => $runtime['all_files'],
        'json' => $runtime['json'],
        'quiet' => $runtime['quiet'],
        'verbose' => $runtime['verbose'],
    ];
}

/**
 * @param array<string, mixed> $runtime
 * @return array<string, mixed>
 */
function eventRuntimeContext(array $runtime): array
{
    return runtimeLogContext($runtime) + [
        'config_file' => $runtime['config_file'],
        'location' => $runtime['location'],
        'manifest_file' => $runtime['manifest_file'],
        'log_file' => $runtime['log_file'],
        'hooks_file' => $runtime['hooks_file'],
    ];
}

/**
 * @param array<string, int> $stats
 * @param array<string, mixed> $runtime
 */
function summaryText(array $stats, array $runtime): string
{
    $verb = $runtime['mode'] === 'move' ? 'moved' : 'copied';
    $count = $runtime['mode'] === 'move' ? $stats['moved'] : $stats['copied'];
    $prefix = $runtime['run'] === true ? 'Complete.' : 'Dry run complete.';
    $would = $runtime['run'] === true ? '' : ' would be';

    return sprintf(
        '%s %d files processed, %d eligible, %d files%s %s, %d directories%s created, %d duplicates found, %d skipped, %d errors.',
        $prefix,
        $stats['processed'],
        $stats['eligible'],
        $count,
        $would,
        $verb,
        $stats['directories_created'],
        $would,
        $stats['duplicates'],
        $stats['skipped'],
        $stats['errors']
    );
}

/**
 * @param array<string, mixed> $config
 */
function helpText(array $config): string
{
    $locations = implode(', ', array_keys((array) $config['locations']));

    return <<<HELP
Bulldozer organizes image files into YYYY/MM/DD directories.

Usage:
  php bulldozer.php [options]
  php bulldozer.php backup true

Options:
  --config=PATH              Load a JSON config file. CLI flags override config values.
  --source=PATH              Source directory. Defaults to the configured source.
  --destination=PATH         Destination directory. Overrides --location.
  --location=NAME            Configured destination name. Available: {$locations}
  --run                      Actually copy or move files. Default is dry run.
  --dry-run                  Force dry run.
  --mode=copy|move           Copy or move files. Default: copy.
  --date=exif|mtime          Date source. Default: exif with mtime fallback.
  --duplicates=rename|skip   Rename or skip destination filename collisions. Default: rename.
  --timezone=TIMEZONE        Timezone for EXIF and mtime dates. Default: PHP default timezone.
  --all-files                Process every file except ignored system files.
  --json                     Print machine-readable JSON instead of text summary.
  --manifest=PATH            Write a JSON manifest of planned or completed actions.
  --quiet                    Suppress verbose per-file output.
  --verbose                  Print per-file progress. Ignored when --json is used.
  --log[=PATH]               Write a log file. Default: {$config['default_log_file']}
  --hooks[=PATH]             Load a PHP hooks file. Default: {$config['default_hooks_file']}
  --help                     Show this help.

Legacy usage still works: php bulldozer.php Location true

Exit codes:
  0  Success.
  1  Usage, configuration, or validation error.
  2  Run completed with file operation errors.
  3  Run completed with hook, log, or manifest reporting errors.

HELP;
}

exit(main($argv, $config));
