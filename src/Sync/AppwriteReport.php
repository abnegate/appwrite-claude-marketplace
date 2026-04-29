<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class AppwriteReport
{
    /**
     * @param UntrackedRepository[] $newOrUntracked
     * @param string[] $removedOrRenamed
     */
    public function __construct(
        public int $upstreamCount,
        public int $trackedCount,
        public array $newOrUntracked,
        public array $removedOrRenamed,
    ) {
    }

    public function hasDrift(): bool
    {
        return $this->newOrUntracked !== [] || $this->removedOrRenamed !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'upstream_count' => $this->upstreamCount,
            'tracked_count' => $this->trackedCount,
            'new_or_untracked' => array_map(static fn (UntrackedRepository $r): array => $r->toArray(), $this->newOrUntracked),
            'removed_or_renamed' => $this->removedOrRenamed,
        ];
    }
}
