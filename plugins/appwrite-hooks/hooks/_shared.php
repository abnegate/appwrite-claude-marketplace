<?php

declare(strict_types=1);

/**
 * Shared helpers for appwrite-hooks PreToolUse guards.
 *
 * Hook protocol reminder:
 *   - Read JSON from stdin with tool_name / tool_input
 *   - Exit 0 to allow
 *   - Exit 2 with stderr text to block (stderr is surfaced to Claude)
 *
 * Every hook that uses this module gets:
 *   - Metric logging to ~/.claude/metrics/appwrite-hooks.jsonl (opt out via
 *     APPWRITE_HOOKS_NO_METRICS=1).
 *   - Dry-run mode via APPWRITE_HOOKS_DRY_RUN=1. When set, hooks log what
 *     they WOULD have done but exit 0 so nothing actually blocks.
 */

namespace Marketplace\Hook\Shared;

const COMMAND_PREFIXES = ['sudo', 'env', 'nice', 'nohup', 'time'];

/**
 * Parse hook stdin payload. Returns [tool_name, tool_input].
 *
 * Any parse failure is treated as "not our problem" — exit 0 and let the
 * tool run.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function read_tool_input(): array
{
    $stdin = stream_get_contents(STDIN);
    if (!is_string($stdin) || $stdin === '') {
        exit(0);
    }
    try {
        $payload = json_decode($stdin, true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        exit(0);
    }
    if (!is_array($payload)) {
        exit(0);
    }
    $name = is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : '';
    $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
    return [$name, $input];
}

/**
 * Run `git diff --cached --name-only` and return the staged file list.
 *
 * @return string[]
 */
function staged_files(string $cwd): array
{
    $output = run_git(['diff', '--cached', '--name-only'], $cwd, 5);
    if ($output === null) {
        return [];
    }
    $files = [];
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $files[] = $line;
        }
    }
    return $files;
}

function staged_diff(string $cwd): string
{
    return run_git(['diff', '--cached', '--diff-filter=ACMR', '-U0'], $cwd, 10) ?? '';
}

/**
 * @param string[] $arguments
 */
function run_git(array $arguments, string $cwd, int $timeoutSeconds): ?string
{
    $command = array_merge(['git'], $arguments);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open(
        $command,
        $descriptors,
        $pipes,
        $cwd !== '' ? $cwd : null,
    );
    if (!is_resource($process)) {
        return null;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
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
            return null;
        }
        usleep(10_000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        return null;
    }
    return $stdout;
}

/**
 * If the command runs `git <subcommand>`, return the parsed argv (with
 * `git` and the subcommand at the start). Returns null otherwise.
 *
 * @return string[]|null
 */
function extract_git_subcommand(string $command, string $subcommand): ?array
{
    $needle = 'git ' . $subcommand;
    if (!str_contains($command, $needle)) {
        return null;
    }
    $segments = preg_split('/&&|\|\||;|\|/', $command) ?: [];
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if (!str_contains($segment, $needle)) {
            continue;
        }
        $argv = shlex_split($segment);
        if ($argv === null) {
            return loose_parse_git($segment, $subcommand);
        }
        $argv = strip_command_prefix($argv);
        if (count($argv) >= 2 && $argv[0] === 'git' && $argv[1] === $subcommand) {
            return $argv;
        }
    }
    return null;
}

/**
 * @return string[]|null
 */
function extract_git_commit(string $command): ?array
{
    return extract_git_subcommand($command, 'commit');
}

/**
 * @return string[]|null
 */
function extract_git_push(string $command): ?array
{
    return extract_git_subcommand($command, 'push');
}

function extract_commit_message(array $argv): ?string
{
    foreach ($argv as $index => $token) {
        if ($token === '-m' && isset($argv[$index + 1])) {
            return $argv[$index + 1];
        }
        if (str_starts_with($token, '-m') && strlen($token) > 2) {
            return substr($token, 2);
        }
        if (str_starts_with($token, '--message=')) {
            return substr($token, strlen('--message='));
        }
    }
    return null;
}

/**
 * @param string[] $argv
 */
