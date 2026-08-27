<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\RawHtml;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\MarkdownReader;
use PHPUnit\Framework\TestCase;

final class MarkdownReaderTest extends TestCase
{
    public function test_heading_produces_a_heading_node_with_correct_level(): void
    {
        $reader = new MarkdownReader();
        $document = $reader->read('## Scope');

        self::assertInstanceOf(ReaderInterface::class, $reader);
        self::assertCount(1, $document->content());
        $heading = $document->content()[0];
        self::assertInstanceOf(Heading::class, $heading);
        self::assertSame(2, $heading->level());
        self::assertSame('Scope', $this->inlineText($heading->inlines()));
    }

    public function test_ordered_list_nesting_produces_nested_listnodes_not_numberingrefs(): void
    {
        $document = (new MarkdownReader())->read(<<<'MD'
1. Parent
    1. Child
        1. Grandchild
MD);

        $top = $document->content()[0];
        self::assertInstanceOf(ListNode::class, $top);
        self::assertSame(ListNode::TYPE_ORDERED, $top->type());
        $parent = $top->items()[0];
        self::assertInstanceOf(Paragraph::class, $parent->content()[0]);
        self::assertNull($parent->content()[0]->numbering());

        $second = $parent->content()[1];
        self::assertInstanceOf(ListNode::class, $second);
        $third = $second->items()[0]->content()[1];
        self::assertInstanceOf(ListNode::class, $third);
        self::assertSame('Grandchild', $this->paragraphText($third->items()[0]->content()[0]));
    }

    public function test_bullet_list_produces_an_unordered_listnode(): void
    {
        $list = (new MarkdownReader())->read("- first\n- second")->content()[0];

        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame(ListNode::TYPE_UNORDERED, $list->type());
        self::assertCount(2, $list->items());
    }

    public function test_ordered_list_start_value_is_preserved(): void
    {
        $list = (new MarkdownReader())->read("3. third\n4. fourth")->content()[0];

        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame(3, $list->start());
    }

