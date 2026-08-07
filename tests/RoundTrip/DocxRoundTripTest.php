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
use Fissible\Transmark\Numbering\RestartRule;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Tests\Support\SemanticRoundTrip;
use Fissible\Transmark\Tests\Support\TreeEquivalence;
use Fissible\Transmark\Writers\DocxWriter;
use PHPUnit\Framework\TestCase;

final class DocxRoundTripTest extends TestCase
{
    public function test_core_blocks_are_idempotent_through_docx(): void
    {
        $this->assertRoundTrip(new Document([
            $this->paragraph('Plain'),
            new Heading(2, [new Text('Scope')]),
            new BlockQuote([new Paragraph([new Text('Quoted')], styleName: 'Quote')]),
            new HorizontalRule(),
        ]));
    }

    public function test_core_inline_formatting_and_whitespace_are_idempotent_through_docx(): void
    {
        $this->assertRoundTrip(new Document([new Paragraph([
            new Text(' leading  '),
            new Strong([new Emphasis([new Underline([new Strike([
                new Superscript([new Text('nested')]),
            ])])])]),
            new LineBreak(),
            new Subscript([new Text('after')]),
            new Text(' trailing '),
        ])]));
    }

    public function test_simple_and_legal_numbering_are_idempotent_through_docx(): void
    {
        $definitions = new NumberingDefinitions(
            abstractNums: [
                1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    1 => new Level(1, NumberFormat::LowerLetter, '%2)', start: 2),
                ], 'multilevel'),
                2 => new AbstractNum(2, [
                    0 => new Level(0, NumberFormat::UpperRoman, '%1.', start: 3),
                    1 => new Level(
                        1,
                        NumberFormat::Decimal,
                        '%1.%2.',
                        isLegal: true,
                        restartRule: RestartRule::Never,
                    ),
                ], 'multilevel'),
            ],
            nums: [
                10 => new Num(10, 1, [1 => 4]),
                20 => new Num(20, 2),
            ],
        );

        $this->assertRoundTrip(new Document(
            content: [
                $this->numberedParagraph('Simple parent', 10, 0),
                $this->numberedParagraph('Simple child', 10, 1),
                $this->numberedParagraph('Legal parent', 20, 0),
                $this->numberedParagraph('Legal child', 20, 1),
            ],
            numbering: $definitions,
        ));
    }

    public function test_structural_lists_are_documented_as_flat_numbered_paragraphs(): void
    {
        $document = new Document([new ListNode(ListNode::TYPE_ORDERED, [
            new ListItem([
                $this->paragraph('Parent'),
                new ListNode(ListNode::TYPE_UNORDERED, [
                    new ListItem([$this->paragraph('Child')]),
                ]),
            ]),
            new ListItem([$this->paragraph('Sibling')]),
        ], start: 3)]);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $this->roundTrip($document),
            'DOCX stores structural lists as flat paragraphs with numbering references.',
            function (Document $actual): void {
                self::assertSame(
                    ['Parent', 'Child', 'Sibling'],
                    array_map($this->paragraphText(...), $actual->content()),
                );
                self::assertSame([1, 2, 1], array_map(
                    static function (mixed $block): ?int {
                        self::assertInstanceOf(Paragraph::class, $block);

                        return $block->numbering()?->numId();
                    },
                    $actual->content(),
                ));
                self::assertSame([0, 1, 0], array_map(
                    static function (mixed $block): ?int {
                        self::assertInstanceOf(Paragraph::class, $block);

                        return $block->numbering()?->ilvl();
                    },
                    $actual->content(),
                ));
                self::assertSame([0 => 3], $actual->numbering()->num(1)?->levelOverrides());
                self::assertSame(
                    NumberFormat::Bullet,
                    $actual->numbering()->levelFor(2, 1)?->format(),
                );
            },
        );
    }

    public function test_links_and_inline_code_are_documented_as_visible_text_loss(): void
    {
        $document = new Document([new Paragraph([
            new Text('Visit '),
            new Link('https://example.com', [new Text('Example')], 'Example site'),
            new Text(' with '),
            new Code('inline-code'),
        ])]);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $this->roundTrip($document),
            'DocxReader preserves link and code text but does not reconstruct their inline nodes.',
            function (Document $actual): void {
                self::assertCount(1, $actual->content());
                self::assertSame('Visit Example with inline-code', $this->paragraphText(
                    $actual->content()[0],
                ));
                self::assertContainsOnlyInstancesOf(
                    Text::class,
                    $actual->content()[0]->inlines(),
                );
            },
        );
    }

    public function test_code_blocks_are_documented_as_styled_paragraphs(): void
    {
        $document = new Document([new CodeBlock("first line\nsecond line", 'php')]);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $this->roundTrip($document),
            'DocxReader preserves code-block presentation but reconstructs a styled paragraph.',
            static function (Document $actual): void {
                self::assertCount(1, $actual->content());
                $paragraph = $actual->content()[0];
                self::assertInstanceOf(Paragraph::class, $paragraph);
                self::assertSame('CodeBlock', $paragraph->styleName());
                self::assertCount(3, $paragraph->inlines());
                self::assertInstanceOf(Text::class, $paragraph->inlines()[0]);
                self::assertSame('first line', $paragraph->inlines()[0]->content());
                self::assertInstanceOf(LineBreak::class, $paragraph->inlines()[1]);
                self::assertInstanceOf(Text::class, $paragraph->inlines()[2]);
                self::assertSame('second line', $paragraph->inlines()[2]->content());
            },
        );
    }

    public function test_tables_are_documented_as_unread_by_the_current_docxreader(): void
    {
        $document = new Document([
            $this->paragraph('Before'),
            new Table([new TableRow([
                new TableCell([$this->paragraph('A')]),
                new TableCell([$this->paragraph('B')]),
            ])]),
            $this->paragraph('After'),
        ]);

        TreeEquivalence::assertExpectedLoss(
            $document,
            $this->roundTrip($document),
            'DocxWriter emits tables, but the current DocxReader only visits body paragraphs.',
            function (Document $actual): void {
                self::assertSame(
                    ['Before', 'After'],
                    array_map($this->paragraphText(...), $actual->content()),
                );
            },
        );
    }

    public function test_metadata_and_attributes_are_documented_as_not_serialized_to_docx(): void
    {
        $document = new Document(
            content: [new Paragraph(
                [new Text('Visible')],
                attributes: new Attributes('paragraph-id', ['important'], ['source' => 'test']),
            )],
            metadata: ['title' => 'Metadata title'],
        );

        TreeEquivalence::assertExpectedLoss(
            $document,
            $this->roundTrip($document),
            'The first DocxWriter version does not serialize canonical metadata or attributes.',
            function (Document $actual): void {
                self::assertSame([], $actual->metadata());
                self::assertSame('Visible', $this->paragraphText($actual->content()[0]));
                self::assertNull($actual->content()[0]->attributes()->id());
                self::assertSame([], $actual->content()[0]->attributes()->classes());
                self::assertSame([], $actual->content()[0]->attributes()->all());
            },
        );
    }

    private function assertRoundTrip(Document $document): void
    {
        TreeEquivalence::assertEquivalent($document, $this->roundTrip($document));
    }

    private function roundTrip(Document $document): Document
    {
        return SemanticRoundTrip::through($document, new DocxWriter(), new DocxReader());
    }

    private function paragraph(string $text): Paragraph
    {
        return new Paragraph([new Text($text)]);
    }

    private function numberedParagraph(string $text, int $numId, int $ilvl): Paragraph
    {
        return new Paragraph([new Text($text)], numbering: new NumberingRef($numId, $ilvl));
    }

    private function paragraphText(mixed $block): string
    {
        self::assertInstanceOf(Paragraph::class, $block);
        $text = '';

        foreach ($block->inlines() as $inline) {
            if ($inline instanceof Text) {
                $text .= $inline->content();
            }
        }

        return $text;
    }
}
