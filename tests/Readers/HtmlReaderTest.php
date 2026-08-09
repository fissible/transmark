<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\Exception\HtmlParseException;
use Fissible\Transmark\Readers\HtmlReader;
use PHPUnit\Framework\TestCase;

final class HtmlReaderTest extends TestCase
{
    private function read(string $html): Document
    {
        return (new HtmlReader())->read($html);
    }

    public function test_reads_a_single_paragraph(): void
    {
        $document = $this->read('<html><body><p>Hello world</p></body></html>');

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);

        $inlines = $content[0]->inlines();
        self::assertCount(1, $inlines);
        self::assertInstanceOf(Text::class, $inlines[0]);
        self::assertSame('Hello world', $inlines[0]->content());
    }

    public function test_reads_multiple_paragraphs_in_order(): void
    {
        $document = $this->read('<body><p>First</p><p>Second</p></body>');

        $content = $document->content();
        self::assertCount(2, $content);
        self::assertSame('First', $content[0]->inlines()[0]->content());
        self::assertSame('Second', $content[1]->inlines()[0]->content());
    }

    public function test_bare_text_directly_under_body_becomes_a_paragraph(): void
    {
        $document = $this->read('<body>Just some text, no wrapper</body>');

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);
        self::assertSame('Just some text, no wrapper', $content[0]->inlines()[0]->content());
    }

    public function test_throws_on_empty_content(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('');
    }

    public function test_throws_on_whitespace_only_content(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('   ');
    }

    public function test_throws_when_there_is_no_parsable_content(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('<html><body></body></html>');
    }

    public function test_handles_utf8_content_correctly(): void
    {
        $document = $this->read('<p>café — 日本語 🎉</p>');

        self::assertSame('café — 日本語 🎉', $document->content()[0]->inlines()[0]->content());
    }

    public function test_does_not_throw_on_malformed_but_recoverable_markup(): void
    {
        $document = $this->read('<p>Unclosed paragraph<p>Second paragraph');

        self::assertNotEmpty($document->content());
    }

    public function test_reads_headings_h1_through_h6(): void
    {
        $html = '<body>'.implode('', array_map(
            static fn (int $n) => "<h{$n}>Heading {$n}</h{$n}>",
            range(1, 6),
        )).'</body>';

        $content = $this->read($html)->content();

        self::assertCount(6, $content);
        foreach ($content as $index => $heading) {
            self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Heading::class, $heading);
            self::assertSame($index + 1, $heading->level());
            self::assertSame('Heading '.($index + 1), $heading->inlines()[0]->content());
        }
    }

    public function test_reads_nested_inline_formatting(): void
    {
        $document = $this->read('<p><strong><em>bold italic</em></strong></p>');

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[0]);

        $children = $inlines[0]->children();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $children[0]);
        self::assertSame('bold italic', $children[0]->children()[0]->content());
    }

    public function test_reads_b_and_i_as_strong_and_emphasis(): void
    {
        $document = $this->read('<p><b>bold</b> <i>italic</i></p>');

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[0]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $inlines[2]);
    }

    public function test_reads_underline_strike_sub_sup_and_inline_code(): void
    {
        $document = $this->read(
            '<p><u>u</u><s>s</s><sub>sub</sub><sup>sup</sup><code>code</code></p>',
        );

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Underline::class, $inlines[0]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strike::class, $inlines[1]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Subscript::class, $inlines[2]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Superscript::class, $inlines[3]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Code::class, $inlines[4]);
        self::assertSame('code', $inlines[4]->content());
    }

    public function test_reads_links_with_title(): void
    {
        $document = $this->read('<p><a href="https://example.com" title="Example">link text</a></p>');

        $link = $document->content()[0]->inlines()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Link::class, $link);
        self::assertSame('https://example.com', $link->href());
        self::assertSame('Example', $link->title());
        self::assertSame('link text', $link->children()[0]->content());
    }

    public function test_reads_links_without_a_title(): void
    {
        $link = $this->read('<p><a href="https://example.com">text</a></p>')->content()[0]->inlines()[0];

        self::assertNull($link->title());
    }

    public function test_reads_line_breaks(): void
    {
        $document = $this->read('<p>line one<br>line two</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertSame('line one', $inlines[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\LineBreak::class, $inlines[1]);
        self::assertSame('line two', $inlines[2]->content());
    }
}