    public function test_bold_and_emphasis_nest_correctly(): void
    {
        $paragraph = (new MarkdownReader())->read('***Important***')->content()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Emphasis::class, $paragraph->inlines()[0]);
        self::assertInstanceOf(Strong::class, $paragraph->inlines()[0]->children()[0]);
        self::assertSame('Important', $this->inlineText($paragraph->inlines()[0]->children()[0]->children()));
    }

    public function test_link_and_image_capture_urls_titles_and_alt_text(): void
    {
        $paragraph = (new MarkdownReader())->read(
            '[site](https://example.com "Site") ![diagram](diagram.png "Diagram")',
        )->content()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        $link = $paragraph->inlines()[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://example.com', $link->href());
        self::assertSame('Site', $link->title());

        $image = $paragraph->inlines()[2];
        self::assertInstanceOf(InlineImage::class, $image);
        self::assertSame('diagram.png', $image->src());
        self::assertSame('diagram', $image->alt());
        self::assertSame('Diagram', $image->title());
    }

    public function test_fenced_code_block_captures_literal_and_language(): void
    {
        $block = (new MarkdownReader())->read("```php\necho 'ok';\n```\n")->content()[0];

        self::assertInstanceOf(CodeBlock::class, $block);
        self::assertSame("echo 'ok';\n", $block->content());
        self::assertSame('php', $block->language());
    }

    public function test_inline_code_and_hard_line_break_map_to_inline_nodes(): void
    {
        $paragraph = (new MarkdownReader())->read("Before `code`  \nAfter")->content()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Code::class, $paragraph->inlines()[1]);
        self::assertSame('code', $paragraph->inlines()[1]->content());
        self::assertInstanceOf(LineBreak::class, $paragraph->inlines()[2]);
    }

    public function test_soft_line_break_maps_to_a_space_not_a_hard_break(): void
    {
        // A single trailing newline is a soft break: CommonMark renders it
        // as a space, not a <br>.
        $document = (new MarkdownReader())->read("line one\nline two\n");

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertCount(3, $paragraph->inlines());
        self::assertInstanceOf(Text::class, $paragraph->inlines()[0]);
        self::assertInstanceOf(Text::class, $paragraph->inlines()[1]);
        self::assertSame(' ', $paragraph->inlines()[1]->content());
        self::assertInstanceOf(Text::class, $paragraph->inlines()[2]);
    }

    public function test_backslash_hard_break_still_maps_to_a_line_break_node(): void
    {
        $paragraph = (new MarkdownReader())->read("line one\\\nline two\n")->content()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(LineBreak::class, $paragraph->inlines()[1]);
    }

    public function test_strikethrough_via_gfm_extension_produces_a_strike_node(): void
    {
        $paragraph = (new MarkdownReader())->read('~~removed~~')->content()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Strike::class, $paragraph->inlines()[0]);
        self::assertSame('removed', $this->inlineText($paragraph->inlines()[0]->children()));
    }

    public function test_gfm_table_maps_to_header_and_body_rows(): void
    {
        $table = (new MarkdownReader())->read(<<<'MD'
| Name | Value |
| :--- | ---: |
| Alpha | 10 |
MD)->content()[0];

        self::assertInstanceOf(Table::class, $table);
        self::assertNotNull($table->header());
        self::assertSame('Name', $this->tableCellText($table->header()->cells()[0]));
        self::assertSame('left', $table->header()->cells()[0]->attributes()->get('alignment'));
        self::assertSame('right', $table->header()->cells()[1]->attributes()->get('alignment'));
        self::assertCount(1, $table->rows());
        self::assertSame('10', $this->tableCellText($table->rows()[0]->cells()[1]));
    }

    public function test_block_quote_and_thematic_break_map_to_block_nodes(): void
    {
        $content = (new MarkdownReader())->read("> Quoted\n\n---")->content();

        self::assertInstanceOf(BlockQuote::class, $content[0]);
        self::assertSame('Quoted', $this->paragraphText($content[0]->content()[0]));
        self::assertInstanceOf(HorizontalRule::class, $content[1]);
    }

    public function test_raw_html_block_reads_as_a_paragraph_wrapping_raw_html(): void
    {
        $content = (new MarkdownReader())->read(
            "Before\n\n<div class=\"x\">hello <b>world</b></div>\n\nAfter\n",
        )->content();

        self::assertCount(3, $content);
        $middle = $content[1];
        self::assertInstanceOf(Paragraph::class, $middle);
        self::assertCount(1, $middle->inlines());
        self::assertInstanceOf(RawHtml::class, $middle->inlines()[0]);
        self::assertSame('<div class="x">hello <b>world</b></div>', $middle->inlines()[0]->content());
    }

    public function test_raw_html_inline_reads_as_a_raw_html_inline(): void
    {
        $paragraph = (new MarkdownReader())->read("a <br> b\n")->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);

        $inlines = $paragraph->inlines();
        self::assertCount(3, $inlines);
        self::assertInstanceOf(Text::class, $inlines[0]);
        self::assertInstanceOf(RawHtml::class, $inlines[1]);
        self::assertSame('<br>', $inlines[1]->content());
        self::assertInstanceOf(Text::class, $inlines[2]);
    }

    public function test_multiline_raw_html_block_keeps_its_literal_lines(): void
    {
        $content = (new MarkdownReader())->read("<pre>line1\nline2</pre>\n\ntext\n")->content();

        $paragraph = $content[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(RawHtml::class, $paragraph->inlines()[0]);
        self::assertSame("<pre>line1\nline2</pre>", $paragraph->inlines()[0]->content());
    }

    /**
     * @param array<int, \Fissible\Transmark\Contracts\InlineInterface> $inlines
     */
    private function inlineText(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            if ($inline instanceof Text) {
                $text .= $inline->content();
            } elseif (method_exists($inline, 'children')) {
                $text .= $this->inlineText($inline->children());
            }
        }

        return $text;
    }

    private function paragraphText(mixed $block): string
    {
        self::assertInstanceOf(Paragraph::class, $block);

        return $this->inlineText($block->inlines());
    }

    private function tableCellText(\Fissible\Transmark\Nodes\Block\TableCell $cell): string
    {
        return $this->paragraphText($cell->content()[0]);
    }
}
