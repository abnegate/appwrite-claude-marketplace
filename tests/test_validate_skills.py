#!/usr/bin/env python3
"""Unit tests for scripts/validate_skills.py.

Creates temporary fixture plugins to exercise every validation path
without touching the real plugin tree.
"""

import json
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

# Import the validator module from the scripts directory.
REPO_ROOT = Path(__file__).parent.parent.resolve()
sys.path.insert(0, str(REPO_ROOT / 'scripts'))
import validate_skills as vs


def _write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(textwrap.dedent(content).lstrip())


class ParseFrontmatterTests(unittest.TestCase):
    def test_parses_name_and_description(self) -> None:
        content = '---\nname: foo\ndescription: bar baz\n---\nbody'
        fields = vs.parse_frontmatter(content)
        self.assertEqual(fields['name'], 'foo')
        self.assertEqual(fields['description'], 'bar baz')

    def test_returns_empty_on_no_frontmatter(self) -> None:
        self.assertEqual(vs.parse_frontmatter('no frontmatter here'), {})

    def test_returns_empty_on_unclosed_frontmatter(self) -> None:
        self.assertEqual(vs.parse_frontmatter('---\nname: x\nbody'), {})


class ValidateSkillTests(unittest.TestCase):
    def test_valid_skill_passes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'my-skill' / 'SKILL.md'
            _write(path, '---\nname: my-skill\ndescription: A skill\n---\nbody')
            errors: list[str] = []
            vs.validate_skill(path, errors, {})
            self.assertEqual(errors, [])

    def test_name_mismatch_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'my-skill' / 'SKILL.md'
            _write(path, '---\nname: wrong-name\ndescription: A skill\n---\nbody')
            errors: list[str] = []
            vs.validate_skill(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('name mismatch', errors[0])

    def test_missing_name_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'my-skill' / 'SKILL.md'
            _write(path, '---\ndescription: A skill\n---\nbody')
            errors: list[str] = []
            vs.validate_skill(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('missing `name:`', errors[0])

    def test_missing_description_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'my-skill' / 'SKILL.md'
            _write(path, '---\nname: my-skill\n---\nbody')
            errors: list[str] = []
            vs.validate_skill(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('missing `description:`', errors[0])

    def test_description_too_long_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'my-skill' / 'SKILL.md'
            long_desc = 'x' * (vs.MAX_DESCRIPTION_CHARS + 1)
            _write(path, f'---\nname: my-skill\ndescription: {long_desc}\n---\nbody')
            errors: list[str] = []
            vs.validate_skill(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('chars', errors[0])

    def test_duplicate_names_fail(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            # Two different plugin trees, each with a skill dir called 'my-skill'
            path1 = Path(tmp) / 'plugin-a' / 'skills' / 'my-skill' / 'SKILL.md'
            path2 = Path(tmp) / 'plugin-b' / 'skills' / 'my-skill' / 'SKILL.md'
            _write(path1, '---\nname: my-skill\ndescription: A\n---\nbody')
            _write(path2, '---\nname: my-skill\ndescription: B\n---\nbody')
            errors: list[str] = []
            seen: dict[str, Path] = {}
            vs.validate_skill(path1, errors, seen)
            vs.validate_skill(path2, errors, seen)
            self.assertEqual(len(errors), 1)
            self.assertIn('duplicate', errors[0])

    def test_missing_frontmatter_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'my-skill' / 'SKILL.md'
            _write(path, 'no frontmatter')
            errors: list[str] = []
            vs.validate_skill(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('missing frontmatter', errors[0])


class ValidateCommandTests(unittest.TestCase):
    def test_valid_command_passes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'foo.md'
            _write(path, '---\ndescription: Does stuff\n---\n# /foo')
            errors: list[str] = []
            vs.validate_command(path, errors, {})
            self.assertEqual(errors, [])

    def test_missing_description_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'foo.md'
            _write(path, '---\nargument-hint: x\n---\n# /foo')
            errors: list[str] = []
            vs.validate_command(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('description', errors[0])


class ValidateAgentTests(unittest.TestCase):
    def test_valid_agent_passes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'router.md'
            _write(path, '---\nname: router\ndescription: Routes\n---\nbody')
            errors: list[str] = []
            vs.validate_agent(path, errors, {})
            self.assertEqual(errors, [])

    def test_missing_name_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'router.md'
            _write(path, '---\ndescription: Routes\n---\nbody')
            errors: list[str] = []
            vs.validate_agent(path, errors, {})
            self.assertEqual(len(errors), 1)
            self.assertIn('name', errors[0])


class ValidateJsonManifestTests(unittest.TestCase):
    def test_valid_manifest_passes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'plugin.json'
            path.write_text(json.dumps({'name': 'x', 'description': 'y'}))
            errors: list[str] = []
            vs.validate_json_manifest(path, errors)
            self.assertEqual(errors, [])

    def test_invalid_json_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'plugin.json'
            path.write_text('{bad json')
            errors: list[str] = []
            vs.validate_json_manifest(path, errors)
            self.assertEqual(len(errors), 1)
            self.assertIn('invalid JSON', errors[0])

    def test_missing_name_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'plugin.json'
            path.write_text(json.dumps({'description': 'y'}))
            errors: list[str] = []
            vs.validate_json_manifest(path, errors)
            self.assertEqual(len(errors), 1)
            self.assertIn('name', errors[0])


if __name__ == '__main__':
    unittest.main(verbosity=2)
