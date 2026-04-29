<?php

declare(strict_types=1);

namespace Marketplace\Index;

final readonly class Category
{
    /**
     * @param string[] $skills
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $skills,
    ) {
    }
}
