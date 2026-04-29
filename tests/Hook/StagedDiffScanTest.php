<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class StagedDiffScanTest extends HookTestCase
{
    private const string HOOK = 'staged_diff_scan.php';

    public function testIgnoresNonBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['file_path' => '/tmp/x'], toolName: 'Read');
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonGitBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'echo hello']);
        $this->assertSame(0, $code);
    }

    public function testAllowsNormalCommit(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git commit -m "(feat): add foo"']);
        $this->assertSame(0, $code);
    }

    public function testTempCodeOptOut(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['command' => 'git commit -m "(feat): temp stuff"'],
            envOverrides: ['APPWRITE_HOOKS_ALLOW_TEMP_CODE' => '1'],
        );
        $this->assertSame(0, $code);
    }
}
