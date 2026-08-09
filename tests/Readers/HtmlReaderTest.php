<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\Exception\HtmlParseException;
use Fissible\Transmark\Readers\HtmlReader;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_pretty_printed_paragraph_content_is_edge_trimmed(): void
    {
        $document = $this->read("<body><p>\n  Hello\n</p></body>");

        $inlines = $document->content()[0]->inlines();
        self::assertCount(1, $inlines);
        self::assertSame('Hello', $inlines[0]->content());
    }

    public function test_pretty_printed_heading_content_is_edge_trimmed(): void
    {
        $document = $this->read("<body><h1>\n  Title\n</h1></body>");

        $heading = $document->content()[0];
        self::assertSame('Title', $heading->inlines()[0]->content());
    }

    public function test_interior_spacing_inside_a_paragraph_survives_edge_trimming(): void
    {
        $document = $this->read('<p>text <span>x</span> after</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('text ', $inlines[0]->content());
        self::assertSame('x', $inlines[1]->content());
        self::assertSame(' after', $inlines[2]->content());
    }

    public function test_whitespace_only_paragraph_content_trims_to_empty_inlines(): void
    {
        $document = $this->read('<body><p>   </p></body>');

        self::assertSame([], $document->content()[0]->inlines());
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

    #[DataProvider('unmappableContentProvider')]
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

    public function test_mark_wrapping_content_at_inline_level_unwraps_transparently(): void
    {
        $document = $this->read('<p>before <mark>flagged</mark> after</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('before ', $inlines[0]->content());
        self::assertSame('flagged', $inlines[1]->content());
        self::assertSame(' after', $inlines[2]->content());
    }

    public function test_other_transparent_inline_wrappers_unwrap_at_inline_level(): void
    {
        $document = $this->read(
            '<p>text <abbr title="abbreviation">ABBR</abbr> and <small>small</small> and <cite>citation</cite> end</p>'
        );

        $inlines = $document->content()[0]->inlines();
        self::assertCount(7, $inlines);
        // Verify all content is preserved (exact split depends on whitespace handling)
        $contentText = implode('', array_map(fn ($inline) => $inline->content(), $inlines));
        self::assertStringContainsString('ABBR', $contentText);
        self::assertStringContainsString('small', $contentText);
        self::assertStringContainsString('citation', $contentText);
    }

    public function test_unrecognized_inline_tag_unwraps_instead_of_dropping_its_content(): void
    {
        $inlines = $this->read('<p>a <ins>inserted</ins> b</p>')->content()[0]->inlines();

        self::assertCount(3, $inlines);
        self::assertSame('a ', $inlines[0]->content());
        self::assertSame('inserted', $inlines[1]->content());
        self::assertSame(' b', $inlines[2]->content());
    }

    public function test_legacy_presentational_inline_tag_unwraps_instead_of_dropping_its_content(): void
    {
        $inlines = $this->read('<p>Hello <font color="red">world</font>!</p>')->content()[0]->inlines();

        self::assertCount(3, $inlines);
        self::assertSame('Hello ', $inlines[0]->content());
        self::assertSame('world', $inlines[1]->content());
        self::assertSame('!', $inlines[2]->content());
    }

    /**
     * @return string[][]
     */
    public static function unmappableInlinePositionProvider(): array
    {
        return [
            'button inside a paragraph' => ['<p>Click <button>here</button> now</p>', 'button'],
            'iframe inside a paragraph' => ['<p>See <iframe src="x"></iframe> ok</p>', 'iframe'],
            'custom element inside a paragraph' => ['<p>See <my-widget>stuff</my-widget> ok</p>', 'my-widget'],
            'button nested inside an inline wrapper' => ['<p><span><button>x</button></span></p>', 'button'],
        ];
    }

    #[DataProvider('unmappableInlinePositionProvider')]
    public function test_throws_on_unmappable_elements_in_inline_position(string $html, string $tag): void
    {
        $this->expectException(HtmlParseException::class);
        $this->expectExceptionMessageMatches('/<'.preg_quote($tag, '/').'>/');

        $this->read($html);
    }

    public function test_strip_tags_are_still_stripped_silently_in_inline_position(): void
    {
        $inlines = $this->read('<p>before <script>alert(1)</script>after</p>')->content()[0]->inlines();

        $text = implode('', array_map(static fn ($inline) => $inline->content(), $inlines));
        self::assertSame('before after', $text);
    }

    public function test_table_cell_with_block_content_keeps_that_content(): void
    {
        $html = '<table><tr><td><p>Cell para</p><ul><li>x</li></ul></td></tr></table>';

        $cell = $this->read($html)->content()[0]->rows()[0]->cells()[0];
        $content = $cell->content();

        self::assertCount(2, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);
        self::assertSame('Cell para', $content[0]->inlines()[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\ListNode::class, $content[1]);
        self::assertSame('x', $content[1]->items()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_table_cells_keep_content_whether_inline_or_block_wrapped(): void
    {
        $html = '<table><tr><td>plain</td><td><p>wrapped</p></td></tr></table>';

        $cells = $this->read($html)->content()[0]->rows()[0]->cells();

        self::assertCount(2, $cells);
        self::assertSame('plain', $cells[0]->content()[0]->inlines()[0]->content());
        self::assertSame('wrapped', $cells[1]->content()[0]->inlines()[0]->content());
    }

    public function test_an_empty_table_cell_still_produces_an_empty_paragraph(): void
    {
        $cells = $this->read('<table><tr><td></td><td>b</td></tr></table>')->content()[0]->rows()[0]->cells();

        self::assertCount(1, $cells[0]->content());
        self::assertInstanceOf(Paragraph::class, $cells[0]->content()[0]);
        self::assertSame([], $cells[0]->content()[0]->inlines());
    }

    public function test_throws_on_unmappable_content_inside_a_table_cell(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('<table><tr><td><button>x</button></td></tr></table>');
    }

    public function test_adjacent_inline_siblings_in_a_list_item_form_one_paragraph(): void
    {
        $html = '<ul><li>Point two with <a href="https://example.com">a link</a></li></ul>';

        $content = $this->read($html)->content()[0]->items()[0]->content();

        self::assertCount(1, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);

        $inlines = $content[0]->inlines();
        self::assertCount(2, $inlines);
        self::assertInstanceOf(Text::class, $inlines[0]);
        self::assertSame('Point two with ', $inlines[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Link::class, $inlines[1]);
        self::assertSame('a link', $inlines[1]->children()[0]->content());
    }

    public function test_adjacent_inline_siblings_in_a_div_form_one_paragraph(): void
    {
        $content = $this->read('<div>Hello <strong>world</strong> and more</div>')->content();

        self::assertCount(1, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);

        $inlines = $content[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('Hello ', $inlines[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[1]);
        self::assertSame('world', $inlines[1]->children()[0]->content());
        self::assertSame(' and more', $inlines[2]->content());
    }

    public function test_adjacent_inline_siblings_in_a_blockquote_form_one_paragraph(): void
    {
        $quote = $this->read('<blockquote>Quoted <em>words</em> here</blockquote>')->content()[0];

        $content = $quote->content();
        self::assertCount(1, $content);

        $inlines = $content[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('Quoted ', $inlines[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $inlines[1]);
        self::assertSame(' here', $inlines[2]->content());
    }

    public function test_legacy_and_lesser_known_phrasing_tags_join_the_surrounding_run(): void
    {
        $content = $this->read('<div>a <ins>b</ins> c</div>')->content();

        self::assertCount(1, $content);
        $inlines = $content[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('a ', $inlines[0]->content());
        self::assertSame('b', $inlines[1]->content());
        self::assertSame(' c', $inlines[2]->content());
    }

    public function test_font_tag_joins_the_surrounding_run(): void
    {
        $content = $this->read('<div>Hello <font color="red">world</font>!</div>')->content();

        self::assertCount(1, $content);
        $inlines = $content[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertSame('Hello ', $inlines[0]->content());
        self::assertSame('world', $inlines[1]->content());
        self::assertSame('!', $inlines[2]->content());
    }

    public function test_label_tag_joins_the_surrounding_run(): void
    {
        $content = $this->read('<li>x <label>Name</label> y</li>')->content();

        self::assertCount(1, $content);
    }

    public function test_whitespace_between_two_inline_siblings_is_preserved_inside_the_run(): void
    {
        $inlines = $this->read('<div><strong>a</strong> <em>b</em></div>')->content()[0]->inlines();

        self::assertCount(3, $inlines);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[0]);
        self::assertSame(' ', $inlines[1]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $inlines[2]);
    }

    public function test_an_inline_run_is_broken_by_a_genuine_block_sibling(): void
    {
        $content = $this->read('<div>lead in<p>a block</p>tail out</div>')->content();

        self::assertCount(3, $content);
        self::assertSame('lead in', $content[0]->inlines()[0]->content());
        self::assertSame('a block', $content[1]->inlines()[0]->content());
        self::assertSame('tail out', $content[2]->inlines()[0]->content());
    }

    public function test_whitespace_only_text_between_blocks_does_not_become_a_paragraph(): void
    {
        $content = $this->read("<div>\n  <p>One</p>\n  <p>Two</p>\n</div>")->content();

        self::assertCount(2, $content);
        self::assertSame('One', $content[0]->inlines()[0]->content());
        self::assertSame('Two', $content[1]->inlines()[0]->content());
    }

    public function test_extra_thead_rows_become_ordinary_rows_instead_of_being_dropped(): void
    {
        $html = '<table><thead><tr><th>A</th></tr><tr><th>B</th></tr></thead>'
            .'<tbody><tr><td>c</td></tr></tbody></table>';

        $table = $this->read($html)->content()[0];

        self::assertSame('A', $table->header()->cells()[0]->content()[0]->inlines()[0]->content());

        $rows = $table->rows();
        self::assertCount(2, $rows);
        self::assertSame('B', $rows[0]->cells()[0]->content()[0]->inlines()[0]->content());
        self::assertSame('c', $rows[1]->cells()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_a_table_caption_becomes_a_paragraph_before_the_table(): void
    {
        $html = '<table><caption>Table 1. <em>Results</em></caption><tr><td>a</td></tr></table>';

        $content = $this->read($html)->content();

        self::assertCount(2, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);
        self::assertSame('Table 1. ', $content[0]->inlines()[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $content[0]->inlines()[1]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Table::class, $content[1]);
        self::assertSame('a', $content[1]->rows()[0]->cells()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_a_table_without_a_caption_emits_only_the_table(): void
    {
        $content = $this->read('<table><tr><td>a</td></tr></table>')->content();

        self::assertCount(1, $content);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Table::class, $content[0]);
    }

    public function test_an_empty_language_class_reads_as_no_language(): void
    {
        $codeBlock = $this->read('<pre><code class="language-">x</code></pre>')->content()[0];

        self::assertSame('x', $codeBlock->content());
        self::assertNull($codeBlock->language());
    }

    public function test_does_not_wipe_libxml_errors_the_caller_already_had_buffered(): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            libxml_clear_errors();

            $dom = new \DOMDocument();
            $dom->loadXML('<a><b></a>');
            $before = count(libxml_get_errors());
            self::assertGreaterThan(0, $before);

            $this->read('<p><b>unclosed</p>');

            self::assertGreaterThanOrEqual($before, count(libxml_get_errors()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function test_restores_the_callers_libxml_internal_error_flag(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            $this->read('<p>hi</p>');

            self::assertFalse(libxml_use_internal_errors(false));
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function test_reads_a_realistic_messy_page_end_to_end(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/html/messy-page.html');
        $document = (new HtmlReader())->read($html);

        $content = $document->content();
        self::assertNotEmpty($content);

        // header/nav/main/article/footer are all unwrapped, contributing their
        // content directly rather than nesting or being dropped.
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Link::class, $this->firstInline($content[0]));
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Heading::class, $content[1]);
        self::assertSame('Article Title', $content[1]->inlines()[0]->content());

        // The first paragraph keeps its inline run intact rather than fragmenting.
        self::assertInstanceOf(Paragraph::class, $content[2]);
        $first = $content[2]->inlines();
        self::assertCount(5, $first);
        self::assertSame('First paragraph with ', $first[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $first[1]);
        self::assertSame('bold', $first[1]->children()[0]->content());
        self::assertSame(' and ', $first[2]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $first[3]);
        self::assertSame(' text.', $first[4]->content());

        $byClass = [];
        foreach ($content as $block) {
            $byClass[$block::class][] = $block;
        }

        // The list keeps both items, and the second item's text and link stay
        // in a single paragraph rather than splitting into two.
        self::assertArrayHasKey(\Fissible\Transmark\Nodes\Block\ListNode::class, $byClass);
        $items = $byClass[\Fissible\Transmark\Nodes\Block\ListNode::class][0]->items();
        self::assertCount(2, $items);
        self::assertSame('Point one', $items[0]->content()[0]->inlines()[0]->content());
        self::assertCount(1, $items[1]->content());
        $secondItem = $items[1]->content()[0]->inlines();
        self::assertCount(2, $secondItem);
        self::assertSame('Point two with ', $secondItem[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Link::class, $secondItem[1]);
        self::assertSame('https://example.com', $secondItem[1]->href());

        self::assertArrayHasKey(\Fissible\Transmark\Nodes\Block\BlockQuote::class, $byClass);
        $quote = $byClass[\Fissible\Transmark\Nodes\Block\BlockQuote::class][0];
        self::assertSame('A quoted remark.', $quote->content()[0]->inlines()[0]->content());

        self::assertArrayHasKey(\Fissible\Transmark\Nodes\Block\CodeBlock::class, $byClass);
        $codeBlock = $byClass[\Fissible\Transmark\Nodes\Block\CodeBlock::class][0];
        self::assertSame('echo "hi";', $codeBlock->content());
        self::assertSame('php', $codeBlock->language());

        // script/style/title/comment content must never appear anywhere in the tree.
        $text = $this->flattenText($content);
        self::assertStringNotContainsString('should be stripped', $text);
        self::assertStringNotContainsString('editorial note', $text);
        self::assertStringNotContainsString('font-family', $text);

        // ...and real content must, including content only reachable through
        // nested inline children, list items, and code blocks.
        self::assertStringContainsString('bold', $text);
        self::assertStringContainsString('a link', $text);
        self::assertStringContainsString('echo "hi";', $text);
        self::assertStringContainsString('Copyright notice', $text);
    }

    public function test_flatten_text_reaches_nested_inlines_table_cells_and_code_blocks(): void
    {
        // Guards the helper the integration test relies on: if flattenText
        // stops recursing, this fails rather than silently weakening that test.
        $html = '<p>outer <strong>bold <em>deep</em></strong></p>'
            .'<pre><code>code content</code></pre>'
            .'<table><thead><tr><th>Header cell</th></tr></thead>'
            .'<tbody><tr><td>Body cell</td></tr></tbody></table>';

        $text = $this->flattenText($this->read($html)->content());

        foreach (['outer ', 'bold ', 'deep', 'code content', 'Header cell', 'Body cell'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }

    public function test_reads_mixed_case_tags(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/html/mixed-case-tags.html');
        $document = (new HtmlReader())->read($html);

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[1]);
    }

    private function firstInline(\Fissible\Transmark\Contracts\BlockInterface $block): \Fissible\Transmark\Contracts\InlineInterface
    {
        return $block->inlines()[0];
    }

    /**
     * @param \Fissible\Transmark\Contracts\BlockInterface[] $blocks
     */
    private function flattenText(array $blocks): string
    {
        $text = '';

        foreach ($blocks as $block) {
            if (method_exists($block, 'inlines')) {
                $text .= $this->flattenInlineText($block->inlines());
            }
            if (method_exists($block, 'content')) {
                $content = $block->content();
                if (is_array($content)) {
                    $text .= $this->flattenText($content);
                } elseif (is_string($content)) {
                    // CodeBlock and friends carry their payload as a string.
                    $text .= $content;
                }
            }
            if (method_exists($block, 'items')) {
                foreach ($block->items() as $item) {
                    $text .= $this->flattenText($item->content());
                }
            }
            if (method_exists($block, 'header') && $block->header() !== null) {
                $text .= $this->flattenText($block->header()->cells());
            }
            if (method_exists($block, 'rows')) {
                foreach ($block->rows() as $row) {
                    $text .= $this->flattenText($row->cells());
                }
            }
        }

        return $text;
    }

    /**
     * @param \Fissible\Transmark\Contracts\InlineInterface[] $inlines
     */
    private function flattenInlineText(array $inlines): string
    {
        $text = '';

        foreach ($inlines as $inline) {
            if (method_exists($inline, 'content') && is_string($inline->content())) {
                $text .= $inline->content();
            }
            if (method_exists($inline, 'children')) {
                $text .= $this->flattenInlineText($inline->children());
            }
        }

        return $text;
    }
}
