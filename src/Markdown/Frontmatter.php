<?php

declare(strict_types=1);

namespace Marketplace\Markdown;

final class Frontmatter
{
    private const string FIELD_PATTERN = '/^([\w-]+):\s*(.*?)$/m';

    /**
     * Parse a leading `---\n...\n---` YAML block into a flat dict. Returns
     * an empty array if the block is missing or unclosed. Only top-level
     * scalar fields are recognised — this is not a full YAML parser.
     *
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        if (!str_starts_with($content, '---')) {
            return [];
        }
        $body = self::extractBlock($content);
        if ($body === null) {
            return [];
        }
        $fields = [];
        foreach (explode("\n", $body) as $line) {
            if (preg_match(self::FIELD_PATTERN, $line, $matches) === 1) {
                $fields[$matches[1]] = trim($matches[2]);
            }
        }
        return $fields;
    }

    public static function hasOpening(string $content): bool
    {
        return str_starts_with($content, '---');
    }

    private static function extractBlock(string $content): ?string
    {
        $start = strpos($content, "---\n");
        if ($start !== 0) {
            return null;
        }
        $end = strpos($content, "\n---", 4);
        if ($end === false) {
            return null;
        }
        return substr($content, 4, $end - 4);
    }
}
