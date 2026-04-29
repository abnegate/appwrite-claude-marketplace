<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class MissingSkill
{
    public function __construct(
        public string $skill,
        public string $repo,
        public string $url,
        public string $description,
        public bool $archived,
        public int $stars,
    ) {
    }

    /**
     * @return array{skill: string, repo: string, url: string, description: string, archived: bool, stars: int}
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skill,
            'repo' => $this->repo,
            'url' => $this->url,
            'description' => $this->description,
            'archived' => $this->archived,
            'stars' => $this->stars,
        ];
    }
}
