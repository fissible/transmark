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

    public function test_reads_an_unordered_list(): void
    {
        $document = $this->read('<ul><li>One</li><li>Two</li></ul>');

        $list = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\ListNode::class, $list);
        self::assertSame(\Fissible\Transmark\Nodes\Block\ListNode::TYPE_UNORDERED, $list->type());
        self::assertCount(2, $list->items());
        self::assertSame('One', $list->items()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_reads_an_ordered_list_with_a_start_attribute(): void
    {
        $document = $this->read('<ol start="5"><li>Five</li><li>Six</li></ol>');

        $list = $document->content()[0];
        self::assertSame(\Fissible\Transmark\Nodes\Block\ListNode::TYPE_ORDERED, $list->type());
        self::assertSame(5, $list->start());
    }

    public function test_reads_a_nested_list(): void
    {
        $document = $this->read('<ul><li>A<ul><li>Nested</li></ul></li></ul>');

        $outerItem = $document->content()[0]->items()[0];
        $content = $outerItem->content();

        self::assertSame('A', $content[0]->inlines()[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\ListNode::class, $content[1]);
        self::assertSame('Nested', $content[1]->items()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_reads_a_blockquote(): void
    {
        $document = $this->read('<blockquote><p>Quoted text</p></blockquote>');

        $quote = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\BlockQuote::class, $quote);
        self::assertSame('Quoted text', $quote->content()[0]->inlines()[0]->content());
    }

    public function test_reads_a_horizontal_rule(): void
    {
        $document = $this->read('<p>Before</p><hr><p>After</p>');

        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\HorizontalRule::class, $document->content()[1]);
    }

    public function test_reads_a_code_block_with_a_language(): void
    {
        $document = $this->read('<pre><code class="language-php">echo 1;</code></pre>');

        $codeBlock = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\CodeBlock::class, $codeBlock);
        self::assertSame('echo 1;', $codeBlock->content());
        self::assertSame('php', $codeBlock->language());
    }

    public function test_reads_a_code_block_without_a_language(): void
    {
        $document = $this->read('<pre><code>plain</code></pre>');

        $codeBlock = $document->content()[0];
        self::assertSame('plain', $codeBlock->content());
        self::assertNull($codeBlock->language());
    }

    public function test_reads_a_bare_pre_with_no_code_child(): void
    {
        $document = $this->read('<pre>raw preformatted text</pre>');

        self::assertSame('raw preformatted text', $document->content()[0]->content());
    }

    public function test_reads_a_table_with_thead_and_tbody(): void
    {
        $html = '<table><thead><tr><th>Name</th><th>Age</th></tr></thead>'
            .'<tbody><tr><td>Alice</td><td>30</td></tr></tbody></table>';

        $table = $this->read($html)->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Table::class, $table);

        $header = $table->header();
        self::assertNotNull($header);
        self::assertSame('Name', $header->cells()[0]->content()[0]->inlines()[0]->content());

        $rows = $table->rows();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->cells()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_reads_a_table_with_no_thead(): void
    {
        $table = $this->read('<table><tr><td>A</td></tr><tr><td>B</td></tr></table>')->content()[0];

        self::assertNull($table->header());
        self::assertCount(2, $table->rows());
    }

    public function test_reads_colspan_and_rowspan(): void
    {
        $table = $this->read('<table><tr><td colspan="2" rowspan="3">merged</td></tr></table>')->content()[0];
        $cell = $table->rows()[0]->cells()[0];

        self::assertSame(2, $cell->colspan());
        self::assertSame(3, $cell->rowspan());
    }

    public function test_clamps_malformed_colspan_and_rowspan_to_minimum_1(): void
    {
        $table = $this->read('<table><tr><td colspan="abc" rowspan="0">cell</td></tr></table>')->content()[0];
        $cell = $table->rows()[0]->cells()[0];

        self::assertSame(1, $cell->colspan());
        self::assertSame(1, $cell->rowspan());
    }

    public function test_clamps_negative_colspan_and_rowspan_to_minimum_1(): void
    {
        $table = $this->read('<table><tr><td colspan="-5" rowspan="-2">cell</td></tr></table>')->content()[0];
        $cell = $table->rows()[0]->cells()[0];

        self::assertSame(1, $cell->colspan());
        self::assertSame(1, $cell->rowspan());
    }

    public function test_reads_a_block_level_image(): void
    {
        $document = $this->read('<body><img src="photo.jpg" alt="A photo" title="Title"></body>');

        $image = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Image::class, $image);
        self::assertSame('photo.jpg', $image->src());
        self::assertSame('A photo', $image->alt());
        self::assertSame('Title', $image->title());
    }

    public function test_reads_an_inline_image_inside_a_paragraph(): void
    {
        $document = $this->read('<p>See <img src="icon.png" alt="icon"> here</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\InlineImage::class, $inlines[1]);
        self::assertSame('icon.png', $inlines[1]->src());
    }

    public function test_strips_script_and_style_without_throwing(): void
    {
        $document = $this->read(
            '<html><head><style>p{color:red}</style></head>'
            .'<body><script>alert(1)</script><p>Real content</p></body></html>',
        );

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertSame('Real content', $content[0]->inlines()[0]->content());
    }

    public function test_strips_html_comments_without_throwing_or_mapping_to_the_comment_node(): void
    {
        $document = $this->read('<body><!-- a comment --><p>Real content</p></body>');

        self::assertCount(1, $document->content());
    }

    public function test_unwraps_div_and_semantic_wrappers_transparently(): void
    {
        $document = $this->read(
            '<body><div><section><article><p>Deeply wrapped</p></article></section></div></body>',
        );

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Paragraph::class, $content[0]);
        self::assertSame('Deeply wrapped', $content[0]->inlines()[0]->content());
    }

    public function test_unwrapping_a_container_can_contribute_multiple_sibling_blocks(): void
    {
        $document = $this->read('<div><p>One</p><p>Two</p></div>');

        self::assertCount(2, $document->content());
    }

    public function test_unknown_inline_level_tag_in_block_position_flattens_to_a_paragraph(): void
    {
        $document = $this->read('<body><mark>highlighted text</mark></body>');

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Paragraph::class, $content[0]);
        self::assertSame('highlighted text', $content[0]->inlines()[0]->content());
    }

    /**
     * @return string[]
     */
    public static function unmappableContentProvider(): array
    {
        return [
            'form' => ['<form><input type="text"></form>'],
            'canvas' => ['<canvas width="10" height="10"></canvas>'],
            'svg' => ['<svg><circle r="5"/></svg>'],
            'iframe' => ['<iframe src="https://example.com"></iframe>'],
            'video' => ['<video src="movie.mp4"></video>'],
            'custom element' => ['<my-widget>content</my-widget>'],
        ];
    }

    /**
     * @dataProvider unmappableContentProvider
     */
    public function test_throws_on_unmappable_content_elements(string $html): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read($html);
    }

    public function test_exception_message_names_the_offending_tag(): void
    {
        try {
            $this->read('<form></form><p>fallback so body is not otherwise empty</p>');
            self::fail('Expected HtmlParseException was not thrown.');
        } catch (HtmlParseException $exception) {
            self::assertStringContainsString('form', $exception->getMessage());
        }
    }

    public function test_span_wrapping_content_at_inline_level_unwraps_transparently(): void
    {
        $document = $this->read('<p>text <span>more text</span> after</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('text ', $inlines[0]->content());
        self::assertSame('more text', $inlines[1]->content());
        self::assertSame(' after', $inlines[2]->content());
    }
}
