<?php

declare(strict_types=1);

namespace Marketplace\Tests\Sync;

use Marketplace\Sync\Client;
use Marketplace\Sync\Repository;
use OutOfBoundsException;

final class StubClient implements Client
{
    /**
     * @param array<string, Repository[]> $reposByOrg
     */
    public function __construct(
        private array $reposByOrg = [],
    ) {
    }

    /**
     * @param Repository[] $repos
     */
    public function setOrgRepos(string $org, array $repos): void
    {
        $this->reposByOrg[$org] = $repos;
    }

    /**
     * @return Repository[]
     */
    public function fetchRepos(string $org): array
    {
        if (!isset($this->reposByOrg[$org])) {
            throw new OutOfBoundsException(sprintf('no stub for org %s', $org));
        }
        return $this->reposByOrg[$org];
    }
}
