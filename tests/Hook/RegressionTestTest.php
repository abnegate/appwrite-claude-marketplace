<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class RegressionTestTest extends HookTestCase
{
    private const string HOOK = 'regression_test.php';

    public function testAllowsNonFixCommit(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git commit -m "(feat): add foo"']);
        $this->assertSame(0, $code);
    }

    public function testAllowsMerge(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => "git commit -m 'Merge pull request #1 from x/y'"]);
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['file_path' => '/tmp/x'], toolName: 'Read');
        $this->assertSame(0, $code);
    }
}
