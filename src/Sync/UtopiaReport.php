<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class UtopiaReport
{
    /**
     * @param MissingSkill[] $missing
     * @param OrphanedSkill[] $orphaned
     * @param ArchivedSkill[] $archived
     */
    public function __construct(
        public int $upstreamCount,
        public int $localCount,
        public array $missing,
        public array $orphaned,
        public array $archived,
    ) {
    }

    public function hasDrift(): bool
    {
        return $this->missing !== [] || $this->orphaned !== [] || $this->archived !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'upstream_count' => $this->upstreamCount,
            'local_count' => $this->localCount,
            'missing' => array_map(static fn (MissingSkill $m): array => $m->toArray(), $this->missing),
            'orphaned' => array_map(static fn (OrphanedSkill $o): array => $o->toArray(), $this->orphaned),
            'archived' => array_map(static fn (ArchivedSkill $a): array => $a->toArray(), $this->archived),
        ];
    }
}