function has_flag(array $argv, string ...$flags): bool
{
    foreach ($argv as $arg) {
        foreach ($flags as $flag) {
            if ($arg === $flag) {
                return true;
            }
            if (str_starts_with($flag, '--') && str_starts_with($arg, $flag . '=')) {
                return true;
            }
            if (
                strlen($flag) === 2
                && $flag[0] === '-'
                && $flag[1] !== '-'
                && str_starts_with($arg, '-')
                && !str_starts_with($arg, '--')
                && str_contains(substr($arg, 1), $flag[1])
            ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Strip leading command wrappers (sudo, env, KEY=VALUE) from argv.
 *
 * @param string[] $argv
 * @return string[]
 */
function strip_command_prefix(array $argv): array
{
    while ($argv !== [] && (in_array($argv[0], COMMAND_PREFIXES, true) || str_contains($argv[0], '='))) {
        array_shift($argv);
    }
    return array_values($argv);
}

/**
 * POSIX-ish shlex split. Returns null when the input is unparseable
 * (mid-quote, dangling escape) so the caller can fall back to loose
 * parsing.
 *
 * @return string[]|null
 */
function shlex_split(string $input): ?array
{
    $tokens = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $hasContent = false;
    $length = strlen($input);

    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];

        if ($char === '\\' && !$inSingle) {
            if ($i + 1 >= $length) {
                return null;
            }
            $next = $input[$i + 1];
            if ($inDouble && !in_array($next, ['"', '\\', '$', '`', "\n"], true)) {
                $buffer .= $char;
            }
            $buffer .= $next;
            $hasContent = true;
            $i++;
            continue;
        }

        if ($char === "'" && !$inDouble) {
            $inSingle = !$inSingle;
            $hasContent = true;
            continue;
        }

        if ($char === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            $hasContent = true;
            continue;
        }

        if (!$inSingle && !$inDouble && ($char === ' ' || $char === "\t" || $char === "\n")) {
            if ($hasContent) {
                $tokens[] = $buffer;
                $buffer = '';
                $hasContent = false;
            }
            continue;
        }

        $buffer .= $char;
        $hasContent = true;
    }

    if ($inSingle || $inDouble) {
        return null;
    }
    if ($hasContent) {
        $tokens[] = $buffer;
    }
    return $tokens;
}

/**
 * Fallback parser for quoted/HEREDOC commands that confuse shlex.
 *
 * @return string[]
 */
function loose_parse_git(string $segment, string $subcommand): array
{
    $argv = ['git', $subcommand];
    foreach (['--no-verify', '--amend', '--force', '--force-with-lease', '-f', '-n'] as $flag) {
        $pattern = '/(?<!\S)' . preg_quote($flag, '/') . '(?!\S)/';
        if (preg_match($pattern, $segment) === 1) {
            $argv[] = $flag;
        }
    }
    if (preg_match("/<<\s*'?EOF'?\s*\n(.*?)\nEOF/s", $segment, $matches) === 1) {
        $argv[] = '-m';
        $argv[] = trim($matches[1]);
        return $argv;
    }
    if (preg_match('/-m\s+"([^"]*)"/', $segment, $matches) === 1) {
        $argv[] = '-m';
        $argv[] = $matches[1];
    } elseif (preg_match("/-m\s+'([^']*)'/", $segment, $matches) === 1) {
        $argv[] = '-m';
        $argv[] = $matches[1];
    }
    if (preg_match_all('/(?<!\S)([\w.\/:@\-]+)(?!\S)/', $segment, $matches) > 0) {
        foreach ($matches[1] as $token) {
            if ($token === 'git' || $token === $subcommand || str_starts_with($token, '-')) {
                continue;
            }
            if (!in_array($token, $argv, true)) {
                $argv[] = $token;
            }
        }
    }
    return $argv;
}

function metrics_dir(): string
{
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    return rtrim($home, '/') . '/.claude/metrics';
}

function metrics_file(): string
{
    return metrics_dir() . '/appwrite-hooks.jsonl';
}

/**
 * @param array<string, mixed> $extra
 */
function log_metric(string $hook, string $tool, string $verdict, string $reason = '', array $extra = []): void
{
    if (getenv('APPWRITE_HOOKS_NO_METRICS') === '1') {
        return;
    }
    $record = [
        'ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP'),
        'hook' => $hook,
        'tool' => $tool,
        'verdict' => $verdict,
    ];
    if ($reason !== '') {
        $record['reason'] = $reason;
    }
    if ($extra !== []) {
        $record = array_merge($record, $extra);
    }
    try {
        $dir = metrics_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents(metrics_file(), json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    } catch (\Throwable) {
        // Metrics are best-effort.
    }
}

function is_dry_run(): bool
{
    return getenv('APPWRITE_HOOKS_DRY_RUN') === '1';
}

function block(string $hook, string $tool, string $message, string $reason = ''): never
{
    if (is_dry_run()) {
        log_metric($hook, $tool, 'would-block', $reason !== '' ? $reason : first_line($message));
        fwrite(STDERR, "[DRY RUN — would block]\n" . $message . "\n");
        exit(0);
    }
    log_metric($hook, $tool, 'blocked', $reason !== '' ? $reason : first_line($message));
    fwrite(STDERR, $message . "\n");
    exit(2);
}

function allow(string $hook = '', string $tool = '', string $reason = ''): never
{
    if ($hook !== '') {
        log_metric($hook, $tool, 'allowed', $reason);
    }
    exit(0);
}

function skip(string $hook = '', string $tool = ''): never
{
    if ($hook !== '') {
        log_metric($hook, $tool, 'skipped');
    }
    exit(0);
}

function first_line(string $text): string
{
    foreach (explode("\n", $text) as $line) {
        $stripped = trim($line);
        if ($stripped !== '') {
            return substr($stripped, 0, 160);
        }
    }
    return '';
}
