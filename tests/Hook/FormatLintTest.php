<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class FormatLintTest extends HookTestCase
{
    private const string HOOK = 'format_lint.php';

    public function testSkippedByEnv(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['command' => 'git commit -m "(feat): x"'],
            envOverrides: ['APPWRITE_HOOKS_SKIP_LINT' => '1'],
        );
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonGitBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'echo hello']);
        $this->assertSame(0, $code);
    }
}
