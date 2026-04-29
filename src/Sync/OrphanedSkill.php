<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class OrphanedSkill
{
    public function __construct(
        public string $skill,
        public string $expectedRepo,
    ) {
    }

    /**
     * @return array{skill: string, expected_repo: string}
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skill,
            'expected_repo' => $this->expectedRepo,
        ];
    }
}
