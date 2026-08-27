<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\RawHtml;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\MarkdownReader;
use Fissible\Transmark\Writers\HtmlWriter;
use Fissible\Transmark\Writers\MarkdownWriter;
use PHPUnit\Framework\TestCase;

/**
 * RawHtml is author-controlled content passed through verbatim by the
 * Markdown and HTML writers (the same policy as pandoc/GitHub), so it
 * deliberately bypasses their normal escaping. These tests pin both the
 * verbatim emission and the round-trip behavior.
 */
final class RawHtmlRenderingTest extends TestCase
{
    public function test_markdown_writer_emits_a_raw_html_paragraph_verbatim(): void
    {
        $document = new Document([new Paragraph([new RawHtml('<div class="x">hello</div>')])]);

        self::assertSame(
            "<div class=\"x\">hello</div>\n",
            (new MarkdownWriter())->write($document),
        );
    }

    public function test_markdown_writer_emits_inline_raw_html_verbatim(): void
    {
        $document = new Document([new Paragraph([new Text('a '), new RawHtml('<br>'), new Text(' b')])]);

        self::assertSame("a <br> b\n", (new MarkdownWriter())->write($document));
    }

    public function test_html_writer_emits_a_raw_html_paragraph_without_a_wrapper(): void
    {
        $document = new Document([new Paragraph([new RawHtml('<div class="x">hello</div>')])]);

        self::assertSame('<div class="x">hello</div>', (new HtmlWriter())->write($document));
    }

    public function test_html_writer_emits_inline_raw_html_inside_the_paragraph(): void
    {
        $document = new Document([new Paragraph([new Text('a '), new RawHtml('<br>'), new Text(' b')])]);

        self::assertSame('<p>a <br> b</p>', (new HtmlWriter())->write($document));
    }

    public function test_raw_html_survives_a_markdown_round_trip(): void
    {
        $reader = new MarkdownReader(allowRawHtml: true);
        $writer = new MarkdownWriter();
        $source = "Before\n\n<div class=\"x\">hello <b>world</b></div>\n\nAfter\n";

        self::assertSame($source, $writer->write($reader->read($source)));
    }

    public function test_raw_html_in_markdown_flows_into_html_output(): void
    {
        $source = "Before\n\n<details><summary>More</summary>hidden</details>\n\nAfter\n";

        $html = (new HtmlWriter())->write((new MarkdownReader(allowRawHtml: true))->read($source));

        self::assertStringContainsString('<details><summary>More</summary>hidden</details>', $html);
        self::assertStringNotContainsString('&lt;details', $html);
    }

    public function test_script_in_markdown_is_kept_out_of_html_output_by_default(): void
    {
        $source = "Hello\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n";

        $html = (new HtmlWriter())->write((new MarkdownReader())->read($source));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('alert(', $html);
    }
}
