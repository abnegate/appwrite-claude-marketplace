<?php

declare(strict_types=1);

namespace Marketplace\Sync;

use RuntimeException;

final class Application
{
    public const string UTOPIA_SKILLS_DIR = 'plugins/utopia-experts/skills';
    public const int EXIT_OK = 0;
    public const int EXIT_DRIFT = 2;

    public function __construct(
        private readonly Client $client,
        private readonly string $repoRoot,
    ) {
    }

    /**
     * @param string[] $argv
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $emitJson = false;
        $regenerateIndex = false;
        $failOnDrift = false;

        foreach ($argv as $arg) {
            switch ($arg) {
                case '--json':
                    $emitJson = true;
                    break;
                case '--regenerate-index':
                    $regenerateIndex = true;
                    break;
                case '--fail-on-drift':
                    $failOnDrift = true;
                    break;
                case '--help':
                case '-h':
                    fwrite($stdout, $this->helpText());
                    return self::EXIT_OK;
                default:
                    fwrite($stderr, sprintf("unknown flag: %s\n", $arg));
                    return 1;
            }
        }

        $report = $this->buildReport();

        if ($emitJson) {
            fwrite($stdout, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        } else {
            TextReporter::render($report, $stdout);
        }

        if ($regenerateIndex) {
            $exit = $this->regenerateIndex($stdout, $stderr);
            if ($exit !== self::EXIT_OK) {
                return $exit;
            }
        }

        if ($failOnDrift && $report->hasDrift()) {
            return self::EXIT_DRIFT;
        }

        return self::EXIT_OK;
    }

    public function buildReport(): DriftReport
    {
        $utopiaRepos = $this->client->fetchRepos(Detector::UTOPIA_ORG);
        $appwriteRepos = $this->client->fetchRepos(Detector::APPWRITE_ORG);
        $appwriteLabsRepos = $this->client->fetchRepos(Detector::APPWRITE_LABS_ORG);
        $openRuntimesRepos = $this->client->fetchRepos(Detector::OPEN_RUNTIMES_ORG);

        $localSkills = $this->listLocalSkills();

        return new DriftReport(
            utopia: Detector::diffUtopia($utopiaRepos, $localSkills),
            appwrite: Detector::diffAppwrite($appwriteRepos),
            appwriteLabs: Detector::summariseOrg(Detector::APPWRITE_LABS_ORG, $appwriteLabsRepos),
            openRuntimes: Detector::summariseOrg(Detector::OPEN_RUNTIMES_ORG, $openRuntimesRepos),
        );
    }

    /**
     * @return string[]
     */
    public function listLocalSkills(): array
    {
        $dir = $this->repoRoot . '/' . self::UTOPIA_SKILLS_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $skills = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $skillPath = $dir . '/' . $entry;
            if (is_dir($skillPath) && is_file($skillPath . '/SKILL.md')) {
                $skills[] = $entry;
            }
        }
        return $skills;
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    private function regenerateIndex($stdout, $stderr): int
    {
        $script = $this->repoRoot . '/plugins/utopia-experts/scripts/generate_index.py';
        if (!is_file($script)) {
            fwrite($stderr, sprintf("error: %s not found\n", $script));
            return 1;
        }
        $process = proc_open(
            ['python3', $script],
            [1 => $stdout, 2 => $stderr],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn generate_index.py');
        }
        return proc_close($process);
    }

    private function helpText(): string
    {
        return <<<TXT
        Usage: bin/sync-libraries [options]

          --json                Emit JSON report on stdout
          --regenerate-index    Re-run plugins/utopia-experts/scripts/generate_index.py
          --fail-on-drift       Exit 2 if any drift is detected
          --help, -h            Show this help

        Set GITHUB_TOKEN to lift API rate limits and surface private repos
        your account can see.

        TXT;
    }
}
