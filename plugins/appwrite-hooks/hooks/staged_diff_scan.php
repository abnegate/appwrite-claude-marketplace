<?php

declare(strict_types=1);

/**
 * Scan staged diffs for conflict markers and temporary-code markers.
 *
 * One `git diff --cached` call, two scans:
 *   1. Conflict markers (no override).
 *   2. TEMP/HACK/FIXME/XXX/DO NOT MERGE/console.log
 *      (override: APPWRITE_HOOKS_ALLOW_TEMP_CODE=1).
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\extract_git_commit;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\skip;
use function Marketplace\Hook\Shared\staged_diff;

const HOOK = 'staged_diff_scan';

const CONFLICT_PATTERN = '/^(<{7}|={7}|>{7})(\s|$)/m';
const TEMP_MARKERS = '/(TEMP[\s:\/-]|HACK[\s:\/-]|FIXME[\s:\/-]|XXX[\s:\/-]|DO\s+NOT\s+MERGE|DO\s+NOT\s+COMMIT|DO\s+NOT\s+SHIP)/i';
const CONSOLE_LOG_PATTERN = '/console\.log\s*\(/';
const CONSOLE_LOG_EXTENSIONS = ['.ts', '.tsx', '.js', '.jsx', '.svelte', '.mjs', '.cjs'];

const EXEMPT_ALL = [
    '/\.(md|txt|rst)$/i',
    '/staged_diff_scan\.(php|py)$/',
    '/test_hooks\.(php|py)$/',
];
const EXEMPT_TEMP_EXTRA = [
    '/CHANGELOG\.md$/',
];

[$toolName, $toolInput] = read_tool_input();
if ($toolName !== 'Bash') {
    skip(HOOK, $toolName);
}

$argv = extract_git_commit((string) ($toolInput['command'] ?? ''));
if ($argv === null) {
    skip(HOOK, $toolName);
}

$diff = staged_diff((string) ($toolInput['cwd'] ?? ''));
if ($diff === '') {
    skip(HOOK, $toolName);
}

$skipTemp = getenv('APPWRITE_HOOKS_ALLOW_TEMP_CODE') === '1';
$conflictFindings = [];
$tempFindings = [];
$currentFile = '';

foreach (explode("\n", $diff) as $line) {
    if (str_starts_with($line, '+++ b/')) {
        $currentFile = substr($line, 6);
        continue;
    }
    if (!str_starts_with($line, '+') || str_starts_with($line, '+++')) {
        continue;
    }
    $added = substr($line, 1);
    $exemptAll = false;
    foreach (EXEMPT_ALL as $pattern) {
        if (preg_match($pattern, $currentFile) === 1) {
            $exemptAll = true;
            break;
        }
    }
    if (!$exemptAll && preg_match(CONFLICT_PATTERN, $added) === 1) {
        $conflictFindings[] = sprintf('  %s: %s', $currentFile, substr(trim($added), 0, 80));
    }
    if (!$skipTemp && !$exemptAll) {
        $exemptTemp = false;
        foreach (EXEMPT_TEMP_EXTRA as $pattern) {
            if (preg_match($pattern, $currentFile) === 1) {
                $exemptTemp = true;
                break;
            }
        }
        if (!$exemptTemp) {
            if (preg_match(TEMP_MARKERS, $added) === 1) {
                $tempFindings[] = sprintf('  %s: %s', $currentFile, substr(trim($added), 0, 80));
            } elseif (preg_match(CONSOLE_LOG_PATTERN, $added) === 1) {
                foreach (CONSOLE_LOG_EXTENSIONS as $extension) {
                    if (str_ends_with($currentFile, $extension)) {
                        $tempFindings[] = sprintf('  %s: console.log — %s', $currentFile, substr(trim($added), 0, 60));
                        break;
                    }
                }
            }
        }
    }
}

if ($conflictFindings !== []) {
    block(
        HOOK,
        $toolName,
        "BLOCKED: staged files contain unresolved merge conflict markers.\n\n"
        . preview($conflictFindings) . "\n\n"
        . "Resolve all conflicts and remove the markers before committing.\n"
        . 'This hook has no override — conflict markers must never be committed.',
        reason: sprintf('%d conflict markers found', count($conflictFindings)),
    );
}

if ($tempFindings !== []) {
    block(
        HOOK,
        $toolName,
        "BLOCKED: staged code contains temporary/debug markers.\n\n"
        . preview($tempFindings) . "\n\n"
        . "Remove TEMP/HACK/FIXME markers and console.log calls before "
        . "committing. Use @todo for legitimate tech-debt annotations.\n\n"
        . 'Override: APPWRITE_HOOKS_ALLOW_TEMP_CODE=1',
        reason: sprintf('%d temp markers found', count($tempFindings)),
    );
}

allow(HOOK, $toolName);

/**
 * @param string[] $findings
 */
function preview(array $findings): string
{
    $head = array_slice($findings, 0, 10);
    $preview = implode("\n", $head);
    if (count($findings) > 10) {
        $preview .= sprintf("\n  ... and %d more", count($findings) - 10);
    }
    return $preview;
}
