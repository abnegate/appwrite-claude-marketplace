<?php

declare(strict_types=1);

namespace Marketplace\Tests\Sync;

use Marketplace\Sync\SkillNamer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillNamer::class)]
final class SkillNamerTest extends TestCase
{
    public function testUtopiaSkillForRepoRoundTrip(): void
    {
        $this->assertSame('utopia-http-expert', SkillNamer::utopiaSkillForRepo('http'));
        $this->assertSame('http', SkillNamer::repoForUtopiaSkill('utopia-http-expert'));
    }

    public function testRepoForUtopiaSkillReturnsNullForNonMatching(): void
    {
        $this->assertNull(SkillNamer::repoForUtopiaSkill('appwrite-auth-expert'));
        $this->assertNull(SkillNamer::repoForUtopiaSkill('utopia-broken'));
    }

    public function testIsAppwriteLibraryRepoClassification(): void
    {
        $this->assertTrue(SkillNamer::isAppwriteLibraryRepo('sdk-for-go'));
        $this->assertTrue(SkillNamer::isAppwriteLibraryRepo('integration-for-stripe'));
        $this->assertTrue(SkillNamer::isAppwriteLibraryRepo('mcp-for-api'));
        $this->assertFalse(SkillNamer::isAppwriteLibraryRepo('appwrite'));
        $this->assertFalse(SkillNamer::isAppwriteLibraryRepo('blog'));
    }
}
