#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Shipcheck.php';

function shipcheck_usage(): void
{
    $version = Shipcheck::VERSION;
    fwrite(STDOUT, <<<TXT
Shipcheck {$version}
Zero-dependency scan for PHP / Laravel production footguns.

Usage:
  php shipcheck.php [path] [--strict] [--format=text|json]

Options:
  path                 Project directory (default: current directory)
  --strict             Exit 1 on warnings as well as errors
  --format=text|json   Human report (default) or one JSON document
  -h, --help           Show this help
  -V, --version        Show the version

Exit codes:
  0  no errors (and no warnings, when --strict)
  1  findings that fail the build
  2  bad arguments or unreadable path

A line containing shipcheck-ignore is skipped. A comment that is only
shipcheck-ignore also skips the next line.

TXT);
}

/** @param list<string> $argv */
function shipcheck_options(array $argv): array
{
    $path = null;
    $format = 'text';
    $strict = false;
    $expectFormat = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($expectFormat) {
            $format = $arg;
            $expectFormat = false;
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            shipcheck_usage();
            exit(0);
        }

        if ($arg === '--version' || $arg === '-V') {
            fwrite(STDOUT, Shipcheck::VERSION . PHP_EOL);
            exit(0);
        }

        if ($arg === '--strict') {
            $strict = true;
            continue;
        }

        if ($arg === '--format') {
            $expectFormat = true;
            continue;
        }

        if (str_starts_with($arg, '--format=')) {
            $format = substr($arg, 9);
            continue;
        }

        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(2);
        }

        if ($path !== null) {
            fwrite(STDERR, "Only one path is accepted.\n");
            exit(2);
        }

        $path = $arg;
    }

    if ($expectFormat || !in_array($format, ['text', 'json'], true)) {
        fwrite(STDERR, "Format must be text or json.\n");
        exit(2);
    }

    return [
        'path' => $path ?? getcwd(),
        'format' => $format,
        'strict' => $strict,
    ];
}

/**
 * @param list<array{rule:string,severity:string,file:string,line:int,message:string,snippet:string}> $findings
 * @param array{errors:int,warnings:int} $counts
 */
function shipcheck_render_text(array $findings, array $counts): string
{
    if ($findings === []) {
        return "shipcheck: no issues\n";
    }

    $lines = [];
    foreach ($findings as $finding) {
        $level = $finding['severity'] === 'warning' ? 'WARNING' : 'ERROR';
        $lines[] = sprintf(
            '%-7s %-20s %s:%d',
            $level,
            $finding['rule'],
            $finding['file'],
            $finding['line']
        );
        $lines[] = '        ' . $finding['message'];
        if ($finding['snippet'] !== '') {
            $lines[] = '        > ' . $finding['snippet'];
        }
        $lines[] = '';
    }

    $lines[] = sprintf(
        'shipcheck: %d %s, %d %s',
        $counts['errors'],
        $counts['errors'] === 1 ? 'error' : 'errors',
        $counts['warnings'],
        $counts['warnings'] === 1 ? 'warning' : 'warnings'
    );

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

$options = shipcheck_options($argv);
$scanner = new Shipcheck();

try {
    $findings = $scanner->scan($options['path']);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}

$counts = $scanner->counts($findings);

if ($options['format'] === 'json') {
    $payload = [
        'version' => Shipcheck::VERSION,
        'errors' => $counts['errors'],
        'warnings' => $counts['warnings'],
        'findings' => $findings,
    ];
    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} else {
    fwrite(STDOUT, shipcheck_render_text($findings, $counts));
}

$failed = $counts['errors'] > 0 || ($options['strict'] && $counts['warnings'] > 0);
exit($failed ? 1 : 0);
