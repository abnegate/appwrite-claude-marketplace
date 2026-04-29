<?php

declare(strict_types=1);

namespace Marketplace\Index;

final readonly class Report
{
    public function __construct(
        public string $indexPath,
        public int $skillCount,
        public int $categoryCount,
    ) {
    }
}
