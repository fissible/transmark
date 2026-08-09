<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Writers\Exception\UnsupportedHtmlNodeException;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class HtmlWriterBlockRenderingTest extends TestCase
{
    private function paragraph(string $text): Paragraph
    {
        return new Paragraph([new Text($text)]);
    }

    public function test_renders_a_blockquote(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new BlockQuote([$this->paragraph('Quoted text')]),
        ]));

        self::assertSame('<blockquote><p>Quoted text</p></blockquote>', $html);
    }

    public function test_renders_a_horizontal_rule(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            $this->paragraph('Before'),
            new HorizontalRule(),
            $this->paragraph('After'),
        ]));

        self::assertSame('<p>Before</p><hr><p>After</p>', $html);
    }

    public function test_renders_a_code_block_with_a_language(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new CodeBlock('echo 1;', 'php'),
        ]));

        self::assertSame('<pre><code class="language-php">echo 1;</code></pre>', $html);
    }

    public function test_renders_a_code_block_without_a_language(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new CodeBlock('plain text'),
        ]));

        self::assertSame('<pre><code>plain text</code></pre>', $html);
    }

    public function test_escapes_code_block_content(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new CodeBlock('<script>alert(1)</script>'),
        ]));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_throws_on_an_unsupported_block_type(): void
    {
        // ListItem is a real BlockInterface implementation, but it's only
        // ever meant to appear nested inside a ListNode, never as a bare
        // top-level document block - a valid stand-in for "genuinely
        // unsupported at this position" now that every real content block
        // type (including Table and Image) has a renderBlock() case.
        $this->expectException(UnsupportedHtmlNodeException::class);

        (new HtmlWriter())->write(new Document([
            new ListItem([$this->paragraph('Orphaned list item')]),
        ]));
    }
}
