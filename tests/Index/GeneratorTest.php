<?php

declare(strict_types=1);

namespace Marketplace\Tests\Index;

use Marketplace\Index\Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Generator::class)]
final class GeneratorTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../..';

    public function testRegenerationIsIdempotentAgainstRealCatalogue(): void
    {
        $indexPath = self::REPO_ROOT . '/plugins/utopia-experts/skills/INDEX.md';
        if (!is_file($indexPath)) {
            $this->markTestSkipped('INDEX.md not found — run `bin/marketplace index` first');
        }
        $before = (string) file_get_contents($indexPath);
        (new Generator(self::REPO_ROOT))->run();
        $after = (string) file_get_contents($indexPath);
        $this->assertSame($before, $after, 'INDEX.md changed after regeneration — generator is not idempotent');
    }

    public function testReportShape(): void
    {
        $indexPath = self::REPO_ROOT . '/plugins/utopia-experts/skills/INDEX.md';
        if (!is_file($indexPath)) {
            $this->markTestSkipped('INDEX.md not found');
        }
        $report = (new Generator(self::REPO_ROOT))->run();
        $this->assertSame(50, $report->skillCount);
        $this->assertGreaterThanOrEqual(10, $report->categoryCount);
        $this->assertStringEndsWith('INDEX.md', $report->indexPath);
    }
}
