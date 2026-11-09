<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Shipcheck.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "  ok  {$message}\n");

        return;
    }

    $failures++;
    fwrite(STDOUT, "  FAIL {$message}\n");
}

/** @param list<array{rule:string,severity:string,file:string,line:int,message:string,snippet:string}> $findings */
function by_rule(array $findings, string $rule): array
{
    return array_values(array_filter(
        $findings,
        static fn (array $finding): bool => $finding['rule'] === $rule
    ));
}

/** @param list<array{rule:string,severity:string,file:string,line:int,message:string,snippet:string}> $findings */
function in_file(array $findings, string $suffix): array
{
    return array_values(array_filter(
        $findings,
        static fn (array $finding): bool => str_ends_with($finding['file'], $suffix)
    ));
}

$root = dirname(__DIR__);
$scanner = new Shipcheck();

fwrite(STDOUT, "dirty fixture\n");
$dirty = $scanner->scan($root . '/tests/fixtures/dirty');
$dirtyCounts = $scanner->counts($dirty);

foreach ($dirty as $finding) {
    fwrite(STDOUT, sprintf(
        "    %s %s %s:%d\n",
        $finding['severity'],
        $finding['rule'],
        $finding['file'],
        $finding['line']
    ));
}

check(count(by_rule($dirty, 'debug-leftover')) === 1, 'one real dd(), comments ignored');
check(in_file(by_rule($dirty, 'debug-leftover'), 'OrderController.php') !== [], 'dd() is in OrderController');
check(count(by_rule($dirty, 'env-outside-config')) === 1, 'env() outside config is flagged once');
check(in_file($dirty, 'config/services.php') === [], 'env() inside config/ is allowed');
check(count(in_file(by_rule($dirty, 'sql-interpolation'), 'UserQuery.php')) === 2, 'two unsafe SQL lines, bound query ignored');
check(count(in_file(by_rule($dirty, 'sql-interpolation'), 'Mysql.php')) === 1, 'mysqli interpolation is flagged');
check(count(by_rule($dirty, 'live-secret')) === 5, 'aws, github, slack, private key, stripe');
check(count(by_rule($dirty, 'debugbar-in-prod')) === 1, 'debugbar in require');
check(count(by_rule($dirty, 'telescope-in-prod')) === 1, 'telescope in require is a warning');
check(by_rule($dirty, 'telescope-in-prod')[0]['severity'] === 'warning', 'telescope severity is warning');
check(count(by_rule($dirty, 'env-not-gitignored')) === 1, 'missing .env gitignore rule');
check(count(by_rule($dirty, 'debug-env-file')) === 1, 'APP_DEBUG in .env.production');
check(count(by_rule($dirty, 'blade-unescaped')) === 1, 'one raw blade echo');
check(count(by_rule($dirty, 'unguarded-model')) === 1, 'empty guarded list');
check(in_file($dirty, 'vendor/pkg/Helper.php') === [], 'vendor is skipped');
check(in_file($dirty, 'tests/Feature/ExampleTest.php') === [], 'tests are skipped');
check($dirtyCounts['errors'] === 13, 'dirty error count is 13, got ' . $dirtyCounts['errors']);
check($dirtyCounts['warnings'] === 3, 'dirty warning count is 3, got ' . $dirtyCounts['warnings']);

fwrite(STDOUT, "clean fixture\n");
$clean = $scanner->scan($root . '/tests/fixtures/clean');
foreach ($clean as $finding) {
    fwrite(STDOUT, sprintf("    %s %s %s:%d %s\n", $finding['severity'], $finding['rule'], $finding['file'], $finding['line'], $finding['snippet']));
}
check($clean === [], 'clean tree has no findings');

fwrite(STDOUT, "warnings fixture\n");
$warnings = $scanner->scan($root . '/tests/fixtures/warnings');
$warningCounts = $scanner->counts($warnings);
check($warningCounts['errors'] === 0, 'warnings fixture has no errors');
check($warningCounts['warnings'] === 2, 'warnings fixture has two warnings, got ' . $warningCounts['warnings']);

