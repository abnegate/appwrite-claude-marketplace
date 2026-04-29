<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final class Detector
{
    public const string UTOPIA_ORG = 'utopia-php';
    public const string APPWRITE_ORG = 'appwrite';
    public const string APPWRITE_LABS_ORG = 'appwrite-labs';
    public const string OPEN_RUNTIMES_ORG = 'open-runtimes';

    public const array UTOPIA_REPO_BLOCKLIST = [
        '.github',
        'utopia-php',
        'utopia',
        'php',
        'php-scrypt-test',
        'docs',
        'site',
        'website',
        'demo',
        'demos',
        'examples',
        'template',
        'starter',
        'tests',
        'docker-base',
        'utopia-php.github.io',
        'composer-merge-plugin',
    ];

    public const array APPWRITE_TRACKED_REPOS = [
        'appwrite',
        'console',
        'sdk-generator',
        'sdk-for-node',
        'sdk-for-php',
        'sdk-for-python',
        'sdk-for-ruby',
        'sdk-for-go',
        'sdk-for-dart',
        'sdk-for-flutter',
        'sdk-for-android',
        'sdk-for-apple',
        'sdk-for-web',
        'sdk-for-deno',
        'sdk-for-kotlin',
        'sdk-for-swift',
        'demos-for-react',
        'demos-for-vue',
        'demos-for-svelte',
        'docs',
        'pink',
        'awesome-appwrite',
    ];

    /**
     * @param Repository[] $repos
     * @param string[] $localSkills
     */
    public static function diffUtopia(array $repos, array $localSkills): UtopiaReport
    {
        $libraries = self::filterLibraryRepos($repos);
        $upstreamByName = [];
        foreach ($libraries as $repo) {
            $upstreamByName[$repo->name] = $repo;
        }

        $localSet = array_fill_keys($localSkills, true);

        $missing = [];
        $expectedSkills = [];
        foreach ($upstreamByName as $name => $repo) {
            $expectedSkills[SkillNamer::utopiaSkillForRepo($name)] = $name;
        }
        ksort($expectedSkills);
        foreach ($expectedSkills as $skillName => $repoName) {
            if (isset($localSet[$skillName])) {
                continue;
            }
            $repo = $upstreamByName[$repoName];
            $missing[] = new MissingSkill(
                skill: $skillName,
                repo: self::UTOPIA_ORG . '/' . $repoName,
                url: $repo->htmlUrl,
                description: $repo->description,
                archived: $repo->archived,
                stars: $repo->stars,
            );
        }

        $orphaned = [];
        $archived = [];
        $sortedSkills = $localSkills;
        sort($sortedSkills);
        foreach ($sortedSkills as $skillName) {
            $repoName = SkillNamer::repoForUtopiaSkill($skillName);
            if ($repoName === null) {
                continue;
            }
            if (!isset($upstreamByName[$repoName])) {
                $orphaned[] = new OrphanedSkill(
                    skill: $skillName,
                    expectedRepo: self::UTOPIA_ORG . '/' . $repoName,
                );
                continue;
            }
            if ($upstreamByName[$repoName]->archived) {
                $archived[] = new ArchivedSkill(
                    skill: $skillName,
                    repo: self::UTOPIA_ORG . '/' . $repoName,
                );
            }
        }

        return new UtopiaReport(
            upstreamCount: count($libraries),
            localCount: count($localSkills),
            missing: $missing,
            orphaned: $orphaned,
            archived: $archived,
        );
    }

    /**
     * @param Repository[] $repos
     */
    public static function diffAppwrite(array $repos): AppwriteReport
    {
        $upstreamByName = [];
        foreach ($repos as $repo) {
            if ($repo->fork) {
                continue;
            }
            $upstreamByName[$repo->name] = $repo;
        }
        ksort($upstreamByName);

        $candidates = [];
        foreach ($upstreamByName as $name => $repo) {
            if (in_array($name, self::APPWRITE_TRACKED_REPOS, true)) {
                continue;
            }
            if ($repo->archived) {
                continue;
            }
            if (!SkillNamer::isAppwriteLibraryRepo($name)) {
                continue;
            }
            $candidates[] = new UntrackedRepository(
                repo: self::APPWRITE_ORG . '/' . $name,
                url: $repo->htmlUrl,
                description: $repo->description,
                stars: $repo->stars,
                pushedAt: $repo->pushedAt,
            );
        }

        $removedOrRenamed = array_values(array_diff(
            self::APPWRITE_TRACKED_REPOS,
            array_keys($upstreamByName),
        ));
        sort($removedOrRenamed);

        return new AppwriteReport(
            upstreamCount: count($upstreamByName),
            trackedCount: count(self::APPWRITE_TRACKED_REPOS),
            newOrUntracked: $candidates,
            removedOrRenamed: $removedOrRenamed,
        );
    }

    /**
     * @param Repository[] $repos
     */
    public static function summariseOrg(string $org, array $repos): OrgSummary
    {
        $visible = [];
        foreach ($repos as $repo) {
            if ($repo->fork) {
                continue;
            }
            $visible[] = new UntrackedRepository(
                repo: $org . '/' . $repo->name,
                url: $repo->htmlUrl,
                description: $repo->description,
                stars: $repo->stars,
                pushedAt: $repo->pushedAt,
            );
        }
        usort($visible, static fn (UntrackedRepository $a, UntrackedRepository $b): int => strcmp($a->repo, $b->repo));
        return new OrgSummary($org, count($visible), $visible);
    }

    /**
     * @param Repository[] $repos
     * @return Repository[]
     */
    public static function filterLibraryRepos(array $repos): array
    {
        $out = [];
        foreach ($repos as $repo) {
            if (in_array($repo->name, self::UTOPIA_REPO_BLOCKLIST, true)) {
                continue;
            }
            if ($repo->fork) {
                continue;
            }
            $out[] = $repo;
        }
        return $out;
    }
}
