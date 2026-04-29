<?php

declare(strict_types=1);

/**
 * Block Edit/Write/MultiEdit on secret-bearing files.
 *
 * Path patterns blocked:
 *   - .env at any level (NOT .env.example / .env.sample)
 *   - credentials*, secrets*
 *   - *.pem, *.key, *.p12, *.pfx, *.jks, *.keystore, *.asc
 *   - id_rsa, id_ed25519, id_ecdsa, id_dsa
 *   - kubeconfig*, *.token, .netrc, .pgpass, .aws/credentials
 *
 * Also scans WRITE CONTENT for AWS / OpenAI / GitHub PAT / Slack / private
 * key blocks / Mongo / Postgres URI patterns.
 *
 * Escape hatch: APPWRITE_HOOKS_ALLOW_SECRETS=1.
 */

require_once __DIR__ . '/_shared.php';

use function Marketplace\Hook\Shared\allow;
use function Marketplace\Hook\Shared\block;
use function Marketplace\Hook\Shared\read_tool_input;
use function Marketplace\Hook\Shared\skip;

const HOOK = 'secrets_guard';

const PROTECTED_TOOLS = ['Edit', 'Write', 'MultiEdit'];

const SECRET_PATH_PATTERNS = [
    '#(^|/)\.env(\.[a-zA-Z0-9_-]+)?$#',
    '#(^|/)credentials(\.[a-z]+)?$#i',
    '#(^|/)secrets?(\.[a-z]+)?$#i',
    '/\.(pem|key|p12|pfx|jks|keystore|asc)$/i',
    '#(^|/)id_(rsa|ed25519|ecdsa|dsa)$#',
    '#(^|/)kubeconfig(\..+)?$#i',
    '/\.token$/i',
    '#(^|/)\.netrc$#',
    '#(^|/)\.pgpass$#',
    '#(^|/)\.aws/credentials$#',
];

const PATH_ALLOWLIST_PATTERNS = [
    '/\.(example|sample|template|dist|tpl)(\.[a-z]+)?$/',
    '/\.example$/',
    '#(^|/)example[-_]#',
    '#(^|/)fixtures?/#',
    '#(^|/)test(s|_data)?/#',
];

const SECRET_CONTENT_PATTERNS = [
    ['/AKIA[0-9A-Z]{16}/', 'AWS access key id'],
    ['#aws_secret_access_key\s*=\s*[A-Za-z0-9/+=]{40}#', 'AWS secret access key'],
    ['/sk-[A-Za-z0-9]{20,}/', 'OpenAI-style API key'],
    ['/ghp_[A-Za-z0-9]{36}/', 'GitHub personal access token'],
    ['/github_pat_[A-Za-z0-9_]{82}/', 'GitHub fine-grained PAT'],
    ['/xoxb-[0-9]+-[0-9]+-[A-Za-z0-9]+/', 'Slack bot token'],
    ['/-----BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/', 'private key block'],
    ['#mongodb(\+srv)?://[^\s:]+:[^\s@]+@#', 'MongoDB connection string with credentials'],
    ['#postgres(ql)?://[^\s:]+:[^\s@]+@#', 'Postgres connection string with credentials'],
];

[$toolName, $toolInput] = read_tool_input();
if (!in_array($toolName, PROTECTED_TOOLS, true)) {
    skip(HOOK, $toolName);
}

$filePath = (string) ($toolInput['file_path'] ?? '');
if ($filePath === '') {
    skip(HOOK, $toolName);
}

if (getenv('APPWRITE_HOOKS_ALLOW_SECRETS') === '1') {
    allow(HOOK, $toolName, 'opt-out-allow-secrets');
}

if (path_is_secret($filePath)) {
    block(
        HOOK,
        $toolName,
        sprintf("BLOCKED: refusing to %s a secret-bearing file.\n\n", $toolName)
        . sprintf("  Path: %s\n\n", $filePath)
        . "This path matches a pattern reserved for credentials, keys, or "
        . "private material. The global rules forbid committing such files, "
        . "and the safest way to enforce that is to never write them in the "
        . "first place.\n\n"
        . "If you meant to edit a non-secret file with a similar name "
        . "(e.g. `.env.example`), rename the file.\n\n"
        . 'If the user has explicitly authorized this edit, set '
        . '`APPWRITE_HOOKS_ALLOW_SECRETS=1` for the command.',
        reason: sprintf('secret path: %s', basename($filePath)),
    );
}

$content = extract_content($toolName, $toolInput);
$leak = content_contains_secret($content);
if ($leak !== '') {
    block(
        HOOK,
        $toolName,
        sprintf("BLOCKED: the content being written contains what looks like a %s.\n\n", $leak)
        . sprintf("  Path: %s\n\n", $filePath)
        . 'If this is a test fixture or deliberate rotation of an expired '
        . 'key, move it into a test/fixtures directory (which is '
        . 'allowlisted) or set `APPWRITE_HOOKS_ALLOW_SECRETS=1` for the command.',
        reason: sprintf('content contains %s', $leak),
    );
}

allow(HOOK, $toolName);

function path_is_secret(string $path): bool
{
    foreach (PATH_ALLOWLIST_PATTERNS as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            return false;
        }
    }
    foreach (SECRET_PATH_PATTERNS as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            return true;
        }
    }
    return false;
}

function content_contains_secret(string $content): string
{
    if ($content === '') {
        return '';
    }
    foreach (SECRET_CONTENT_PATTERNS as [$pattern, $label]) {
        if (preg_match($pattern, $content) === 1) {
            return $label;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $toolInput
 */
function extract_content(string $toolName, array $toolInput): string
{
    if ($toolName === 'Write') {
        return (string) ($toolInput['content'] ?? '');
    }
    if ($toolName === 'Edit') {
        return (string) ($toolInput['new_string'] ?? '');
    }
    if ($toolName === 'MultiEdit') {
        $edits = $toolInput['edits'] ?? [];
        if (!is_array($edits)) {
            return '';
        }
        $parts = [];
        foreach ($edits as $edit) {
            if (is_array($edit)) {
                $parts[] = (string) ($edit['new_string'] ?? '');
            }
        }
        return implode("\n", $parts);
    }
    return '';
}
