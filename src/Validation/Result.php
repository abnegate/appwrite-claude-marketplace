<?php

declare(strict_types=1);

namespace Marketplace\Validation;

final readonly class Result
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public array $errors,
        public int $skillCount,
        public int $commandCount,
        public int $agentCount,
        public int $evalCount,
        public int $triggerCount,
    ) {
    }

    public function isOk(): bool
    {
        return $this->errors === [];
    }
}
