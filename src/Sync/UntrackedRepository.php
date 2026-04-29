<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class UntrackedRepository
{
    public function __construct(
        public string $repo,
        public string $url,
        public string $description,
        public int $stars,
        public ?string $pushedAt,
    ) {
    }

    /**
     * @return array{repo: string, url: string, description: string, stars: int, pushed_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'repo' => $this->repo,
            'url' => $this->url,
            'description' => $this->description,
            'stars' => $this->stars,
            'pushed_at' => $this->pushedAt,
        ];
    }
}
