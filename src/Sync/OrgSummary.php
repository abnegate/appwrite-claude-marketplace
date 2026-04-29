<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class OrgSummary
{
    /**
     * @param UntrackedRepository[] $repos
     */
    public function __construct(
        public string $org,
        public int $count,
        public array $repos,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'org' => $this->org,
            'count' => $this->count,
            'repos' => array_map(static fn (UntrackedRepository $r): array => $r->toArray(), $this->repos),
        ];
    }
}
