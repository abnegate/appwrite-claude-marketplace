<?php

declare(strict_types=1);

namespace Marketplace\Tests\Validation;

use Marketplace\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
final class ValidatorTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/validator-test-' . uniqid('', true);
        mkdir($this->sandbox, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->sandbox);
    }

    public function testValidSkillPasses(): void
    {
        $this->writeFixture('plugins/p/skills/my-skill/SKILL.md', "---\nname: my-skill\ndescription: A skill\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->skillCount);
    }

    public function testNameMismatchFails(): void
    {
        $this->writeFixture('plugins/p/skills/my-skill/SKILL.md', "---\nname: wrong\ndescription: A skill\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('name mismatch', $result->errors[0]);
    }

    public function testMissingNameFails(): void
    {
        $this->writeFixture('plugins/p/skills/my-skill/SKILL.md', "---\ndescription: A skill\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('missing `name:`', $result->errors[0]);
    }

    public function testMissingDescriptionFails(): void
    {
        $this->writeFixture('plugins/p/skills/my-skill/SKILL.md', "---\nname: my-skill\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('missing `description:`', $result->errors[0]);
    }

    public function testDescriptionTooLongFails(): void
    {
        $longDescription = str_repeat('x', Validator::MAX_DESCRIPTION_CHARS + 1);
        $this->writeFixture('plugins/p/skills/my-skill/SKILL.md', sprintf("---\nname: my-skill\ndescription: %s\n---\nbody", $longDescription));
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('chars', $result->errors[0]);
    }

    public function testDuplicateSkillNamesFail(): void
    {
        $this->writeFixture('plugins/a/skills/my-skill/SKILL.md', "---\nname: my-skill\ndescription: A\n---\nbody");
        $this->writeFixture('plugins/b/skills/my-skill/SKILL.md', "---\nname: my-skill\ndescription: B\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('duplicate', $result->errors[0]);
    }

    public function testMissingFrontmatterFails(): void
    {
        $this->writeFixture('plugins/p/skills/my-skill/SKILL.md', 'no frontmatter');
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('missing frontmatter', $result->errors[0]);
    }

    public function testValidCommandPasses(): void
    {
        $this->writeFixture('plugins/p/commands/foo.md', "---\ndescription: Does stuff\n---\n# /foo");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->commandCount);
    }

    public function testCommandMissingDescriptionFails(): void
    {
        $this->writeFixture('plugins/p/commands/foo.md', "---\nargument-hint: x\n---\n# /foo");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('description', $result->errors[0]);
    }

    public function testValidAgentPasses(): void
    {
        $this->writeFixture('plugins/p/agents/router.md', "---\nname: router\ndescription: Routes\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->agentCount);
    }

    public function testAgentMissingNameFails(): void
    {
        $this->writeFixture('plugins/p/agents/router.md', "---\ndescription: Routes\n---\nbody");
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('name', $result->errors[0]);
    }

    public function testValidPluginManifestPasses(): void
    {
        $this->writeFixture('plugins/p/.claude-plugin/plugin.json', json_encode(['name' => 'p', 'description' => 'd']));
        $result = (new Validator($this->sandbox))->validate();
        $this->assertSame([], $result->errors);
    }

    public function testInvalidJsonFails(): void
    {
        $this->writeFixture('plugins/p/.claude-plugin/plugin.json', '{bad json');
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('invalid JSON', $result->errors[0]);
    }

    public function testManifestMissingNameFails(): void
    {
        $this->writeFixture('plugins/p/.claude-plugin/plugin.json', json_encode(['description' => 'd']));
        $result = (new Validator($this->sandbox))->validate();
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('name', $result->errors[0]);
    }

    private function writeFixture(string $relativePath, string $content): void
    {
        $path = $this->sandbox . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, $content);
    }

    private function removeRecursive(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursive($path . '/' . $entry);
        }
        rmdir($path);
    }
}
