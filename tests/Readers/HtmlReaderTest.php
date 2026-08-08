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
}
