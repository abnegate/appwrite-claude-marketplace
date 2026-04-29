<?php

declare(strict_types=1);

namespace Marketplace\Tests\Index;

use Marketplace\Index\Catalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Catalogue::class)]
final class CatalogueTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function knownSkillProvider(): array
    {
        return [
            'http' => ['utopia-http-expert', 'framework'],
            'database' => ['utopia-database-expert', 'data'],
            'cache' => ['utopia-cache-expert', 'storage-io'],
            'abuse' => ['utopia-abuse-expert', 'auth-security'],
            'cli' => ['utopia-cli-expert', 'runtime'],
            'logger' => ['utopia-logger-expert', 'observability'],
            'messaging' => ['utopia-messaging-expert', 'messaging-async'],
            'pay' => ['utopia-pay-expert', 'domain'],
            'ab' => ['utopia-ab-expert', 'utilities'],
            'console' => ['utopia-console-expert', 'misc'],
        ];
    }

    #[DataProvider('knownSkillProvider')]
    public function testKnownSkillsResolveToExpectedCategory(string $skill, string $expected): void
    {
        $this->assertSame($expected, Catalogue::lookup($skill));
    }

    public function testUnknownSkillFallsThroughToOther(): void
    {
        $this->assertSame('other', Catalogue::lookup('utopia-mystery-expert'));
    }

    public function testAllFiftySkillsHaveACategory(): void
    {
        $all = [];
        foreach (Catalogue::all() as $category) {
            foreach ($category->skills as $skill) {
                $all[] = $skill;
            }
        }
        $this->assertCount(50, $all);
        foreach ($all as $skill) {
            $this->assertNotSame('other', Catalogue::lookup($skill), sprintf('%s has no category', $skill));
        }
    }
}
