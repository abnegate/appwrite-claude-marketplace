<?php

declare(strict_types=1);

/**
 * Block force-push to protected branches.
 *
 * Catches `--force`, `-f`, `--force-with-lease`, `--force-if-includes`,
 * and leading-`+` refspecs targeting:
 *   - main, master, trunk, develop
 *   - version branches like `1.9.x`
 *   - release/* and hotfix/*
 *
 * Escape hatch: APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1.
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\extract_git_push;
use function Marketplace\Hook\Shared\has_flag;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\skip;

const HOOK = 'force_push_guard';

const PROTECTED_BRANCH_PATTERNS = [
    '/^(main|master|trunk|develop)$/',
    '/^\d+\.\d+\.x$/',
    '#^release[/\-].+$#',
    '#^hotfix[/\-].+$#',
];

const FORCE_FLAGS = ['--force', '-f', '--force-with-lease', '--force-if-includes'];

[$toolName, $toolInput] = read_tool_input();
if ($toolName !== 'Bash') {
    skip(HOOK, $toolName);
}

$argv = extract_git_push((string) ($toolInput['command'] ?? ''));
if ($argv === null) {
    skip(HOOK, $toolName);
}

if (getenv('APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH') === '1') {
    allow(HOOK, $toolName, 'opt-out-unsafe-push');
}

$hasForce = has_flag($argv, ...FORCE_FLAGS);
foreach (array_slice($argv, 2) as $token) {
    if (str_starts_with($token, '-')) {
        continue;
    }
    if (str_contains($token, ':') && str_starts_with(explode(':', $token)[0], '+')) {
        $hasForce = true;
        break;
    }
    if (str_starts_with($token, '+')) {
        $hasForce = true;
        break;
    }
}

if (!$hasForce) {
    allow(HOOK, $toolName);
}

$target = targets_protected_branch($argv);

if ($target === null && pushes_without_explicit_target($argv)) {
    block(
        HOOK,
        $toolName,
        'BLOCKED: force-push with no explicit target. Cannot verify the '
        . 'current upstream is safe to rewrite. Specify the target branch '
        . 'explicitly or set APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1 if you are sure.',
        reason: 'force-push with implicit target',
    );
}

if ($target === null) {
    allow(HOOK, $toolName, 'force-push to non-protected branch');
}

block(
    HOOK,
    $toolName,
    sprintf("BLOCKED: force-push to protected branch `%s`.\n\n", $target)
    . "The global rules forbid rewriting history on main/master/trunk/develop "
    . "and on version branches like 1.9.x. Force-pushing these breaks every "
    . "other clone that has fetched them.\n\n"
    . 'If the user has explicitly authorized this force-push, set '
    . '`APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1` for the command.',
    reason: sprintf('force-push to %s', $target),
);

/**
 * @param string[] $argv
 */
function targets_protected_branch(array $argv): ?string
{
    $positionalCount = 0;
    foreach (array_slice($argv, 2) as $token) {
        if (str_starts_with($token, '-')) {
            continue;
        }
        $positionalCount++;
        if ($positionalCount === 1) {
            continue;
        }
        $refspec = ltrim($token, '+');
        $parts = explode(':', $refspec, 2);
        $remoteRef = $parts[count($parts) - 1];
        $remoteBranch = $remoteRef;
        if (str_starts_with($remoteBranch, 'refs/heads/')) {
            $remoteBranch = substr($remoteBranch, strlen('refs/heads/'));
        }
        foreach (PROTECTED_BRANCH_PATTERNS as $pattern) {
            if (preg_match($pattern, $remoteBranch) === 1) {
                return $remoteBranch;
            }
        }
    }
    return null;
}

/**
 * @param string[] $argv
 */
function pushes_without_explicit_target(array $argv): bool
{
    $positional = [];
    foreach (array_slice($argv, 2) as $token) {
        if (!str_starts_with($token, '-')) {
            $positional[] = $token;
        }
    }
    return count($positional) <= 1;
}
