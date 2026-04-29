<?php

declare(strict_types=1);

namespace Marketplace\Tests\Validation;

use Marketplace\Markdown\Frontmatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Frontmatter::class)]
final class FrontmatterTest extends TestCase
{
    public function testParsesNameAndDescription(): void
    {
        $fields = Frontmatter::parse("---\nname: foo\ndescription: bar baz\n---\nbody");
        $this->assertSame('foo', $fields['name']);
        $this->assertSame('bar baz', $fields['description']);
    }

    public function testReturnsEmptyOnNoFrontmatter(): void
    {
        $this->assertSame([], Frontmatter::parse('no frontmatter here'));
    }

    public function testReturnsEmptyOnUnclosedFrontmatter(): void
    {
        $this->assertSame([], Frontmatter::parse("---\nname: x\nbody"));
    }

    public function testHasOpeningDetectsLeadingMarker(): void
    {
        $this->assertTrue(Frontmatter::hasOpening("---\nname: x\n---\n"));
        $this->assertFalse(Frontmatter::hasOpening('no marker'));
    }
}
