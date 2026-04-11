#!/usr/bin/env python3
"""Unit tests for plugins/utopia-experts/scripts/generate_index.py.

Tests the core functions (frontmatter parsing, category lookup) in
isolation and verifies the full generate run is idempotent against
the real skill set.
"""

import sys
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).parent.parent.resolve()
sys.path.insert(0, str(REPO_ROOT / 'plugins' / 'utopia-experts' / 'scripts'))
import generate_index as gi


class ParseFrontmatterTests(unittest.TestCase):
    def test_extracts_name_and_description(self) -> None:
        content = '---\nname: utopia-http-expert\ndescription: HTTP framework\n---\n# body'
        fields = gi.parse_frontmatter(content)
        self.assertEqual(fields['name'], 'utopia-http-expert')
        self.assertEqual(fields['description'], 'HTTP framework')

    def test_returns_empty_on_no_frontmatter(self) -> None:
        self.assertEqual(gi.parse_frontmatter('no frontmatter'), {})

    def test_returns_empty_on_unclosed_block(self) -> None:
        self.assertEqual(gi.parse_frontmatter('---\nname: x\nbody'), {})


class CategoryLookupTests(unittest.TestCase):
    def test_known_skills_get_correct_category(self) -> None:
        self.assertEqual(gi.lookup_category('utopia-http-expert'), 'framework')
        self.assertEqual(gi.lookup_category('utopia-database-expert'), 'data')
        self.assertEqual(gi.lookup_category('utopia-cache-expert'), 'storage-io')
        self.assertEqual(gi.lookup_category('utopia-abuse-expert'), 'auth-security')
        self.assertEqual(gi.lookup_category('utopia-cli-expert'), 'runtime')
        self.assertEqual(gi.lookup_category('utopia-logger-expert'), 'observability')
        self.assertEqual(gi.lookup_category('utopia-messaging-expert'), 'messaging-async')
        self.assertEqual(gi.lookup_category('utopia-pay-expert'), 'domain')
        self.assertEqual(gi.lookup_category('utopia-ab-expert'), 'utilities')
        self.assertEqual(gi.lookup_category('utopia-console-expert'), 'misc')

    def test_unknown_skill_falls_through_to_other(self) -> None:
        self.assertEqual(gi.lookup_category('utopia-mystery-expert'), 'other')

    def test_all_50_skills_have_a_category(self) -> None:
        all_skills: list[str] = []
        for skills in gi.CATEGORIES.values():
            all_skills.extend(skills)
        self.assertEqual(len(all_skills), 50)
        for skill in all_skills:
            self.assertNotEqual(gi.lookup_category(skill), 'other', f'{skill} has no category')


class IdempotencyTests(unittest.TestCase):
    def test_regenerate_produces_same_output(self) -> None:
        index_path = REPO_ROOT / 'plugins' / 'utopia-experts' / 'skills' / 'INDEX.md'
        if not index_path.exists():
            self.skipTest('INDEX.md not found — run generate_index.py first')
        before = index_path.read_text()
        gi.main()
        after = index_path.read_text()
        self.assertEqual(before, after, 'INDEX.md changed after regeneration — generator is not idempotent')


if __name__ == '__main__':
    unittest.main(verbosity=2)
