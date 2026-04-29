<?php

declare(strict_types=1);

namespace Marketplace\Sync;

use RuntimeException;

final class GhClient implements Client
{
    public function __construct(
        private readonly string $binary = 'gh',
        private readonly int $perPage = 100,
    ) {
    }

    /**
     * @return Repository[]
     */
    public function fetchRepos(string $org): array
    {
        $repos = [];
        $page = 1;
        while (true) {
            $endpoint = sprintf(
                'orgs/%s/repos?per_page=%d&page=%d&type=all',
                $org,
                $this->perPage,
                $page,
            );
            $batch = $this->run([
                'api',
                $endpoint,
                '-H', 'Accept: application/vnd.github+json',
                '-H', 'X-GitHub-Api-Version: 2022-11-28',
            ]);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $repos[] = Repository::fromArray($org, $entry);
            }
            if (count($batch) < $this->perPage) {
                break;
            }
            $page++;
        }
        return $repos;
    }

    /**
     * @param string[] $arguments
     * @return array<int, mixed>
     */
    private function run(array $arguments): array
    {
        $command = array_merge([$this->binary], $arguments);
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start gh CLI');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                'gh CLI exited %d: %s',
                $exit,
                trim($stderr),
            ));
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('gh CLI returned non-array JSON');
        }
        return $decoded;
    }
}
