<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Numbering;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Numbering\NumberingShapeClassifier;
use PHPUnit\Framework\TestCase;

final class NumberingShapeClassifierTest extends TestCase
{
    public function test_a_single_placeholder_level_at_the_top_level_is_simple(): void
    {
        $document = new Document(
            content: [new Paragraph([new Text('Item')], numbering: new NumberingRef(10, 0))],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [0 => new Level(0, NumberFormat::Decimal, '%1.')])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame([10 => true], (new NumberingShapeClassifier())->classify($document));
    }

    public function test_a_multi_placeholder_level_at_the_top_level_is_not_simple(): void
    {
        $document = new Document(
            content: [
                new Paragraph([new Text('Parent')], numbering: new NumberingRef(10, 0)),
                new Paragraph([new Text('Child')], numbering: new NumberingRef(10, 1)),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    1 => new Level(1, NumberFormat::Decimal, '%1.%2.'),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame([10 => false], (new NumberingShapeClassifier())->classify($document));
    }

    public function test_a_single_placeholder_level_used_only_inside_a_table_cell_is_still_simple(): void
    {
        // Regression: a numId whose only usage is nested inside a table
        // cell must be found and classified the same as if it were at the
        // top level, not silently absent from $usedLevels.
        $document = new Document(
            content: [
                new Table(rows: [
                    new TableRow([new TableCell([
                        new Paragraph([new Text('Item')], numbering: new NumberingRef(10, 0)),
                    ])]),
                ]),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [0 => new Level(0, NumberFormat::Decimal, '%1.')])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame([10 => true], (new NumberingShapeClassifier())->classify($document));
    }

    public function test_a_multi_placeholder_level_used_only_inside_a_table_cell_is_not_simple(): void
    {
        $document = new Document(
            content: [
                new Table(rows: [
                    new TableRow([new TableCell([
                        new Paragraph([new Text('Parent')], numbering: new NumberingRef(10, 0)),
                        new Paragraph([new Text('Child')], numbering: new NumberingRef(10, 1)),
                    ])]),
                ]),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    1 => new Level(1, NumberFormat::Decimal, '%1.%2.'),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame([10 => false], (new NumberingShapeClassifier())->classify($document));
    }
}
