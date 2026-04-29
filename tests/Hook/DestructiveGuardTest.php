<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class DestructiveGuardTest extends HookTestCase
{
    private const string HOOK = 'destructive_guard.php';

    public function testBlocksRmRfSlash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf /']);
        $this->assertSame(2, $code);
    }

    public function testBlocksRmRfHome(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf ~']);
        $this->assertSame(2, $code);
    }

    public function testBlocksRmRfWithVariable(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf $SOMETHING']);
        $this->assertSame(2, $code);
    }

    public function testBlocksRmRfGlobAtRoot(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf /*']);
        $this->assertSame(2, $code);
    }

    public function testAllowsRmRfNodeModules(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf node_modules']);
        $this->assertSame(0, $code);
    }

    public function testAllowsRmRfDist(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf ./dist']);
        $this->assertSame(0, $code);
    }

    public function testAllowsRmRfTmp(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf /tmp/xyz']);
        $this->assertSame(0, $code);
    }

    public function testBlocksRmRfTmpevil(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf /tmpevil']);
        $this->assertSame(2, $code);
    }

    public function testBlocksSudoRmRfSlash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'sudo rm -rf /']);
        $this->assertSame(2, $code);
    }

    public function testBlocksEnvRmRfSlash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'env rm -rf /']);
        $this->assertSame(2, $code);
    }

    public function testBlocksRmRfViaPipe(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'echo foo | rm -rf /']);
        $this->assertSame(2, $code);
    }

    public function testBlocksTmpdirTraversal(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm -rf $TMPDIR/../../etc']);
        $this->assertSame(2, $code);
    }

    public function testAllowsPlainRm(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'rm foo.txt']);
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['file_path' => '/tmp/x'], toolName: 'Read');
        $this->assertSame(0, $code);
    }

    public function testOptOutAllowsRmRfHome(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['command' => 'rm -rf ~'],
            envOverrides: ['APPWRITE_HOOKS_ALLOW_DESTRUCTIVE' => '1'],
        );
        $this->assertSame(0, $code);
    }
}
