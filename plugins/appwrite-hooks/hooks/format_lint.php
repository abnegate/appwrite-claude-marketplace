<?php

declare(strict_types=1);

/**
 * Run format + lint before allowing a commit.
 *
 * Detects the ecosystem from staged files and runs the matching tool:
 *   *.php                    -> composer lint
 *   *.kt / build.gradle(.kts) -> ktlint / gradlew ktlintCheck
 *   *.rs / Cargo.toml         -> cargo fmt --check && cargo clippy -D warnings
 *   *.ts / *.tsx / *.js       -> npx prettier --check
 *   *.py                      -> ruff check
 *
 * Escape hatch: APPWRITE_HOOKS_SKIP_LINT=1.
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\extract_git_commit;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\skip;
use function Marketplace\Hook\Shared\staged_files;

const HOOK = 'format_lint';

[$toolName, $toolInput] = read_tool_input();

if (getenv('APPWRITE_HOOKS_SKIP_LINT') === '1') {
    skip(HOOK, $toolName);
}

if ($toolName !== 'Bash') {
    skip(HOOK, $toolName);
}

$argv = extract_git_commit((string) ($toolInput['command'] ?? ''));
if ($argv === null) {
    skip(HOOK, $toolName);
}

$cwd = (string) ($toolInput['cwd'] ?? '');
$files = staged_files($cwd);
if ($files === []) {
    skip(HOOK, $toolName);
}

$repoRoot = find_repo_root($cwd);
$results = [];

if (any_match($files, ['.php']) && is_file($repoRoot . '/composer.json')) {
    $results[] = run_lint(['composer', 'lint'], $repoRoot, 'composer lint');
}

if (any_match($files, ['.kt', '.kts'])) {
    if (is_file($repoRoot . '/gradlew')) {
        $results[] = run_lint(['./gradlew', 'ktlintCheck'], $repoRoot, 'ktlintCheck');
    } elseif (which('ktlint') !== null) {
        $results[] = run_lint(['ktlint', '--format'], $repoRoot, 'ktlint');
    }
}

if (any_match($files, ['.rs']) && is_file($repoRoot . '/Cargo.toml')) {
    $results[] = run_lint(['cargo', 'fmt', '--check'], $repoRoot, 'cargo fmt');
    $results[] = run_lint(['cargo', 'clippy', '--all-targets', '--', '-D', 'warnings'], $repoRoot, 'cargo clippy');
}

if (any_match($files, ['.ts', '.tsx', '.js', '.jsx', '.mjs', '.cjs']) && is_file($repoRoot . '/package.json') && which('npx') !== null) {
    $results[] = run_lint(['npx', '--no-install', 'prettier', '--check', '.'], $repoRoot, 'prettier');
}

if (any_match($files, ['.py']) && which('ruff') !== null) {
    $results[] = run_lint(['ruff', 'check', '.'], $repoRoot, 'ruff');
}

$failures = [];
foreach ($results as [$ok, $output]) {
    if (!$ok) {
        $failures[] = $output;
    }
}

if ($failures !== []) {
    block(
        HOOK,
        $toolName,
        "BLOCKED: format/lint checks failed. Fix and retry.\n\n"
        . implode("\n\n", $failures)
        . "\n\n(Override with APPWRITE_HOOKS_SKIP_LINT=1 if strictly necessary.)",
        reason: 'lint failed',
    );
}

allow(HOOK, $toolName);

function find_repo_root(string $start): string
{
    $current = realpath($start !== '' ? $start : '.') ?: getcwd() ?: '/';
    while (true) {
        if (is_dir($current . '/.git') || is_file($current . '/.git')) {
            return $current;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            return realpath($start !== '' ? $start : '.') ?: getcwd() ?: '/';
        }
        $current = $parent;
    }
}

/**
 * @param string[] $files
 * @param string[] $suffixes
 */
function any_match(array $files, array $suffixes): bool
{
    foreach ($files as $file) {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Locate a binary on PATH without invoking a shell.
 */
function which(string $binary): ?string
{
    if (str_contains($binary, '/')) {
        return is_executable($binary) ? $binary : null;
    }
    $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        $candidate = rtrim($dir, '/') . '/' . $binary;
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * @param string[] $command
 * @return array{0: bool, 1: string}
 */
function run_lint(array $command, string $cwd, string $label): array
{
    if (which($command[0]) === null) {
        return [true, sprintf('%s: skipped (%s not on PATH)', $label, $command[0])];
    }
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return [true, sprintf('%s: skipped (failed to spawn)', $label)];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 120;
    while (true) {
        $status = proc_get_status($process);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return [false, sprintf('%s: timed out after 120s', $label)];
        }
        usleep(20_000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit === 0) {
        return [true, sprintf('%s: ok', $label)];
    }
    return [false, sprintf("%s: FAILED\n--- stdout ---\n%s\n--- stderr ---\n%s", $label, trim($stdout), trim($stderr))];
}
