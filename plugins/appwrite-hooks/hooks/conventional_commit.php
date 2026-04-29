<?php

declare(strict_types=1);

/**
 * Enforce commit discipline: no --no-verify/--amend, conventional format.
 *
 * Two checks that both parse `git commit` argv:
 *   1. Flag guard — blocks --no-verify / --amend (override:
 *      APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1).
 *   2. Message format — requires `(type): subject`.
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\extract_commit_message;
use function Marketplace\Hook\Shared\extract_git_commit;
use function Marketplace\Hook\Shared\has_flag;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\skip;

const HOOK = 'conventional_commit';
const ALLOWED_TYPES = [
    'feat', 'fix', 'refactor', 'chore', 'docs', 'test', 'style',
    'perf', 'revert', 'ci', 'cleanup', 'improvement', 'build',
];

[$toolName, $toolInput] = read_tool_input();
if ($toolName !== 'Bash') {
    skip(HOOK, $toolName);
}

$argv = extract_git_commit((string) ($toolInput['command'] ?? ''));
if ($argv === null) {
    skip(HOOK, $toolName);
}

if (getenv('APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT') !== '1') {
    if (has_flag($argv, '--no-verify', '-n')) {
        block(
            HOOK,
            $toolName,
            "BLOCKED: `git commit --no-verify` skips the format/lint/test guards. "
            . "Fix the underlying issue instead of bypassing.\n\n"
            . 'Override: APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1',
            reason: 'no-verify flag',
        );
    }
    if (has_flag($argv, '--amend')) {
        block(
            HOOK,
            $toolName,
            "BLOCKED: `git commit --amend` rewrites the previous commit. "
            . "After a hook failure, create a new commit instead.\n\n"
            . 'Override: APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1',
            reason: 'amend flag',
        );
    }
}

$message = extract_commit_message($argv);
if ($message === null) {
    skip(HOOK, $toolName);
}

$lines = explode("\n", trim($message));
$firstLine = $lines[0] ?? '';

if ($firstLine === '') {
    block(
        HOOK,
        $toolName,
        'BLOCKED: empty commit message. Use `(type): subject` format, where type is one of: '
        . implode(', ', ALLOWED_TYPES) . '.',
        reason: 'empty message',
    );
}

$conventional = '/^\((' . implode('|', ALLOWED_TYPES) . ')\): .+/i';
$merge = '/^(Merge|Revert) /i';

if (preg_match($merge, $firstLine) === 1 || preg_match($conventional, $firstLine) === 1) {
    allow(HOOK, $toolName);
}

block(
    HOOK,
    $toolName,
    "BLOCKED: commit message does not follow conventional format.\n\n"
    . sprintf("  Got:      '%s'\n", $firstLine)
    . "  Expected: (type): subject\n"
    . '  Types:    ' . implode(', ', ALLOWED_TYPES) . "\n\n"
    . "Examples:\n"
    . "  (feat): add gitea webhook handler\n"
    . "  (fix): guard against null billing plan cache\n"
    . "  (refactor): extract publisher resource wiring\n\n"
    . 'Rewrite the commit message and retry.',
    reason: 'non-conventional format',
);
