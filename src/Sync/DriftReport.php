<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final readonly class DriftReport
{
    public function __construct(
        public UtopiaReport $utopia,
        public AppwriteReport $appwrite,
        public OrgSummary $appwriteLabs,
        public OrgSummary $openRuntimes,
    ) {
    }

    public function hasDrift(): bool
    {
        return $this->utopia->hasDrift() || $this->appwrite->hasDrift();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'utopia' => $this->utopia->toArray(),
            'appwrite' => $this->appwrite->toArray(),
            'appwrite_labs' => $this->appwriteLabs->toArray(),
            'open_runtimes' => $this->openRuntimes->toArray(),
        ];
    }
}
