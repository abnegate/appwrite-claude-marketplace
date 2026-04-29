<?php

declare(strict_types=1);

/**
 * Block `rm -rf` on system and home directories.
 *
 * Catches the highest-impact foot-gun: recursive force-delete of paths
 * that are unrecoverable. Allows `rm -rf` on known scratch targets and
 * deep relative paths.
 *
 * Escape hatch: APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1.
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\shlex_split;
use function Marketplace\Hook\Shared\skip;
use function Marketplace\Hook\Shared\strip_command_prefix;

const HOOK = 'destructive_guard';

const FORBIDDEN_ABSOLUTE = [
    '/', '/*', '/.', '/..',
    '/home', '/home/', '/home/*',
    '/Users', '/Users/', '/Users/*',
    '/root', '/etc', '/var', '/usr', '/bin', '/sbin', '/opt', '/lib',
    '/System', '/Library', '/Applications',
    '/boot', '/dev', '/proc', '/sys', '/run',
];

const SAFE_PATH_PREFIXES = [
    'node_modules', './node_modules', 'dist', './dist',
    'build', './build', '.next', './.next',
    'target', './target', '.cache', './.cache',
    'coverage', './coverage', '.pytest_cache', './.pytest_cache',
    '__pycache__', './__pycache__', '.nyc_output', './.nyc_output',
    'vendor', './vendor',
    '/tmp/', 'tmp/',
];

const SAFE_PATH_SUFFIXES = ['.log', '.tmp', '.cache', '.lock', '.pid'];

[$toolName, $toolInput] = read_tool_input();
if ($toolName !== 'Bash') {
    skip(HOOK, $toolName);
}

$command = trim((string) ($toolInput['command'] ?? ''));
if ($command === '') {
    skip(HOOK, $toolName);
}

if (getenv('APPWRITE_HOOKS_ALLOW_DESTRUCTIVE') === '1') {
    allow(HOOK, $toolName, 'opt-out-allow-destructive');
}

foreach (preg_split('/&&|\|\||;|\|/', $command) ?: [] as $segment) {
    $reason = check_rm_rf(trim($segment));
    if ($reason !== '') {
        block(
            HOOK,
            $toolName,
            "BLOCKED: refusing to run a destructive command.\n\n"
            . sprintf("  Segment: %s\n", trim($segment))
            . sprintf("  Reason:  %s\n\n", $reason)
            . 'Override: APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1',
            reason: $reason,
        );
    }
}

allow(HOOK, $toolName);

function check_rm_rf(string $segment): string
{
    if ($segment === '') {
        return '';
    }
    $argv = shlex_split($segment);
    if ($argv === null || $argv === []) {
        return '';
    }
    $argv = strip_command_prefix($argv);
    if ($argv === [] || $argv[0] !== 'rm') {
        return '';
    }
    $flags = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '-')) {
            $flags[] = $arg;
        }
    }
    $isRecursive = false;
    $isForce = false;
    foreach ($flags as $flag) {
        if ($flag === '--recursive') {
            $isRecursive = true;
        }
        if ($flag === '--force') {
            $isForce = true;
        }
        if (!str_starts_with($flag, '--')) {
            $body = substr($flag, 1);
            if (str_contains($body, 'r') || str_contains($body, 'R')) {
                $isRecursive = true;
            }
            if (str_contains($body, 'f')) {
                $isForce = true;
            }
        }
    }
    if (!($isRecursive && $isForce)) {
        return '';
    }
    $targets = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '-')) {
            $targets[] = $arg;
        }
    }
    if ($targets === []) {
        return 'rm -rf with no explicit target (possible empty-variable expansion)';
    }
    foreach ($targets as $target) {
        $reason = path_danger($target);
        if ($reason !== '') {
            return $reason;
        }
    }
    return '';
}

function path_danger(string $target): string
{
    $stripped = rtrim($target, '/');
    if ($stripped === '') {
        $stripped = '/';
    }

    if (str_contains($stripped, '$')) {
        if (str_starts_with($stripped, '$TMPDIR') || str_starts_with($stripped, '${TMPDIR}')) {
            if (!str_contains($stripped, '/..')) {
                return '';
            }
        }
        return sprintf('path contains variable expansion: %s', $target);
    }

    if (str_starts_with($stripped, '~')) {
        return sprintf('path targets home directory: %s', $target);
    }

    if (in_array($stripped, FORBIDDEN_ABSOLUTE, true) || in_array($stripped . '/', FORBIDDEN_ABSOLUTE, true)) {
        return sprintf('path is a system directory: %s', $target);
    }

    if (preg_match('#^(?:\./|/)?(\*|\*/)#', $target) === 1) {
        return sprintf('glob at root of path: %s', $target);
    }

    if (str_starts_with($stripped, '/')) {
        foreach (SAFE_PATH_PREFIXES as $prefix) {
            if (!str_starts_with($prefix, '/')) {
                continue;
            }
            $prefixStripped = rtrim($prefix, '/');
            if ($stripped === $prefixStripped || str_starts_with($stripped, $prefix)) {
                return '';
            }
        }
        return sprintf('absolute path outside safe zones: %s', $target);
    }

    $normalized = ltrim($stripped, './');
    foreach (SAFE_PATH_PREFIXES as $prefix) {
        $bare = ltrim($prefix, './');
        if ($normalized === $bare || str_starts_with($normalized, $bare . '/')) {
            return '';
        }
    }
    foreach (SAFE_PATH_SUFFIXES as $suffix) {
        if (str_ends_with($normalized, $suffix)) {
            return '';
        }
    }

    if (!str_contains($normalized, '/')) {
        return sprintf('relative top-level path: %s', $target);
    }

    return '';
}
