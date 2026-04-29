<?php

declare(strict_types=1);

namespace Marketplace\Tests\Sync;

use Marketplace\Sync\Repository;

final class RepositoryFactory
{
    /**
     * @param array{
     *     owner?: string,
     *     html_url?: string,
     *     description?: string,
     *     archived?: bool,
     *     fork?: bool,
     *     stars?: int,
     *     pushed_at?: ?string,
     * } $overrides
     */
    public static function make(string $name, array $overrides = []): Repository
    {
        return new Repository(
            owner: $overrides['owner'] ?? 'example',
            name: $name,
            htmlUrl: $overrides['html_url'] ?? sprintf('https://github.com/example/%s', $name),
            description: $overrides['description'] ?? '',
            archived: $overrides['archived'] ?? false,
            fork: $overrides['fork'] ?? false,
            stars: $overrides['stars'] ?? 0,
            pushedAt: $overrides['pushed_at'] ?? '2026-04-01T00:00:00Z',
        );
    }
}
