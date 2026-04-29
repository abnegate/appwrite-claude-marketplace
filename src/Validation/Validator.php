<?php

declare(strict_types=1);

namespace Marketplace\Validation;

use Marketplace\Markdown\Frontmatter;

final class Validator
{
    public const int MAX_DESCRIPTION_CHARS = 500;

    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    public function validate(): Result
    {
        $errors = [];
        $seenSkillNames = [];
        $seenCommandNames = [];
        $seenAgentNames = [];

        foreach ($this->glob('plugins/*/skills/*/SKILL.md') as $path) {
            $this->validateSkill($path, $errors, $seenSkillNames);
        }
        foreach ($this->glob('plugins/*/commands/*.md') as $path) {
            $this->validateCommand($path, $errors, $seenCommandNames);
        }
        foreach ($this->glob('plugins/*/agents/*.md') as $path) {
            $this->validateAgent($path, $errors, $seenAgentNames);
        }

        $marketplaceManifest = $this->repoRoot . '/.claude-plugin/marketplace.json';
        if (is_file($marketplaceManifest)) {
            $this->validateJsonManifest($marketplaceManifest, $errors);
        }
        foreach ($this->glob('plugins/*/.claude-plugin/plugin.json') as $path) {
            $this->validateJsonManifest($path, $errors);
        }
        foreach ($this->glob('plugins/*/hooks/hooks.json') as $path) {
            $this->validateJsonParses($path, $errors);
        }

        $evalCount = 0;
        foreach ($this->glob('plugins/*/evals/evals.json') as $path) {
            $evalCount += $this->validateEvals($path, $errors);
        }

        $triggerCount = 0;
        foreach ($this->glob('plugins/*/evals/trigger-*.json') as $path) {
            $triggerCount += $this->validateTriggerEvals($path, $errors);
        }

        return new Result(
            errors: $errors,
            skillCount: count($seenSkillNames),
            commandCount: count($seenCommandNames),
            agentCount: count($seenAgentNames),
            evalCount: $evalCount,
            triggerCount: $triggerCount,
        );
    }

    /**
     * @param string[] $errors
     * @param array<string, string> $seenNames
     */
    private function validateSkill(string $path, array &$errors, array &$seenNames): void
    {
        $content = (string) file_get_contents($path);
        if (!Frontmatter::hasOpening($content)) {
            $errors[] = sprintf('%s: missing frontmatter (no leading ---)', $path);
            return;
        }
        $fields = Frontmatter::parse($content);
        if ($fields === []) {
            $errors[] = sprintf('%s: frontmatter block not closed (no trailing ---)', $path);
            return;
        }

        $name = $fields['name'] ?? '';
        $description = $fields['description'] ?? '';
        $directory = basename(dirname($path));

        if ($name === '') {
            $errors[] = sprintf('%s: missing `name:` field', $path);
        } elseif ($name !== $directory) {
            $errors[] = sprintf(
                '%s: name mismatch — frontmatter `name: %s` does not match directory `%s`',
                $path,
                $name,
                $directory,
            );
        } elseif (isset($seenNames[$name])) {
            $errors[] = sprintf('%s: duplicate skill name `%s` — also declared at %s', $path, $name, $seenNames[$name]);
        } else {
            $seenNames[$name] = $path;
        }

        $this->validateDescription($path, $description, $errors);
    }

    /**
     * @param string[] $errors
     * @param array<string, string> $seenNames
     */
    private function validateCommand(string $path, array &$errors, array &$seenNames): void
    {
        $content = (string) file_get_contents($path);
        if (!Frontmatter::hasOpening($content)) {
            $errors[] = sprintf('%s: missing frontmatter', $path);
            return;
        }
        $fields = Frontmatter::parse($content);
        if ($fields === []) {
            $errors[] = sprintf('%s: frontmatter block not closed', $path);
            return;
        }

        $description = $fields['description'] ?? '';
        if ($description === '') {
            $errors[] = sprintf('%s: commands must have a `description:` field', $path);
        } elseif (mb_strlen($description) > self::MAX_DESCRIPTION_CHARS) {
            $errors[] = sprintf('%s: description is %d chars (max %d)', $path, mb_strlen($description), self::MAX_DESCRIPTION_CHARS);
        }

        $stem = pathinfo($path, PATHINFO_FILENAME);
        if (isset($seenNames[$stem])) {
            $errors[] = sprintf('%s: duplicate command name `%s` — also declared at %s', $path, $stem, $seenNames[$stem]);
        } else {
            $seenNames[$stem] = $path;
        }
    }

