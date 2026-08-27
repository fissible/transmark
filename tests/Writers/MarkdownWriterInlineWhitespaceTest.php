<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\MarkdownReader;
use Fissible\Transmark\Writers\MarkdownWriter;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark's flanking rules reject emphasis delimiters that touch
 * whitespace ("** foo **" is literal text, not a strong), and code spans
 * lose one leading/trailing space when both edges are spaces. These
 * serialization details used to corrupt edge-whitespace content on a
 * markdown round-trip; these tests pin the corrected output.
 */
final class MarkdownWriterInlineWhitespaceTest extends TestCase
{
    public function test_strong_with_edge_whitespace_renders_parseable_delimiters(): void
    {
        $document = new Document([new Paragraph([new Strong([new Text(' foo ')])])]);

        // The trailing space is normalized away by the writer's document-level
        // rtrim and by CommonMark's paragraph-edge trim; what matters is that
        // the output parses as strong instead of literal "\*\*...\*\*" text.
        self::assertSame(" **foo**\n", (new MarkdownWriter())->write($document));
    }

    public function test_strong_with_leading_whitespace_moves_the_space_outside(): void
    {
        $document = new Document([new Paragraph([new Strong([new Text(' foo')])])]);

        self::assertSame(" **foo**\n", (new MarkdownWriter())->write($document));
    }

    public function test_emphasis_with_trailing_space_moves_the_space_outside(): void
    {
        $document = new Document([new Paragraph([new Emphasis([new Text('foo ')])])]);

        self::assertSame("*foo*\n", (new MarkdownWriter())->write($document));
    }

    public function test_strike_with_edge_whitespace_renders_parseable_delimiters(): void
    {
        $document = new Document([new Paragraph([new Strike([new Text(' a ')])])]);

        self::assertSame(" ~~a~~\n", (new MarkdownWriter())->write($document));
    }

    public function test_whitespace_only_emphasis_degrades_to_plain_text_without_leaking_delimiters(): void
    {
        $document = new Document([new Paragraph([new Emphasis([new Text('  ')])])]);

        // Whitespace-only content cannot be represented in emphasis; it must
        // not leak an unparseable "****" pair. The writer's rtrim then folds
        // the whitespace-only paragraph away, leaving just the newline.
        self::assertSame("\n", (new MarkdownWriter())->write($document));
    }

    public function test_code_span_with_edge_spaces_gets_double_padding(): void
    {
        $document = new Document([new Paragraph([new Text('a '), new Code(' foo '), new Text(' b')])]);

        self::assertSame("a `  foo  ` b\n", (new MarkdownWriter())->write($document));
    }

    public function test_code_span_of_only_spaces_needs_no_padding(): void
    {
        $document = new Document([new Paragraph([new Code('   ')])]);

        self::assertSame("`   `\n", (new MarkdownWriter())->write($document));
    }

    public function test_code_span_with_single_edged_space_needs_no_padding(): void
    {
        $document = new Document([new Paragraph([new Code(' foo')])]);

        self::assertSame("` foo`\n", (new MarkdownWriter())->write($document));
    }

    public function test_edge_whitespace_inline_formatting_round_trips_idempotently(): void
    {
        $reader = new MarkdownReader();
        $writer = new MarkdownWriter();

        // Mid-paragraph edge whitespace survives fully: the spaces move
        // outside the delimiters and re-read as paragraph text.
        $source = new Document([new Paragraph([
            new Text('a '),
            new Strong([new Text(' foo ')]),
            new Text(' b'),
        ])]);

        self::assertSame("a  **foo**  b\n", $writer->write($source));
        self::assertSame(
            $writer->write($source),
            $writer->write($reader->read($writer->write($source))),
        );
    }

    public function test_edge_whitespace_emphasis_round_trips_without_leaking_literal_delimiters(): void
    {
        $reader = new MarkdownReader();
        $writer = new MarkdownWriter();

        $markdown = $writer->write(new Document([new Paragraph([
            new Strong([new Text(' foo ')]),
            new Text(' '),
            new Emphasis([new Text('bar ')]),
        ])]));

        $document = $reader->read($markdown);
        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Strong::class, $paragraph->inlines()[0]);
        self::assertInstanceOf(Emphasis::class, $paragraph->inlines()[2]);
        self::assertStringNotContainsString('\*\*', $writer->write($document));
    }

    public function test_code_span_edge_spaces_round_trip_preserve_content(): void
    {
        $reader = new MarkdownReader();
        $writer = new MarkdownWriter();

        $source = new Document([new Paragraph([new Text('a '), new Code(' foo '), new Text(' b')])]);

        self::assertSame(
            $writer->write($source),
            $writer->write($reader->read($writer->write($source))),
        );
    }
}
