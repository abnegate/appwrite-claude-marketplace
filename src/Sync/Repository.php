<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class Repository
{
    public function __construct(
        public string $owner,
        public string $name,
        public string $htmlUrl,
        public string $description,
        public bool $archived,
        public bool $fork,
        public int $stars,
        public ?string $pushedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data Single repo payload from the GitHub REST API.
     */
    public static function fromArray(string $owner, array $data): self
    {
        return new self(
            owner: $owner,
            name: (string) ($data['name'] ?? ''),
            htmlUrl: (string) ($data['html_url'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            archived: (bool) ($data['archived'] ?? false),
            fork: (bool) ($data['fork'] ?? false),
            stars: (int) ($data['stargazers_count'] ?? 0),
            pushedAt: $data['pushed_at'] ?? null,
        );
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->name;
    }
}
