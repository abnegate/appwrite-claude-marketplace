<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class HookTestCase extends TestCase
{
    private const string HOOK_DIR = __DIR__ . '/../../plugins/appwrite-hooks/hooks';

    /**
     * Invoke a hook script as Claude Code would: PHP subprocess, JSON
     * stdin, env stripped of metric writes by default.
     *
     * @param array<string, mixed> $toolInput
     * @param array<string, string> $envOverrides
     * @return array{int, string} [exitCode, stderrText]
     */
    protected static function callHook(
        string $hookScript,
        array $toolInput,
        string $toolName = 'Bash',
        array $envOverrides = [],
    ): array {
        $payload = json_encode(
            ['tool_name' => $toolName, 'tool_input' => $toolInput],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $env = ['APPWRITE_HOOKS_NO_METRICS' => '1'];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach (['PATH', 'HOME', 'USER', 'TMPDIR'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        foreach ($envOverrides as $key => $value) {
            $env[$key] = $value;
        }

        $hookPath = self::HOOK_DIR . '/' . $hookScript;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['php', $hookPath],
            $descriptors,
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn hook subprocess');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stderr = (string) stream_get_contents($pipes[2]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($stdout !== '') {
            // Hook stdout is reserved for the eventual tool response;
            // hooks should never print there. Fail loudly if they do.
            throw new RuntimeException(sprintf('hook %s wrote to stdout: %s', $hookScript, $stdout));
        }
        return [$exit, $stderr];
    }
}
