<?php

declare(strict_types=1);

namespace Marketplace\Index;

use Marketplace\Markdown\Frontmatter;
use RuntimeException;

final class Generator
{
    public const string DEFAULT_SKILLS_DIR = 'plugins/utopia-experts/skills';
    public const string OTHER_CATEGORY_TITLE = 'Other';

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $skillsDir = self::DEFAULT_SKILLS_DIR,
    ) {
    }

    public function run(): Report
    {
        $skillsPath = $this->repoRoot . '/' . $this->skillsDir;
        if (!is_dir($skillsPath)) {
            throw new RuntimeException(sprintf('skills directory not found: %s', $skillsPath));
        }

        $entries = $this->loadSkillEntries($skillsPath);
        $byCategory = $this->groupByCategory($entries);

        $output = [
            '# Utopia Experts — Skill Index',
            '',
            sprintf(
                'Auto-generated index of the %d `utopia-*-expert` skills in this plugin.',
                count($entries),
            ),
            'The `utopia-router` agent reads this file first to decide which 1-3 skills to load',
            'for a given question. Regenerate with `bin/marketplace index` after any skill change.',
            '',
            '## How to use',
            '',
            'For surgical reference on one library, load the matching skill directly.',
            'For cross-cutting questions, dispatch to the `utopia-router` agent which will',
            'read this index, pick the most relevant skills, and return a synthesised answer.',
            '',
        ];

        foreach (Catalogue::all() as $category) {
            if (!isset($byCategory[$category->key])) {
                continue;
            }
            $output[] = sprintf('## %s', $category->title);
            $output[] = '';
            $output[] = '| Skill | Description |';
            $output[] = '|---|---|';
            $rows = $byCategory[$category->key];
            sort($rows);
            foreach ($rows as [$name, $description]) {
                $escaped = str_replace('|', '\\|', $description);
                $output[] = sprintf('| `%s` | %s |', $name, $escaped);
            }
            $output[] = '';
        }

        if (isset($byCategory['other'])) {
            $output[] = sprintf('## %s', self::OTHER_CATEGORY_TITLE);
            $output[] = '';
            $output[] = '| Skill | Description |';
            $output[] = '|---|---|';
            $rows = $byCategory['other'];
            sort($rows);
            foreach ($rows as [$name, $description]) {
                $escaped = str_replace('|', '\\|', $description);
                $output[] = sprintf('| `%s` | %s |', $name, $escaped);
            }
            $output[] = '';
        }

        foreach ($this->compositionNotes() as $line) {
            $output[] = $line;
        }

        $indexPath = $skillsPath . '/INDEX.md';
        file_put_contents($indexPath, implode("\n", $output));

        return new Report(
            indexPath: $indexPath,
            skillCount: count($entries),
            categoryCount: count($byCategory),
        );
    }

    /**
     * @return list<array{string, string, string}> [category, name, description]
     */
    private function loadSkillEntries(string $skillsPath): array
    {
        $entries = [];
        $skills = glob($skillsPath . '/*/SKILL.md') ?: [];
        sort($skills);
        foreach ($skills as $skillPath) {
            $content = (string) file_get_contents($skillPath);
            $fields = Frontmatter::parse($content);
            $directory = basename(dirname($skillPath));
            $name = $fields['name'] ?? $directory;
            $description = $fields['description'] ?? '';
            $entries[] = [Catalogue::lookup($name), $name, $description];
        }
        return $entries;
    }

    /**
     * @param list<array{string, string, string}> $entries
     * @return array<string, list<array{string, string}>>
     */
    private function groupByCategory(array $entries): array
    {
        $byCategory = [];
        foreach ($entries as [$category, $name, $description]) {
            $byCategory[$category] ??= [];
            $byCategory[$category][] = [$name, $description];
        }
        return $byCategory;
    }

    /**
     * @return string[]
     */
    private function compositionNotes(): array
    {
        return [
            '## Composition notes for the router',
            '',
            'Some questions naturally span multiple skills. Known pairings:',
            '',
            '- **Observability pipeline** — `utopia-span-expert` + `utopia-logger-expert` + `utopia-telemetry-expert` + `utopia-audit-expert` + `utopia-analytics-expert`',
            '- **Swoole pool stack** — `utopia-pools-expert` + `utopia-database-expert` + `utopia-cache-expert` + `utopia-mongo-expert`',
            '- **SDK regen cascade** — `utopia-http-expert` + `utopia-validators-expert` + `utopia-platform-expert`',
            '- **Custom-domain onboarding** — `utopia-domains-expert` + `utopia-dns-expert` + `utopia-vcs-expert`',
            '- **Ingestion pipeline** — `utopia-cloudevents-expert` + `utopia-usage-expert`',
            '- **Rate limiting** — `utopia-abuse-expert` + `utopia-waf-expert` + `utopia-cache-expert`',
            '- **Messaging worker** — `utopia-messaging-expert` + `utopia-queue-expert` + `utopia-async-expert`',
            '',
            'When a question matches a pairing, the router should load all relevant skills rather than picking just one.',
            '',
        ];
    }
}
