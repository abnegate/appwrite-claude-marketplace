<?php

declare(strict_types=1);

namespace Marketplace\Sync;

interface Client
{
    /**
     * Page through all repos visible to the auth context for the given org.
     *
     * @return Repository[]
     */
    public function fetchRepos(string $org): array;
}