fwrite(STDOUT, "self scan\n");
$self = $scanner->scan($root);
foreach ($self as $finding) {
    fwrite(STDOUT, sprintf("    %s %s %s:%d %s\n", $finding['severity'], $finding['rule'], $finding['file'], $finding['line'], $finding['snippet']));
}
check($self === [], 'the shipcheck repo itself is clean');

fwrite(STDOUT, "tracked .env\n");
$temp = sys_get_temp_dir() . '/shipcheck-tracked-' . getmypid();
if (is_dir($temp)) {
    shipcheck_rm($temp);
}
mkdir($temp, 0777, true);
file_put_contents($temp . '/.gitignore', "/.env\n");
file_put_contents($temp . '/.env', "APP_NAME=Tracked\n");
file_put_contents($temp . '/app.php', "<?php\n\$name = 'ok';\n");
$git = 'git -C ' . escapeshellarg($temp);
system($git . ' init -q', $gitCode);
check($gitCode === 0, 'git init in temp fixture');
system($git . ' add -A && ' . $git . ' add -f .env', $addCode);
check($addCode === 0, 'git add tracked .env');
$tracked = $scanner->scan($temp);
$trackedRules = array_column($tracked, 'rule');
check(in_array('env-tracked', $trackedRules, true), 'tracked .env is reported');
check(!in_array('env-not-gitignored', $trackedRules, true), 'gitignore rule still counts');
check(!in_array('debug-env-file', $trackedRules, true), 'local APP_DEBUG is not required for this case');
shipcheck_rm($temp);

fwrite(STDOUT, "cli\n");
$php = escapeshellarg(PHP_BINARY);
$bin = escapeshellarg($root . '/shipcheck.php');

[$cleanCode, $cleanOut, $cleanErr] = shipcheck_run($php . ' ' . $bin . ' ' . escapeshellarg($root . '/tests/fixtures/clean'));
check($cleanCode === 0, 'cli clean exits 0');
check(str_contains($cleanOut, 'no issues'), 'cli clean says no issues');
check($cleanErr === '', 'cli clean has empty stderr');

[$warnCode] = shipcheck_run($php . ' ' . $bin . ' ' . escapeshellarg($root . '/tests/fixtures/warnings'));
check($warnCode === 0, 'warnings do not fail the build');
[$strictCode] = shipcheck_run($php . ' ' . $bin . ' --strict ' . escapeshellarg($root . '/tests/fixtures/warnings'));
check($strictCode === 1, '--strict fails on warnings');

[$dirtyCode, $dirtyOut] = shipcheck_run($php . ' ' . $bin . ' --format=json ' . escapeshellarg($root . '/tests/fixtures/dirty'));
check($dirtyCode === 1, 'cli dirty exits 1');
$json = json_decode($dirtyOut, true);
check(is_array($json), 'json output parses');
check(($json['errors'] ?? null) === 13, 'json error count');
check(($json['warnings'] ?? null) === 3, 'json warning count');
check(($json['version'] ?? null) === Shipcheck::VERSION, 'json version');

[$missingCode, , $missingErr] = shipcheck_run($php . ' ' . $bin . ' ' . escapeshellarg($root . '/does-not-exist'));
check($missingCode === 2, 'missing path exits 2');
check(str_contains($missingErr, 'not a directory'), 'missing path explains why');

[$helpCode, $helpOut] = shipcheck_run($php . ' ' . $bin . ' --help');
check($helpCode === 0, '--help exits 0');
check(str_contains($helpOut, 'shipcheck-ignore'), 'help documents ignore');

[$versionCode, $versionOut] = shipcheck_run($php . ' ' . $bin . ' --version');
check($versionCode === 0 && trim($versionOut) === Shipcheck::VERSION, '--version prints ' . Shipcheck::VERSION);

if ($failures > 0) {
    fwrite(STDOUT, "\n{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "\nall checks passed\n");
exit(0);

/** @return array{0:int,1:string,2:string} */
function shipcheck_run(string $command): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [127, '', 'proc_open failed'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
}

function shipcheck_rm(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            shipcheck_rm($full);
            continue;
        }
        unlink($full);
    }

    rmdir($path);
}
