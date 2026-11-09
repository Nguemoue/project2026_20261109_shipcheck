<?php

declare(strict_types=1);

/**
 * Static checks for PHP / Laravel trees before a production deploy.
 * Reads files only: it never executes the project and never sends data out.
 */
final class Shipcheck
{
    public const VERSION = '1.0.0';

    private const MAX_FILE_BYTES = 700000;

    private const MAX_LINE_CHARS = 4000;

    /** @var list<array{rule:string,severity:string,file:string,line:int,message:string,snippet:string}> */
    private array $findings = [];

    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @return list<array{rule:string,severity:string,file:string,line:int,message:string,snippet:string}>
     */
    public function scan(string $root): array
    {
        $this->findings = [];
        $this->seen = [];

        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Path is not a directory: ' . $root);
        }

        $this->checkGitignore($resolved);
        $this->checkTrackedDotenv($resolved);
        $this->walk($resolved);

        usort(
            $this->findings,
            static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['rule']] <=> [$b['file'], $b['line'], $b['rule']]
        );

        return $this->findings;
    }

    /**
     * @param list<array{rule:string,severity:string,file:string,line:int,message:string,snippet:string}> $findings
     * @return array{errors:int,warnings:int}
     */
    public function counts(array $findings): array
    {
        $errors = 0;
        $warnings = 0;
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'error') {
                $errors++;
            } elseif ($finding['severity'] === 'warning') {
                $warnings++;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function walk(string $root): void
    {
        $directory = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        );
        $filtered = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $current): bool {
                if (!$current->isDir()) {
                    return true;
                }

                return !in_array($current->getFilename(), [
                    'vendor',
                    'node_modules',
                    'storage',
                    'tests',
                    'test',
                    '.git',
                ], true);
            }
        );

        $iterator = new RecursiveIteratorIterator($filtered);
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
            if ($this->skippedRelative($relative)) {
                continue;
            }

            $this->scanFile($relative, $path);
        }
    }

    private function skippedRelative(string $relative): bool
    {
        if (
            str_starts_with($relative, 'bootstrap/cache/')
            || str_starts_with($relative, 'public/build/')
            || $relative === 'public/hot'
        ) {
            return true;
        }

        return in_array(basename($relative), [
            'composer.lock',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ], true);
    }

    private function scanFile(string $relative, string $path): void
    {
        $base = basename($relative);

        if ($base === 'composer.json') {
            $this->checkComposer($relative, $path);
        }

        if ($this->isExampleEnv($base) || $this->skippedExtension($base)) {
            return;
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return;
        }

        $contents = file_get_contents($path);
        if ($contents === false || str_contains(substr($contents, 0, 1024), "\0")) {
            return;
        }

        if ($base === '.env' || preg_match('/^\.env\.(production|prod|staging)$/', $base) === 1) {
            $this->checkEnvFile($relative, $contents, $base);
            $this->scanLooseSecrets($relative, $contents);

            return;
        }

        if (str_ends_with($base, '.php')) {
            $this->scanSource($relative, $contents, str_ends_with($base, '.blade.php'));

            return;
        }

        $this->scanLooseSecrets($relative, $contents);
    }

    private function isExampleEnv(string $base): bool
    {
        return in_array($base, ['.env.example', '.env.sample', '.env.dist', '.env.testing'], true)
            || str_ends_with($base, '.example')
            || str_ends_with($base, '.sample')
            || str_ends_with($base, '.dist');
    }

    private function skippedExtension(string $base): bool
    {
        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));

        return in_array($extension, [
            'md',
            'markdown',
            'png',
            'jpg',
            'jpeg',
            'gif',
            'webp',
            'ico',
            'svg',
            'woff',
            'woff2',
            'ttf',
            'eot',
            'map',
            'lock',
            'zip',
            'gz',
            'tgz',
            'phar',
            'exe',
            'so',
            'dll',
            'pdf',
        ], true);
    }

    private function checkGitignore(string $root): void
    {
        $path = $root . DIRECTORY_SEPARATOR . '.gitignore';
        if (!is_file($path)) {
            $this->add(
                'env-not-gitignored',
                'error',
                '.gitignore',
                1,
                $this->message('env-not-gitignored'),
                '(file missing)'
            );

            return;
        }

        if (!$this->gitignoreCoversEnv($path)) {
            $this->add(
                'env-not-gitignored',
                'error',
                '.gitignore',
                1,
                $this->message('env-not-gitignored'),
                '(no .env rule)'
            );
        }
    }

    private function gitignoreCoversEnv(string $path): bool
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        $covers = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $negated = false;
            if (str_starts_with($line, '!')) {
                $negated = true;
                $line = substr($line, 1);
            }

            $line = ltrim($line, '/');
            if (!$this->ruleCoversEnv($line)) {
                continue;
            }

            $covers = !$negated;
        }

        return $covers;
    }

    private function ruleCoversEnv(string $line): bool
    {
        return $line === '.env'
            || $line === '.env.*'
            || $line === '.env*'
            || str_ends_with($line, '/.env');
    }

    private function checkTrackedDotenv(string $root): void
    {
        if (!is_dir($root . DIRECTORY_SEPARATOR . '.git') || !function_exists('shell_exec')) {
            return;
        }

        $command = 'git -C ' . escapeshellarg($root) . ' ls-files -- .env .env.production .env.prod .env.staging 2>/dev/null';
        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            return;
        }

        foreach (preg_split('/\R/', trim($output)) ?: [] as $relative) {
            if ($relative === '') {
                continue;
            }

            $this->add(
                'env-tracked',
                'error',
                str_replace('\\', '/', $relative),
                1,
                $this->message('env-tracked'),
                '(tracked by git)'
            );
        }
    }

    private function checkComposer(string $relative, string $path): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return;
        }

        $json = json_decode($contents, true);
        if (!is_array($json)) {
            $this->add(
                'composer-invalid',
                'warning',
                $relative,
                1,
                $this->message('composer-invalid'),
                'composer.json'
            );

            return;
        }

        $require = $json['require'] ?? [];
        if (!is_array($require)) {
            return;
        }

        $rules = [
            'barryvdh/laravel-debugbar' => ['debugbar-in-prod', 'error'],
            'laravel/telescope' => ['telescope-in-prod', 'warning'],
        ];

        foreach ($rules as $package => [$rule, $severity]) {
            if (!array_key_exists($package, $require)) {
                continue;
            }

            $this->add(
                $rule,
                $severity,
                $relative,
                $this->packageLine($contents, $package),
                $this->message($rule),
                $package
            );
        }
    }

    private function packageLine(string $contents, string $package): int
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $section = null;
        $depth = 0;

        foreach ($lines as $index => $line) {
            if ($depth === 1 && preg_match('/"(require(?:-dev)?)"\s*:/', $line, $matches) === 1) {
                $section = $matches[1];
            }

            if ($section === 'require' && str_contains($line, '"' . $package . '"')) {
                return $index + 1;
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
            if ($depth < 0) {
                $depth = 0;
            }
        }

        return 1;
    }

    private function checkEnvFile(string $relative, string $contents, string $base): void
    {
        if ($base === '.env') {
            return;
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*APP_DEBUG\s*=\s*(?:true|1|"true"|\'true\')\s*(?:#.*)?$/i', $line) !== 1) {
                continue;
            }

            $this->add(
                'debug-env-file',
                'error',
                $relative,
                $index + 1,
                $this->message('debug-env-file'),
                trim($line)
            );
        }
    }

    private function scanSource(string $relative, string $contents, bool $blade): void
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $inBlock = false;
        $inBlade = false;
        $ignoreNext = false;

        foreach ($lines as $index => $line) {
            $skip = $ignoreNext;
            $ignoreNext = false;
            $code = $this->visibleCode($line, $inBlock, $inBlade);

            if (str_contains($line, 'shipcheck-ignore')) {
                $skip = true;
                $residue = trim(str_replace('shipcheck-ignore', '', $code));
                if ($residue === '') {
                    $ignoreNext = true;
                }
            }

            if ($skip || trim($code) === '') {
                continue;
            }

            if (strlen($code) > self::MAX_LINE_CHARS) {
                $code = substr($code, 0, self::MAX_LINE_CHARS);
            }

            $this->inspectCode($relative, $index + 1, $line, $code, $blade);
        }
    }

    private function inspectCode(string $relative, int $lineNo, string $rawLine, string $code, bool $blade): void
    {
        $outside = $this->maskStrings($code);
        $snippet = $this->snippet($rawLine);

        if (preg_match('/\b(?:dd|ddd|ray|var_dump)\s*\(/', $outside) === 1) {
            $this->add('debug-leftover', 'error', $relative, $lineNo, $this->message('debug-leftover'), $snippet);
        } elseif (preg_match('/\b(?:dump|print_r)\s*\(/', $outside) === 1) {
            $this->add('debug-noise', 'warning', $relative, $lineNo, $this->message('debug-noise'), $snippet);
        }

        if (!$this->envAllowed($relative) && preg_match('/\benv\s*\(/', $outside) === 1) {
            $this->add('env-outside-config', 'error', $relative, $lineNo, $this->message('env-outside-config'), $snippet);
        }

        if ($this->hasSqlInjection($code)) {
            $this->add('sql-interpolation', 'error', $relative, $lineNo, $this->message('sql-interpolation'), $snippet);
        }

        if (
            preg_match('/\$guarded\s*=\s*(?:\[\s*\]|array\s*\(\s*\))/', $outside) === 1
            || preg_match('/\bModel::unguard\s*\(/', $outside) === 1
        ) {
            $this->add('unguarded-model', 'warning', $relative, $lineNo, $this->message('unguarded-model'), $snippet);
        }

        if ($blade && str_contains($code, '{!!')) {
            $this->add('blade-unescaped', 'warning', $relative, $lineNo, $this->message('blade-unescaped'), $snippet);
        }

        if ($this->hasSecret($code)) {
            $this->add('live-secret', 'error', $relative, $lineNo, $this->message('live-secret'), $snippet);
        }
    }

    private function envAllowed(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        return $relative === 'config' || str_starts_with($relative, 'config/');
    }

    private function scanLooseSecrets(string $relative, string $contents): void
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        foreach ($lines as $index => $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#') || str_contains($line, 'shipcheck-ignore')) {
                continue;
            }

            if (!$this->hasSecret($line)) {
                continue;
            }

            $this->add(
                'live-secret',
                'error',
                $relative,
                $index + 1,
                $this->message('live-secret'),
                $this->snippet($line)
            );
        }
    }

    private function hasSqlInjection(string $code): bool
    {
        $call = '(?:DB::(?:raw|select|insert|update|delete|statement|unprepared)|->(?:whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|joinRaw|query))';
        $inside = '(["\'])(?:\\\\.|(?!\1).)*';

        if (preg_match('/' . $call . '\s*\(\s*' . $inside . '\$(?:[A-Za-z_\{])/', $code) === 1) {
            return true;
        }

        if (preg_match('/' . $call . '\s*\(\s*["\'][^"\']*["\']\s*\./', $code) === 1) {
            return true;
        }

        if (preg_match('/mysqli_query\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*' . $inside . '\$(?:[A-Za-z_\{])/', $code) === 1) {
            return true;
        }

        return preg_match('/mysqli_query\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*["\'][^"\']*["\']\s*\./', $code) === 1;
    }

    private function hasSecret(string $code): bool
    {
        $patterns = [
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bsk_live_[A-Za-z0-9]{16,}\b/',
            '/\bghp_[A-Za-z0-9]{20,}\b/',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
            '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        // Split so this source file does not contain a contiguous private-key header.
        return preg_match('/-----' . 'BEGIN [A-Z ]*PRIVATE KEY' . '-----/', $code) === 1;
    }

    private function visibleCode(string $line, bool &$inBlock, bool &$inBlade): string
    {
        if ($inBlade) {
            $end = strpos($line, '--}}');
            if ($end === false) {
                return '';
            }
            $inBlade = false;
            $line = substr($line, $end + 4);
        }

        if ($inBlock) {
            $end = strpos($line, '*/');
            if ($end === false) {
                return '';
            }
            $inBlock = false;
            $line = substr($line, $end + 2);
        }

        $out = '';
        $length = strlen($line);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $c = $line[$i];
            $next = $i + 1 < $length ? $line[$i + 1] : '';

            if ($quote !== null) {
                $out .= $c;
                if ($c === '\\' && $quote === '"') {
                    $i++;
                    if ($i < $length) {
                        $out .= $line[$i];
                    }
                    continue;
                }
                if ($c === '\\' && $quote === "'" && ($next === "'" || $next === '\\')) {
                    $i++;
                    $out .= $line[$i];
                    continue;
                }
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $out .= $c;
                continue;
            }

            if ($c === '/' && $next === '/') {
                break;
            }

            if ($c === '#' && ($i === 0 || ctype_space($line[$i - 1]))) {
                break;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($line, '*/', $i + 2);
                if ($end === false) {
                    $inBlock = true;
                    break;
                }
                $i = $end + 1;
                continue;
            }

            if ($c === '{' && substr($line, $i, 4) === '{{--') {
                $end = strpos($line, '--}}', $i + 4);
                if ($end === false) {
                    $inBlade = true;
                    break;
                }
                $i = $end + 3;
                continue;
            }

            $out .= $c;
        }

        return $out;
    }

    private function maskStrings(string $code): string
    {
        $masked = preg_replace("/'(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"/", "''", $code);

        return $masked ?? $code;
    }

    private function snippet(string $rawLine): string
    {
        $snippet = trim($rawLine);
        if (strlen($snippet) > 160) {
            return substr($snippet, 0, 157) . '...';
        }

        return $snippet;
    }

    private function message(string $rule): string
    {
        return match ($rule) {
            'debug-leftover' => 'Debug call left in the code. It can leak data or kill the request in production.',
            'debug-noise' => 'dump() or print_r() still writes internal data. Remove it or mark the line with shipcheck-ignore.',
            'env-outside-config' => 'env() outside config/. Read config() instead, or config:cache will break this call.',
            'sql-interpolation' => 'SQL built with interpolation or concatenation. Use a prepared statement and bindings.',
            'live-secret' => 'Possible live secret. Rotate it if this file was ever shared or committed.',
            'debugbar-in-prod' => 'laravel-debugbar is in composer require. Move it to require-dev so it cannot ship.',
            'env-not-gitignored' => '.gitignore does not ignore .env. A local secret file can be committed by accident.',
            'env-tracked' => '.env is tracked by git. Remove it from the index and rotate those secrets.',
            'debug-env-file' => 'APP_DEBUG is enabled in a production or staging env file.',
            'blade-unescaped' => 'Raw Blade output {!! !!}. Prefer {{ }} unless this HTML is fully trusted.',
            'unguarded-model' => 'Mass assignment is wide open. Set $fillable, or a real $guarded list.',
            'telescope-in-prod' => 'laravel/telescope is in composer require. Prefer require-dev unless production access is locked down.',
            'composer-invalid' => 'composer.json could not be parsed.',
            default => $rule,
        };
    }

    private function add(string $rule, string $severity, string $file, int $line, string $message, string $snippet): void
    {
        $key = $rule . '|' . $file . '|' . $line;
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;

        $this->findings[] = [
            'rule' => $rule,
            'severity' => $severity,
            'file' => $file,
            'line' => $line,
            'message' => $message,
            'snippet' => $snippet,
        ];
    }
}
