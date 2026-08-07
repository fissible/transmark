<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\RoundTrip;

use Fissible\Transmark\Attributes;
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
use Fissible\Transmark\Tests\Support\SemanticRoundTrip;
use Fissible\Transmark\Tests\Support\TreeEquivalence;
use Fissible\Transmark\Writers\MarkdownWriter;
use PHPUnit\Framework\TestCase;

final class MarkdownRoundTripTest extends TestCase
{
    public function test_nested_lists_are_idempotent_through_markdown(): void
    {
        $document = new Document([
            new ListNode(ListNode::TYPE_ORDERED, [
                new ListItem([
                    $this->paragraph('Parent'),
                    new ListNode(ListNode::TYPE_UNORDERED, [
                        new ListItem([$this->paragraph('Child')]),
                    ]),
                ]),
                new ListItem([$this->paragraph('Sibling')]),
            ], start: 3),
        ]);

        $this->assertRoundTrip($document);
    }

    public function test_supported_inline_formatting_is_idempotent_through_markdown(): void
    {
        $document = new Document([new Paragraph([
            new Text('Lead '),
            new Strong([new Text('bold')]),
            new Text(' and '),
            new Emphasis([new Text('italic')]),
            new Text(' plus '),
            new Strike([new Text('removed')]),
            new Text(' with '),
            new Code('code'),
            new Text(' and '),
            new Link('https://example.com/a_(b)', [new Text('a link')], 'Link title'),
            new Text(' '),
            new InlineImage('diagram.png', 'diagram', 'Diagram'),
            new LineBreak(),
            new Text('Next line with *literal* markers.'),
        ])]);

        $this->assertRoundTrip($document);
    }

    public function test_headings_are_idempotent_through_markdown(): void
    {
        $this->assertRoundTrip(new Document([
            new Heading(1, [new Text('Agreement')]),
            new Heading(4, [new Text('Details')]),
        ]));
    }

    public function test_block_quote_code_and_rule_are_idempotent_through_markdown(): void
    {
        $this->assertRoundTrip(new Document([
            new BlockQuote([$this->paragraph('Quoted text')]),
            new CodeBlock("echo 'ok';\n", 'php'),
            new HorizontalRule(),
        ]));
    }

    public function test_gfm_table_is_idempotent_through_markdown(): void
    {
        $header = new TableRow([
            $this->cell('Name', 'left'),
            $this->cell('Value', 'right'),
        ]);
        $row = new TableRow([
            $this->cell('Alpha', 'left'),
            $this->cell('10', 'right'),
        ]);

        $this->assertRoundTrip(new Document([new Table([$row], $header)]));
    }

    public function test_plain_text_with_markdown_metacharacters_is_idempotent(): void
    {
        $this->assertRoundTrip(new Document([
            $this->paragraph('# not a heading; [not](a link); *not emphasis*'),
        ]));
    }

    public function test_simple_ooxml_numbering_is_documented_as_lossy_through_markdown(): void
    {
        $document = new Document(
            content: [
                $this->numberedParagraph('First', 10, 0),
                $this->numberedParagraph('Child', 10, 1),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    1 => new Level(1, NumberFormat::Decimal, '%2.'),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        $roundTripped = $this->roundTrip($document);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $roundTripped,
            'Markdown uses structural lists and cannot reconstruct OOXML numId/ilvl pointers.',
            static function (Document $actual): void {
                self::assertInstanceOf(ListNode::class, $actual->content()[0]);
            },
        );
    }

    public function test_legal_outline_numbering_is_documented_as_lossy_through_markdown(): void
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

        $roundTripped = $this->roundTrip($document);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $roundTripped,
            'Legal labels become literal text because Markdown has no cross-level counter model.',
            function (Document $actual): void {
                self::assertSame(['1. Parent', '1.1. Child'], $this->paragraphTexts($actual));
            },
        );
    }

    public function test_raw_html_inline_fallbacks_are_documented_as_lossy_through_markdown(): void
    {
        $document = new Document([new Paragraph([
            new Underline([new Text('under')]),
            new Text(' '),
            new Superscript([new Text('super')]),
            new Text(' '),
            new Subscript([new Text('sub')]),
        ])]);

        $roundTripped = $this->roundTrip($document);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $roundTripped,
            'Raw HTML preserves visible text but MarkdownReader does not infer formatting nodes from HTML.',
            function (Document $actual): void {
                self::assertSame('under super sub', $this->paragraphTexts($actual)[0]);
            },
        );
    }

    private function assertRoundTrip(Document $document): void
    {
        TreeEquivalence::assertEquivalent($document, $this->roundTrip($document));
    }

    private function roundTrip(Document $document): Document
    {
        return SemanticRoundTrip::through(
            $document,
            new MarkdownWriter(),
            new MarkdownReader(),
        );
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

    /**
     * @return string[]
     */
    private function paragraphTexts(Document $document): array
    {
        $texts = [];
        foreach ($document->content() as $block) {
            if (!$block instanceof Paragraph) {
                continue;
            }

            $text = '';
            foreach ($block->inlines() as $inline) {
                if ($inline instanceof Text) {
                    $text .= $inline->content();
                }
            }
            $texts[] = $text;
        }

        return $texts;
    }
}