    /**
     * @param string[] $errors
     * @param array<string, string> $seenNames
     */
    private function validateAgent(string $path, array &$errors, array &$seenNames): void
    {
        $content = (string) file_get_contents($path);
        if (!Frontmatter::hasOpening($content)) {
            $errors[] = sprintf('%s: missing frontmatter', $path);
            return;
        }
        $fields = Frontmatter::parse($content);
        $name = $fields['name'] ?? '';
        if ($name === '') {
            $errors[] = sprintf('%s: agents must have a `name:` field', $path);
        } elseif (isset($seenNames[$name])) {
            $errors[] = sprintf('%s: duplicate agent name `%s` — also declared at %s', $path, $name, $seenNames[$name]);
        } else {
            $seenNames[$name] = $path;
        }
        if (!array_key_exists('description', $fields) || $fields['description'] === '') {
            $errors[] = sprintf('%s: agents must have a `description:` field', $path);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateDescription(string $path, string $description, array &$errors): void
    {
        if ($description === '') {
            $errors[] = sprintf('%s: missing `description:` field', $path);
            return;
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_CHARS) {
            $errors[] = sprintf('%s: description is %d chars (max %d)', $path, mb_strlen($description), self::MAX_DESCRIPTION_CHARS);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateJsonManifest(string $path, array &$errors): void
    {
        $data = $this->decodeJson($path, $errors);
        if (!is_array($data)) {
            return;
        }
        if (!array_is_list($data)) {
            if (!array_key_exists('name', $data)) {
                $errors[] = sprintf('%s: missing `name` field', $path);
            }
            if (!array_key_exists('description', $data)) {
                $errors[] = sprintf('%s: missing `description` field', $path);
            }
        } else {
            $errors[] = sprintf('%s: top-level must be an object', $path);
        }
    }

    /**
     * @param string[] $errors
     */
    private function validateJsonParses(string $path, array &$errors): void
    {
        $this->decodeJson($path, $errors);
    }

    /**
     * @param string[] $errors
     */
    private function validateEvals(string $path, array &$errors): int
    {
        $data = $this->decodeJson($path, $errors);
        if (!is_array($data)) {
            return 0;
        }
        if (array_is_list($data)) {
            $errors[] = sprintf('%s: top-level must be an object', $path);
            return 0;
        }
        if (!array_key_exists('skill_name', $data)) {
            $errors[] = sprintf('%s: missing `skill_name` field', $path);
        }
        $evals = $data['evals'] ?? [];
        if (!is_array($evals) || !array_is_list($evals)) {
            $errors[] = sprintf('%s: `evals` must be an array', $path);
            return 0;
        }
        foreach ($evals as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? '?') : '?';
            if (!is_array($entry) || !array_key_exists('prompt', $entry)) {
                $errors[] = sprintf('%s: eval %s missing `prompt`', $path, $id);
            }
            if (!is_array($entry) || !array_key_exists('expected_output', $entry)) {
                $errors[] = sprintf('%s: eval %s missing `expected_output`', $path, $id);
            }
            if (is_array($entry) && array_key_exists('expectations', $entry) && !is_array($entry['expectations'])) {
                $errors[] = sprintf('%s: eval %s `expectations` must be an array', $path, $id);
            }
        }
        return count($evals);
    }

    /**
     * @param string[] $errors
     */
    private function validateTriggerEvals(string $path, array &$errors): int
    {
        $data = $this->decodeJson($path, $errors);
        if (!is_array($data)) {
            return 0;
        }
        if (!array_is_list($data)) {
            $errors[] = sprintf('%s: top-level must be an array', $path);
            return 0;
        }
        foreach ($data as $item) {
            if (!is_array($item) || !array_key_exists('query', $item)) {
                $errors[] = sprintf('%s: entry missing `query`', $path);
            }
            if (!is_array($item) || !array_key_exists('should_trigger', $item)) {
                $errors[] = sprintf('%s: entry missing `should_trigger`', $path);
            }
        }
        return count($data);
    }

    /**
     * @param string[] $errors
     */
    private function decodeJson(string $path, array &$errors): mixed
    {
        try {
            return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $errors[] = sprintf('%s: invalid JSON (%s)', $path, $exception->getMessage());
            return null;
        }
    }

    /**
     * @return string[]
     */
    private function glob(string $pattern): array
    {
        $matches = glob($this->repoRoot . '/' . $pattern, GLOB_BRACE) ?: [];
        sort($matches);
        return $matches;
    }
}
