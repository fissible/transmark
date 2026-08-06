<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Readers\MarkdownReader;
use Fissible\Transmark\Writers\MarkdownWriter;
use PHPUnit\Framework\TestCase;

final class MarkdownWriterTest extends TestCase
{
    public function test_nested_listnode_roundtrips_through_markdownreader(): void
    {
        $writer = new MarkdownWriter();
        $document = new Document([
            new ListNode(ListNode::TYPE_ORDERED, [
                new ListItem([
                    $this->paragraph('Parent'),
                    new ListNode(ListNode::TYPE_UNORDERED, [
                        new ListItem([$this->paragraph('Child')]),
                    ]),
                ]),
                new ListItem([$this->paragraph('Sibling')]),
            ]),
        ]);

        $markdown = $writer->write($document);

        self::assertInstanceOf(WriterInterface::class, $writer);
        self::assertSame("1. Parent\n    - Child\n2. Sibling\n", $markdown);
        $roundTripped = (new MarkdownReader())->read($markdown)->content()[0];
        self::assertInstanceOf(ListNode::class, $roundTripped);
        self::assertInstanceOf(ListNode::class, $roundTripped->items()[0]->content()[1]);
    }

    public function test_ordered_list_start_value_is_preserved(): void
    {
        $document = new Document([
            new ListNode(ListNode::TYPE_ORDERED, [
                new ListItem([$this->paragraph('Third')]),
                new ListItem([$this->paragraph('Fourth')]),
            ], start: 3),
        ]);

        self::assertSame("3. Third\n4. Fourth\n", (new MarkdownWriter())->write($document));
    }

    public function test_simple_numbered_paragraphs_reconstruct_nested_list_syntax(): void
    {
        $document = new Document(
            content: [
                $this->numberedParagraph('Parent', 10, 0),
                $this->numberedParagraph('Child one', 10, 1),
                $this->numberedParagraph('Child two', 10, 1),
                $this->numberedParagraph('Sibling', 10, 0),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    1 => new Level(1, NumberFormat::LowerLetter, '%2.'),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame(
            "1. Parent\n    1. Child one\n    2. Child two\n2. Sibling\n",
            (new MarkdownWriter())->write($document),
        );
    }

    public function test_legal_numbered_paragraphs_fall_back_to_literal_label_text(): void
    {
        $document = new Document(
            content: [
                $this->numberedParagraph('Parent', 20, 0),
                $this->numberedParagraph('Child', 20, 1),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [2 => new AbstractNum(2, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    1 => new Level(1, NumberFormat::Decimal, '%1.%2.'),
                ])],
                nums: [20 => new Num(20, 2)],
            ),
        );

        $markdown = (new MarkdownWriter())->write($document);

        self::assertSame("1\\. Parent\n\n  1\\.1\\. Child\n", $markdown);
        $roundTripped = (new MarkdownReader())->read($markdown);
        self::assertInstanceOf(Paragraph::class, $roundTripped->content()[0]);
        self::assertSame('1. Parent', $roundTripped->content()[0]->inlines()[0]->content());
    }

    public function test_literal_markdown_characters_in_text_are_escaped(): void
    {
        $document = new Document([$this->paragraph('*literal* _text_ [not a link]')]);

        self::assertSame(
            "\\*literal\\* \\_text\\_ \\[not a link\\]\n",
            (new MarkdownWriter())->write($document),
        );
    }

    public function test_heading_level_maps_to_correct_hash_prefix(): void
    {
        $document = new Document([new Heading(3, [new Text('Terms')])]);

        self::assertSame("### Terms\n", (new MarkdownWriter())->write($document));
    }

    public function test_inline_nodes_map_to_markdown_and_raw_html_fallbacks(): void
    {
        $document = new Document([new Paragraph([
            new Strong([new Text('bold')]),
            new Text(' '),
            new Emphasis([new Text('italic')]),
            new Text(' '),
            new Strike([new Text('gone')]),
            new Text(' '),
            new Code('code'),
            new Text(' '),
            new Link('https://example.com', [new Text('link')], 'Title'),
            new Text(' '),
            new InlineImage('image.png', 'alt', 'Image'),
            new LineBreak(),
            new Underline([new Text('under')]),
            new Superscript([new Text('super')]),
            new Subscript([new Text('sub')]),
        ])]);

        self::assertSame(
            "**bold** *italic* ~~gone~~ `code` [link](https://example.com \"Title\") "
            ."![alt](image.png \"Image\")  \n<u>under</u><sup>super</sup><sub>sub</sub>\n",
            (new MarkdownWriter())->write($document),
        );
    }

    public function test_block_quote_code_block_and_rule_are_serialized(): void
    {
        $document = new Document([
            new BlockQuote([$this->paragraph('Quoted')]),
            new CodeBlock("echo 'ok';\n", 'php'),
            new HorizontalRule(),
        ]);

        self::assertSame(
            "> Quoted\n\n```php\necho 'ok';\n```\n\n---\n",
            (new MarkdownWriter())->write($document),
        );
    }

    public function test_gfm_table_serialization_preserves_header_rows_and_alignment(): void
    {
        $header = new TableRow([
            $this->cell('Name', 'left'),
            $this->cell('Value', 'right'),
        ]);
        $document = new Document([new Table([
            new TableRow([$this->cell('Alpha'), $this->cell('10')]),
        ], $header)]);

        $markdown = (new MarkdownWriter())->write($document);

        self::assertSame(
            "| Name | Value |\n| :--- | ---: |\n| Alpha | 10 |\n",
            $markdown,
        );
        $table = (new MarkdownReader())->read($markdown)->content()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertNotNull($table->header());
        self::assertCount(1, $table->rows());
    }

    private function paragraph(string $text): Paragraph
    {
        return new Paragraph([new Text($text)]);
    }

    private function numberedParagraph(string $text, int $numId, int $ilvl): Paragraph
    {
        return new Paragraph([new Text($text)], numbering: new NumberingRef($numId, $ilvl));
    }

    private function cell(string $text, ?string $alignment = null): TableCell
    {
        $attributes = $alignment === null
            ? new Attributes()
            : new Attributes(data: ['alignment' => $alignment]);

        return new TableCell([$this->paragraph($text)], attributes: $attributes);
    }
}
