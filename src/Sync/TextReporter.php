<?php

declare(strict_types=1);

namespace Marketplace\Sync;

final class TextReporter
{
    /**
     * @param resource $stream
     */
    public static function render(DriftReport $report, $stream): void
    {
        $utopia = $report->utopia;
        fwrite($stream, "utopia-php\n");
        fwrite($stream, sprintf("  upstream libraries: %d\n", $utopia->upstreamCount));
        fwrite($stream, sprintf("  local skills:       %d\n", $utopia->localCount));

        self::renderMissing($utopia->missing, $stream);
        self::renderOrphaned($utopia->orphaned, $stream);
        self::renderArchived($utopia->archived, $stream);

        fwrite($stream, "\n");

        $appwrite = $report->appwrite;
        fwrite($stream, "appwrite\n");
        fwrite($stream, sprintf("  upstream public repos: %d\n", $appwrite->upstreamCount));
        fwrite($stream, sprintf("  tracked repos:         %d\n", $appwrite->trackedCount));

        self::renderUntracked($appwrite->newOrUntracked, $stream);
        self::renderRemoved($appwrite->removedOrRenamed, $stream);

        fwrite($stream, "\n");
        fwrite($stream, sprintf("%s: %d visible repos\n", $report->appwriteLabs->org, $report->appwriteLabs->count));
        fwrite($stream, sprintf("%s: %d visible repos\n", $report->openRuntimes->org, $report->openRuntimes->count));
    }

    /**
     * @param MissingSkill[] $missing
     * @param resource $stream
     */
    private static function renderMissing(array $missing, $stream): void
    {
        if ($missing === []) {
            fwrite($stream, "  missing skills:     none\n");
            return;
        }
        fwrite($stream, sprintf("  missing skills (%d):\n", count($missing)));
        foreach ($missing as $entry) {
            $note = $entry->stars > 0 ? sprintf('  (%d★)', $entry->stars) : '';
            fwrite($stream, sprintf("    - %s ← %s%s\n", $entry->skill, $entry->repo, $note));
            if ($entry->description !== '') {
                fwrite($stream, sprintf("        %s\n", $entry->description));
            }
        }
    }

    /**
     * @param OrphanedSkill[] $orphaned
     * @param resource $stream
     */
    private static function renderOrphaned(array $orphaned, $stream): void
    {
        if ($orphaned === []) {
            fwrite($stream, "  orphaned skills:    none\n");
            return;
        }
        fwrite($stream, sprintf("  orphaned skills (%d):\n", count($orphaned)));
        foreach ($orphaned as $entry) {
            fwrite($stream, sprintf("    - %s (expected %s)\n", $entry->skill, $entry->expectedRepo));
        }
    }

    /**
     * @param ArchivedSkill[] $archived
     * @param resource $stream
     */
    private static function renderArchived(array $archived, $stream): void
    {
        if ($archived === []) {
            fwrite($stream, "  archived upstream:  none\n");
            return;
        }
        fwrite($stream, sprintf("  archived upstream (%d):\n", count($archived)));
        foreach ($archived as $entry) {
            fwrite($stream, sprintf("    - %s (%s)\n", $entry->skill, $entry->repo));
        }
    }

    /**
     * @param UntrackedRepository[] $untracked
     * @param resource $stream
     */
    private static function renderUntracked(array $untracked, $stream): void
    {
        if ($untracked === []) {
            fwrite($stream, "  new / untracked:       none\n");
            return;
        }
        fwrite($stream, sprintf("  new / untracked (%d):\n", count($untracked)));
        foreach ($untracked as $entry) {
            $note = $entry->stars > 0 ? sprintf('  (%d★)', $entry->stars) : '';
            fwrite($stream, sprintf("    - %s%s\n", $entry->repo, $note));
            if ($entry->description !== '') {
                fwrite($stream, sprintf("        %s\n", $entry->description));
            }
        }
    }

    /**
     * @param string[] $removed
     * @param resource $stream
     */
    private static function renderRemoved(array $removed, $stream): void
    {
        if ($removed === []) {
            fwrite($stream, "  removed or renamed:    none\n");
            return;
        }
        fwrite($stream, sprintf("  removed or renamed (%d):\n", count($removed)));
        foreach ($removed as $name) {
            fwrite($stream, sprintf("    - %s/%s\n", Detector::APPWRITE_ORG, $name));
        }
    }
}
