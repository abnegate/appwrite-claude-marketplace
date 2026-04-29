<?php

declare(strict_types=1);

namespace Marketplace\Tests\Sync;

use Marketplace\Sync\Application;
use Marketplace\Sync\Detector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sync-test-' . uniqid('', true);
        mkdir($this->sandbox . '/' . Application::UTOPIA_SKILLS_DIR, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->sandbox);
    }

    public function testRunEmitsJsonReportShapeWithStubbedFetch(): void
    {
        $client = new StubClient();
        $client->setOrgRepos(Detector::UTOPIA_ORG, [RepositoryFactory::make('http')]);
        $client->setOrgRepos(Detector::APPWRITE_ORG, [
            RepositoryFactory::make('appwrite'),
            RepositoryFactory::make('sdk-for-rust', ['stars' => 99]),
        ]);
        $client->setOrgRepos(Detector::APPWRITE_LABS_ORG, [RepositoryFactory::make('cloud')]);
        $client->setOrgRepos(Detector::OPEN_RUNTIMES_ORG, [RepositoryFactory::make('executor')]);

        $app = new Application($client, $this->sandbox);
        [$exit, $stdout] = $this->capture(static fn ($out, $err): int => $app->run(['--json'], $out, $err));

        $this->assertSame(Application::EXIT_OK, $exit);
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['utopia', 'appwrite', 'appwrite_labs', 'open_runtimes'], array_keys($report));
        $this->assertSame('appwrite-labs', $report['appwrite_labs']['org']);
        $this->assertSame(1, $report['open_runtimes']['count']);
        $flagged = array_column($report['appwrite']['new_or_untracked'], 'repo');
        $this->assertContains('appwrite/sdk-for-rust', $flagged);
    }

    public function testFailOnDriftReturnsNonZeroWhenDriftExists(): void
    {
        $client = new StubClient();
        $client->setOrgRepos(Detector::UTOPIA_ORG, [RepositoryFactory::make('newlib')]);
        $client->setOrgRepos(Detector::APPWRITE_ORG, $this->trackedAppwriteRepos());
        $client->setOrgRepos(Detector::APPWRITE_LABS_ORG, []);
        $client->setOrgRepos(Detector::OPEN_RUNTIMES_ORG, []);

        $app = new Application($client, $this->sandbox);
        [$exit] = $this->capture(static fn ($out, $err): int => $app->run(['--json', '--fail-on-drift'], $out, $err));

        $this->assertSame(Application::EXIT_DRIFT, $exit);
    }

    public function testFailOnDriftReturnsZeroWhenNoDrift(): void
    {
        $client = new StubClient();
        $client->setOrgRepos(Detector::UTOPIA_ORG, []);
        $client->setOrgRepos(Detector::APPWRITE_ORG, $this->trackedAppwriteRepos());
        $client->setOrgRepos(Detector::APPWRITE_LABS_ORG, []);
        $client->setOrgRepos(Detector::OPEN_RUNTIMES_ORG, []);

        $app = new Application($client, $this->sandbox);
        [$exit] = $this->capture(static fn ($out, $err): int => $app->run(['--json', '--fail-on-drift'], $out, $err));

        $this->assertSame(Application::EXIT_OK, $exit);
    }

    public function testListLocalSkillsReadsScaffoldedDirectories(): void
    {
        $skillsDir = $this->sandbox . '/' . Application::UTOPIA_SKILLS_DIR;
        mkdir($skillsDir . '/utopia-foo-expert');
        file_put_contents($skillsDir . '/utopia-foo-expert/SKILL.md', "---\nname: utopia-foo-expert\ndescription: x\n---\n");
        mkdir($skillsDir . '/utopia-bar-without-skill-md');

        $app = new Application(new StubClient(), $this->sandbox);
        $skills = $app->listLocalSkills();

        $this->assertContains('utopia-foo-expert', $skills);
        $this->assertNotContains('utopia-bar-without-skill-md', $skills);
    }

    public function testUnknownFlagReturnsError(): void
    {
        $app = new Application(new StubClient(), $this->sandbox);
        [$exit, , $stderr] = $this->capture(static fn ($out, $err): int => $app->run(['--mystery'], $out, $err));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown flag', $stderr);
    }

    /**
     * @param callable(resource, resource): int $fn
     * @return array{int, string, string}
     */
    private function capture(callable $fn): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $exit = $fn($stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        $stdoutText = stream_get_contents($stdout) ?: '';
        $stderrText = stream_get_contents($stderr) ?: '';
        fclose($stdout);
        fclose($stderr);

        return [$exit, $stdoutText, $stderrText];
    }

    /**
     * @return \Marketplace\Sync\Repository[]
     */
    private function trackedAppwriteRepos(): array
    {
        return array_map(
            static fn (string $name) => RepositoryFactory::make($name),
            Detector::APPWRITE_TRACKED_REPOS,
        );
    }

    private function removeRecursive(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursive($path . '/' . $entry);
        }
        rmdir($path);
    }
}
