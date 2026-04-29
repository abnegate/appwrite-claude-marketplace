<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class ArchivedSkill
{
    public function __construct(
        public string $skill,
        public string $repo,
    ) {
    }

    /**
     * @return array{skill: string, repo: string}
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skill,
            'repo' => $this->repo,
        ];
    }
}
