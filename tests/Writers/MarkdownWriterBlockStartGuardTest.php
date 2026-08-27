<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\MarkdownReader;
use Fissible\Transmark\Tests\Support\TreeEquivalence;
use Fissible\Transmark\Writers\MarkdownWriter;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark treats a first line indented 4+ columns (tab or spaces) as an
 * indented code block and a line starting with 0-3 spaces plus a list marker
 * as a list. A Paragraph whose text starts that way — reachable from HTML or
 * DOCX sources — must not re-parse as a different block type.
 */
final class MarkdownWriterBlockStartGuardTest extends TestCase
{
    public function test_paragraph_with_a_leading_tab_does_not_become_a_code_block(): void
    {
        $document = new Document([new Paragraph([new Text("\t"), new Text("indented")])]);

        $markdown = (new MarkdownWriter())->write($document);

        self::assertSame("   indented\n", $markdown);
        self::assertInstanceOf(Paragraph::class, (new MarkdownReader())->read($markdown)->content()[0]);
    }

    public function test_paragraph_with_four_leading_spaces_does_not_become_a_code_block(): void
    {
        $document = new Document([new Paragraph([new Text('    indented')])]);

        $markdown = (new MarkdownWriter())->write($document);

        self::assertSame("   indented\n", $markdown);
        self::assertInstanceOf(Paragraph::class, (new MarkdownReader())->read($markdown)->content()[0]);
    }

    public function test_space_plus_tab_indentation_is_normalized(): void
    {
        // " \t" computes to 4 columns of indentation (tab stop at 4).
        $document = new Document([new Paragraph([new Text(" \tindented")])]);

        $markdown = (new MarkdownWriter())->write($document);

        self::assertSame("   indented\n", $markdown);
        self::assertInstanceOf(Paragraph::class, (new MarkdownReader())->read($markdown)->content()[0]);
    }

    public function test_three_or_fewer_leading_spaces_are_left_alone(): void
    {
        $document = new Document([new Paragraph([new Text('   indented')])]);

        self::assertSame("   indented\n", (new MarkdownWriter())->write($document));
    }

    public function test_paragraph_text_with_up_to_three_leading_spaces_before_a_marker_is_escaped(): void
    {
        $document = new Document([
            new Paragraph([new Text(' - foo')]),
            new Paragraph([new Text('   + foo')]),
            new Paragraph([new Text(' 1. foo')]),
        ]);

        self::assertSame(
            " \\- foo\n\n   \\+ foo\n\n 1\\. foo\n",
            (new MarkdownWriter())->write($document),
        );
    }

    public function test_marker_guard_applies_to_every_line_of_a_paragraph(): void
    {
        $document = new Document([new Paragraph([new Text("first line\n- second")])]);

        self::assertSame("first line\n\\- second\n", (new MarkdownWriter())->write($document));

        $reparsed = (new MarkdownReader())->read((new MarkdownWriter())->write($document))->content()[0];
        self::assertInstanceOf(Paragraph::class, $reparsed);
    }

    public function test_marker_guarded_paragraphs_round_trip_to_equivalent_trees(): void
    {
        $reader = new MarkdownReader();
        $writer = new MarkdownWriter();

        foreach (['- bar', '+ qux', '1. foo', '10) baz'] as $text) {
            $source = new Document([new Paragraph([new Text($text)])]);

            TreeEquivalence::assertEquivalent($source, $reader->read($writer->write($source)));
        }
    }

    public function test_leading_space_markers_stay_paragraphs_after_a_round_trip(): void
    {
        $reader = new MarkdownReader();
        $writer = new MarkdownWriter();

        // CommonMark trims paragraph-edge whitespace, so the leading spaces
        // cannot survive exactly — but the paragraph must, not a list.
        foreach ([' - foo', '   + foo', ' 1. foo'] as $text) {
            $reparsed = $reader->read($writer->write(new Document([new Paragraph([new Text($text)])])))->content()[0];

            self::assertInstanceOf(Paragraph::class, $reparsed);
        }
    }
}
