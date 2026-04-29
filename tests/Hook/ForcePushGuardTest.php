<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class ForcePushGuardTest extends HookTestCase
{
    private const string HOOK = 'force_push_guard.php';

    public function testAllowsNormalPush(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git push origin feat-foo']);
        $this->assertSame(0, $code);
    }

    public function testAllowsForcePushToFeatureBranch(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git push --force origin feat-foo']);
        $this->assertSame(0, $code);
    }

    public function testBlocksForcePushToMain(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push --force origin main']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('main', $err);
    }

    public function testBlocksForcePushToMaster(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push -f origin master']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('master', $err);
    }

    public function testBlocksForcePushToVersionBranch(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push --force origin 1.9.x']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('1.9.x', $err);
    }

    public function testBlocksForceWithLeaseToMain(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git push --force-with-lease origin main']);
        $this->assertSame(2, $code);
    }

    public function testBlocksLeadingPlusRefspecOnMain(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push origin +main']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('main', $err);
    }

    public function testBlocksForcePushFlagsBeforeRemote(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push --force --set-upstream origin main']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('main', $err);
    }

    public function testBlocksForceWithLeaseEqualsForm(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git push --force-with-lease=origin/main origin main']);
        $this->assertSame(2, $code);
    }

    public function testBlocksCombinedShortForceFlag(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push -uf origin main']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('main', $err);
    }

    public function testBlocksForcePushNoTarget(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git push --force']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('no explicit target', $err);
    }

    public function testOptOutAllowsForcePushToMain(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['command' => 'git push --force origin main'],
            envOverrides: ['APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH' => '1'],
        );
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonPushBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git status']);
        $this->assertSame(0, $code);
    }
}
