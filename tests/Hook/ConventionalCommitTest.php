<?php

declare(strict_types=1);

namespace Marketplace\Tests\Hook;

final class ConventionalCommitTest extends HookTestCase
{
    private const string HOOK = 'conventional_commit.php';

    public function testAllowsFeat(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git commit -m "(feat): add gitea webhook"']);
        $this->assertSame(0, $code);
    }

    public function testAllowsFix(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git commit -m "(fix): guard against null billing plan"']);
        $this->assertSame(0, $code);
    }

    public function testAllowsMerge(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => "git commit -m 'Merge pull request #123 from abc/def'"]);
        $this->assertSame(0, $code);
    }

    public function testBlocksMissingType(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git commit -m "add foo"']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('conventional format', $err);
    }

    public function testBlocksUnknownType(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git commit -m "(wat): add foo"']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('conventional format', $err);
    }

    public function testBlocksBareTypeNoParens(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git commit -m "feat: add foo"']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('conventional format', $err);
    }

    public function testAllowsChainedCommands(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'git add . && git commit -m "(feat): chained"']);
        $this->assertSame(0, $code);
    }

    public function testBlocksNoVerify(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git commit --no-verify -m "(feat): add foo"']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('no-verify', $err);
    }

    public function testBlocksAmend(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'git commit --amend -m "(feat): add foo"']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('amend', $err);
    }

    public function testBlocksEnvPrefixedNoVerify(): void
    {
        [$code, $err] = self::callHook(self::HOOK, ['command' => 'GIT_AUTHOR_NAME="test" git commit --no-verify -m "(feat): foo"']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('no-verify', $err);
    }

    public function testOptOutAllowsNoVerify(): void
    {
        [$code] = self::callHook(
            self::HOOK,
            ['command' => 'git commit --no-verify -m "(feat): add foo"'],
            envOverrides: ['APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT' => '1'],
        );
        $this->assertSame(0, $code);
    }

    public function testDryRunConvertsBlockToAllow(): void
    {
        [$code, $err] = self::callHook(
            self::HOOK,
            ['command' => 'git commit --no-verify -m "(feat): add foo"'],
            envOverrides: ['APPWRITE_HOOKS_DRY_RUN' => '1'],
        );
        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $err);
    }

    public function testIgnoresNonGitBash(): void
    {
        [$code] = self::callHook(self::HOOK, ['command' => 'ls -la']);
        $this->assertSame(0, $code);
    }

    public function testIgnoresNonBashTools(): void
    {
        [$code] = self::callHook(self::HOOK, ['file_path' => '/tmp/x'], toolName: 'Read');
        $this->assertSame(0, $code);
    }
}
