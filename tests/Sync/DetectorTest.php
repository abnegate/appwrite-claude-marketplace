<?php

declare(strict_types=1);

namespace Marketplace\Tests\Sync;

use Marketplace\Sync\Detector;
use Marketplace\Sync\SkillNamer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Detector::class)]
final class DetectorTest extends TestCase
{
    public function testMissingSkillForNewUpstreamRepo(): void
    {
        $upstream = [
            RepositoryFactory::make('http'),
            RepositoryFactory::make('newlib', ['description' => 'shiny']),
        ];
        $local = [SkillNamer::utopiaSkillForRepo('http')];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertCount(1, $report->missing);
        $this->assertSame('utopia-newlib-expert', $report->missing[0]->skill);
        $this->assertSame('utopia-php/newlib', $report->missing[0]->repo);
        $this->assertSame('shiny', $report->missing[0]->description);
        $this->assertSame([], $report->orphaned);
        $this->assertSame([], $report->archived);
    }

    public function testOrphanedSkillWhenUpstreamRemoved(): void
    {
        $upstream = [RepositoryFactory::make('http')];
        $local = ['utopia-http-expert', 'utopia-removed-expert'];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertSame([], $report->missing);
        $this->assertCount(1, $report->orphaned);
        $this->assertSame('utopia-removed-expert', $report->orphaned[0]->skill);
    }

    public function testArchivedUpstreamFlagsLocalSkill(): void
    {
        $upstream = [
            RepositoryFactory::make('http'),
            RepositoryFactory::make('legacy', ['archived' => true]),
        ];
        $local = ['utopia-http-expert', 'utopia-legacy-expert'];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertCount(1, $report->archived);
        $this->assertSame('utopia-legacy-expert', $report->archived[0]->skill);
    }

    public function testBlocklistExcludesMetaRepos(): void
    {
        $upstream = [
            RepositoryFactory::make('http'),
            RepositoryFactory::make('docs'),
            RepositoryFactory::make('demo'),
            RepositoryFactory::make('.github'),
        ];
        $local = ['utopia-http-expert'];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertSame([], $report->missing);
        $this->assertSame(1, $report->upstreamCount);
    }

    public function testForksExcluded(): void
    {
        $upstream = [
            RepositoryFactory::make('http'),
            RepositoryFactory::make('forked-lib', ['fork' => true]),
        ];
        $local = ['utopia-http-expert'];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertSame(1, $report->upstreamCount);
        $this->assertSame([], $report->missing);
    }

    public function testSkillNotMatchingPatternIsIgnoredForOrphaning(): void
    {
        $upstream = [RepositoryFactory::make('http')];
        $local = ['utopia-http-expert', 'random-skill-name'];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertSame([], $report->orphaned);
    }

    public function testMissingEntryCarriesStarsAndUrl(): void
    {
        $upstream = [
            RepositoryFactory::make('shiny', ['stars' => 42, 'html_url' => 'https://x/shiny']),
        ];
        $local = [];

        $report = Detector::diffUtopia($upstream, $local);

        $this->assertSame(42, $report->missing[0]->stars);
        $this->assertSame('https://x/shiny', $report->missing[0]->url);
    }

    public function testDiffAppwriteOnlyFlagsLibraryPatterns(): void
    {
        $upstream = [
            RepositoryFactory::make('appwrite'),
            RepositoryFactory::make('sdk-for-rust'),
            RepositoryFactory::make('integration-for-stripe'),
            RepositoryFactory::make('mcp-for-api'),
            RepositoryFactory::make('blog'),
            RepositoryFactory::make('30daysofappwrite'),
        ];

        $report = Detector::diffAppwrite($upstream);

        $names = array_map(static fn ($r) => $r->repo, $report->newOrUntracked);
        $this->assertContains('appwrite/sdk-for-rust', $names);
        $this->assertContains('appwrite/integration-for-stripe', $names);
        $this->assertContains('appwrite/mcp-for-api', $names);
        $this->assertNotContains('appwrite/blog', $names);
        $this->assertNotContains('appwrite/30daysofappwrite', $names);
    }

    public function testDiffAppwriteSkipsTrackedRepos(): void
    {
        $upstream = [
            RepositoryFactory::make('appwrite'),
            RepositoryFactory::make('sdk-for-node'),
        ];

        $report = Detector::diffAppwrite($upstream);

        $this->assertSame([], $report->newOrUntracked);
    }

    public function testDiffAppwriteSkipsArchivedRepos(): void
    {
        $upstream = [RepositoryFactory::make('sdk-for-defunct', ['archived' => true])];

        $report = Detector::diffAppwrite($upstream);

        $this->assertSame([], $report->newOrUntracked);
    }

    public function testDiffAppwriteDetectsRemovedTrackedRepos(): void
    {
        $upstream = [RepositoryFactory::make('appwrite')];

        $report = Detector::diffAppwrite($upstream);

        $this->assertContains('console', $report->removedOrRenamed);
        $this->assertNotContains('appwrite', $report->removedOrRenamed);
    }

    public function testSummariseOrgInventoriesAndExcludesForks(): void
    {
        $repos = [
            RepositoryFactory::make('a'),
            RepositoryFactory::make('b'),
            RepositoryFactory::make('c', ['fork' => true]),
        ];

        $summary = Detector::summariseOrg('appwrite-labs', $repos);

        $this->assertSame('appwrite-labs', $summary->org);
        $this->assertSame(2, $summary->count);
        $names = array_map(static fn ($r) => $r->repo, $summary->repos);
        $this->assertSame(['appwrite-labs/a', 'appwrite-labs/b'], $names);
    }

    public function testSummariseOrgCarriesPushedAt(): void
    {
        $repos = [RepositoryFactory::make('legacy', ['pushed_at' => '2024-01-01T00:00:00Z'])];

        $summary = Detector::summariseOrg('appwrite-labs', $repos);

        $this->assertSame('2024-01-01T00:00:00Z', $summary->repos[0]->pushedAt);
    }

    public function testFilterLibraryReposExcludesBlocklistAndForks(): void
    {
        $repos = [
            RepositoryFactory::make('http'),
            RepositoryFactory::make('docs'),
            RepositoryFactory::make('demo'),
            RepositoryFactory::make('forked', ['fork' => true]),
            RepositoryFactory::make('storage'),
        ];

        $filtered = Detector::filterLibraryRepos($repos);
        $names = array_map(static fn ($r) => $r->name, $filtered);
        sort($names);

        $this->assertSame(['http', 'storage'], $names);
    }
}
