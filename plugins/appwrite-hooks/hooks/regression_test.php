<?php

declare(strict_types=1);

/**
 * Require a staged test file when committing a `(fix):` change.
 *
 * Half-enforces the global rule "every bug fix must include a regression
 * test" — checks that at least one staged file matches a test pattern.
 *
 * Escape hatch: APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1.
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\extract_commit_message;
use function Marketplace\Hook\Shared\extract_git_commit;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\skip;
use function Marketplace\Hook\Shared\staged_files;

const HOOK = 'regression_test';

const FIX_PREFIX = '/^\(fix\): /i';

const TEST_PATH_PATTERNS = [
    '#(^|/)tests?/#',
    '/Test\.(php|kt|java|cs|scala|groovy)$/',
    '/_test\.(go|py|rb|ts|tsx|js|jsx)$/',
    '/\.spec\.(ts|tsx|js|jsx)$/',
    '/_spec\.(rb|py)$/',
    '#(^|/)__tests__/#',
    '#(^|/)spec/#',
];

[$toolName, $toolInput] = read_tool_input();
if ($toolName !== 'Bash') {
    skip(HOOK, $toolName);
}

$argv = extract_git_commit((string) ($toolInput['command'] ?? ''));
if ($argv === null) {
    skip(HOOK, $toolName);
}

$message = extract_commit_message($argv);
if ($message === null || trim($message) === '') {
    skip(HOOK, $toolName);
}

$lines = explode("\n", trim($message));
$firstLine = $lines[0] ?? '';
if (preg_match(FIX_PREFIX, $firstLine) !== 1) {
    skip(HOOK, $toolName);
}

if (getenv('APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST') === '1') {
    allow(HOOK, $toolName, 'opt-out-allow-fix-without-test');
}

$cwd = (string) ($toolInput['cwd'] ?? '');
$files = staged_files($cwd);
if ($files === []) {
    skip(HOOK, $toolName);
}

foreach ($files as $file) {
    if (is_test_path($file)) {
        allow(HOOK, $toolName, 'test file staged');
    }
}

$preview = '';
foreach (array_slice($files, 0, 10) as $file) {
    $preview .= sprintf("    %s\n", $file);
}
if (count($files) > 10) {
    $preview .= sprintf("    ... and %d more\n", count($files) - 10);
}

block(
    HOOK,
    $toolName,
    "BLOCKED: `(fix):` commit with no regression test staged.\n\n"
    . "Global rule: \"Every bug fix must include a regression test that fails "
    . "without the fix and passes with it.\"\n\n"
    . sprintf("Staged files:\n%s\n", $preview)
    . 'Add a test that reproduces the bug, stage it, and retry. If this '
    . 'genuinely does not need a test (e.g. doc typo fix — should '
    . 'probably be `(docs):` instead), override with '
    . '`APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1`.',
    reason: 'fix commit with no test staged',
);

function is_test_path(string $path): bool
{
    foreach (TEST_PATH_PATTERNS as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            return true;
        }
    }
    return false;
}
