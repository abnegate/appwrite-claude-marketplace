<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class SecretsGuardTest extends HookTestCase
{
    private const string HOOK = 'secrets_guard.php';

    public function testBlocksEnvWrite(): void
    {
        [$code, $err] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/.env', 'content' => 'DB=x'],
            toolName: 'Write',
        );
        $this->assertSame(2, $code);
        $this->assertStringContainsString('.env', $err);
    }

    public function testAllowsEnvExample(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/.env.example', 'content' => 'DB=example'],
            toolName: 'Write',
        );
        $this->assertSame(0, $code);
    }

    public function testBlocksPemEdit(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['file_path' => '/secrets/app.pem', 'new_string' => 'abc'],
            toolName: 'Edit',
        );
        $this->assertSame(2, $code);
    }

    public function testBlocksIdRsa(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['file_path' => '/home/user/.ssh/id_rsa', 'content' => 'abc'],
            toolName: 'Write',
        );
        $this->assertSame(2, $code);
    }

    public function testBlocksAwsKeyInContent(): void
    {
        [$code, $err] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/src/foo.py', 'content' => 'KEY = "AKIAIOSFODNN7EXAMPLE"'],
            toolName: 'Write',
        );
        $this->assertSame(2, $code);
        $this->assertStringContainsString('AWS', $err);
    }

    public function testBlocksPrivateKeyInContent(): void
    {
        [$code, $err] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/src/foo.md', 'content' => "example\n-----BEGIN RSA PRIVATE KEY-----\n...\n"],
            toolName: 'Write',
        );
        $this->assertSame(2, $code);
        $this->assertStringContainsString('private key', $err);
    }

    public function testBlocksEnvUppercaseVariant(): void
    {
        [$code, $err] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/.env.PRODUCTION', 'content' => 'DB=x'],
            toolName: 'Write',
        );
        $this->assertSame(2, $code);
        $this->assertStringContainsString('.env.PRODUCTION', $err);
    }

    public function testBlocksKubeconfig(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/kubeconfig.yaml', 'content' => 'clusters: []'],
            toolName: 'Write',
        );
        $this->assertSame(2, $code);
    }

    public function testAllowsNormalCode(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/src/foo.py', 'content' => 'print("hi")'],
            toolName: 'Write',
        );
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonEditTools(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'ls -la'], toolName: 'Bash');
        $this->assertSame(0, $code);
    }

    public function testOptOutAllowsEnvWrite(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['file_path' => '/repo/.env', 'content' => 'DB=x'],
            toolName: 'Write',
            envOverrides: ['APPWRITE_HOOKS_ALLOW_SECRETS' => '1'],
        );
        $this->assertSame(0, $code);
    }
}
