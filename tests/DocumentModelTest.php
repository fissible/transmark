<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use PHPUnit\Framework\TestCase;

final class DocumentModelTest extends TestCase
{
    public function test_paragraph_carries_a_numbering_pointer_not_a_label(): void
    {
        $paragraph = new Paragraph(
            [new Text('Term of Agreement')],
            numbering: new NumberingRef(numId: 3, ilvl: 0),
        );

        self::assertTrue($paragraph->isNumbered());
        self::assertSame(3, $paragraph->numbering()->numId());
        self::assertSame(0, $paragraph->numbering()->ilvl());
    }

    public function test_numbering_definitions_resolve_a_level_through_a_num_indirection(): void
    {
        $abstractNum = new AbstractNum(0, [
            0 => new Level(0, NumberFormat::Decimal, '%1.'),
            1 => new Level(1, NumberFormat::Decimal, '%1.%2'),
        ]);

        $definitions = (new NumberingDefinitions())
            ->withAbstractNum($abstractNum)
            ->withNum(new Num(numId: 3, abstractNumId: 0));

        $level = $definitions->levelFor(numId: 3, ilvl: 1);

        self::assertNotNull($level);
        self::assertSame('%1.%2', $level->lvlText());
        self::assertSame(NumberFormat::Decimal, $level->format());
    }

    public function test_document_holds_mixed_block_content_alongside_its_numbering_table(): void
    {
        $numbering = (new NumberingDefinitions())
            ->withAbstractNum(new AbstractNum(0, [
                0 => new Level(0, NumberFormat::Decimal, '%1.'),
            ]))
            ->withNum(new Num(numId: 3, abstractNumId: 0));

        $numberedParagraph = new Paragraph(
            [new Strong([new Text('Renewal')])],
            numbering: new NumberingRef(3, 0),
        );

        $document = new Document(
            content: [new Heading(1, [new Text('Title')]), $numberedParagraph],
            numbering: $numbering,
        );

        self::assertCount(2, $document->content());
        self::assertInstanceOf(Heading::class, $document->content()[0]);
        self::assertSame($numberedParagraph, $document->content()[1]);
        self::assertSame($numbering, $document->numbering());
    }
}
