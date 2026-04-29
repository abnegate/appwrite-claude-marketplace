<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final class SkillNamer
{
    public const string UTOPIA_PREFIX = 'utopia-';
    public const string UTOPIA_SUFFIX = '-expert';

    public const array APPWRITE_LIBRARY_PREFIXES = [
        'sdk-for-',
        'integration-for-',
        'mcp-for-',
    ];

    public static function utopiaSkillForRepo(string $repoName): string
    {
        return self::UTOPIA_PREFIX . $repoName . self::UTOPIA_SUFFIX;
    }

    public static function repoForUtopiaSkill(string $skillName): ?string
    {
        if (!str_starts_with($skillName, self::UTOPIA_PREFIX)) {
            return null;
        }
        if (!str_ends_with($skillName, self::UTOPIA_SUFFIX)) {
            return null;
        }
        return substr(
            $skillName,
            strlen(self::UTOPIA_PREFIX),
            -strlen(self::UTOPIA_SUFFIX),
        );
    }

    public static function isAppwriteLibraryRepo(string $repoName): bool
    {
        foreach (self::APPWRITE_LIBRARY_PREFIXES as $prefix) {
            if (str_starts_with($repoName, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
