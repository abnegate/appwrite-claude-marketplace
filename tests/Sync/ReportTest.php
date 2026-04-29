<?php

declare(strict_types=1);

namespace Marketplace\Tests\Sync;

use Marketplace\Sync\AppwriteReport;
use Marketplace\Sync\ArchivedSkill;
use Marketplace\Sync\DriftReport;
use Marketplace\Sync\MissingSkill;
use Marketplace\Sync\OrgSummary;
use Marketplace\Sync\OrphanedSkill;
use Marketplace\Sync\UntrackedRepository;
use Marketplace\Sync\UtopiaReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtopiaReport::class)]
#[CoversClass(AppwriteReport::class)]
#[CoversClass(DriftReport::class)]
final class ReportTest extends TestCase
{
    public function testNoDrift(): void
    {
        $report = $this->buildReport(missing: [], orphaned: [], archived: [], untracked: [], removed: []);
        $this->assertFalse($report->hasDrift());
    }

    public function testDriftOnMissing(): void
    {
        $missing = [new MissingSkill('utopia-x-expert', 'utopia-php/x', '', '', false, 0)];
        $report = $this->buildReport(missing: $missing, orphaned: [], archived: [], untracked: [], removed: []);
        $this->assertTrue($report->hasDrift());
    }

    public function testDriftOnOrphaned(): void
    {
        $orphaned = [new OrphanedSkill('utopia-y-expert', 'utopia-php/y')];
        $report = $this->buildReport(missing: [], orphaned: $orphaned, archived: [], untracked: [], removed: []);
        $this->assertTrue($report->hasDrift());
    }

    public function testDriftOnArchived(): void
    {
        $archived = [new ArchivedSkill('utopia-z-expert', 'utopia-php/z')];
        $report = $this->buildReport(missing: [], orphaned: [], archived: $archived, untracked: [], removed: []);
        $this->assertTrue($report->hasDrift());
    }

    public function testDriftOnAppwriteUntracked(): void
    {
        $untracked = [new UntrackedRepository('appwrite/sdk-for-x', '', '', 0, null)];
        $report = $this->buildReport(missing: [], orphaned: [], archived: [], untracked: $untracked, removed: []);
        $this->assertTrue($report->hasDrift());
    }

    public function testDriftOnAppwriteRemoved(): void
    {
        $report = $this->buildReport(missing: [], orphaned: [], archived: [], untracked: [], removed: ['console']);
        $this->assertTrue($report->hasDrift());
    }

    /**
     * @param MissingSkill[] $missing
     * @param OrphanedSkill[] $orphaned
     * @param ArchivedSkill[] $archived
     * @param UntrackedRepository[] $untracked
     * @param string[] $removed
     */
    private function buildReport(
        array $missing,
        array $orphaned,
        array $archived,
        array $untracked,
        array $removed,
    ): DriftReport {
        return new DriftReport(
            utopia: new UtopiaReport(0, 0, $missing, $orphaned, $archived),
            appwrite: new AppwriteReport(0, 0, $untracked, $removed),
            appwriteLabs: new OrgSummary('appwrite-labs', 0, []),
            openRuntimes: new OrgSummary('open-runtimes', 0, []),
        );
    }
}
