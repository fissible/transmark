<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Numbering;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingEngine;
use Fissible\Transmark\Numbering\NumberingRef;
use PHPUnit\Framework\TestCase;

final class NumberingEngineTest extends TestCase
{
    public function test_sibling_paragraphs_increment_sequentially(): void
    {
        $first = $this->numberedParagraph(numId: 1, ilvl: 0);
        $second = $this->numberedParagraph(numId: 1, ilvl: 0);
        $third = $this->numberedParagraph(numId: 1, ilvl: 0);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$first, $second, $third],
            numbering: $this->numberingDefinitions(),
        ));

        self::assertSame('1', $labels->labelFor($first));
        self::assertSame('2', $labels->labelFor($second));
        self::assertSame('3', $labels->labelFor($third));
    }

    public function test_descending_a_level_starts_its_counter_at_level_start(): void
    {
        $parent = $this->numberedParagraph(numId: 1, ilvl: 0);
        $child = $this->numberedParagraph(numId: 1, ilvl: 1);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$parent, $child],
            numbering: $this->numberingDefinitions(levelOneStart: 4),
        ));

        self::assertSame('1', $labels->labelFor($parent));
        self::assertSame('1.4', $labels->labelFor($child));
    }

    public function test_ascending_and_incrementing_resets_deeper_counters(): void
    {
        $firstParent = $this->numberedParagraph(numId: 1, ilvl: 0);
        $firstChild = $this->numberedParagraph(numId: 1, ilvl: 1);
        $grandchild = $this->numberedParagraph(numId: 1, ilvl: 2);
        $greatGrandchild = $this->numberedParagraph(numId: 1, ilvl: 3);
        $secondParent = $this->numberedParagraph(numId: 1, ilvl: 0);
        $restartedDeepLevel = $this->numberedParagraph(numId: 1, ilvl: 3);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [
                $firstParent,
                $firstChild,
                $grandchild,
                $greatGrandchild,
                $secondParent,
                $restartedDeepLevel,
            ],
            numbering: $this->numberingDefinitions(),
        ));

        self::assertSame('1.1.1.1', $labels->labelFor($greatGrandchild));
        self::assertSame('2', $labels->labelFor($secondParent));
        // The reset level-1/level-2 counters render as their start values,
        // the way Word fills in intermediates of a deep-level label.
        self::assertSame('2.1.1.1', $labels->labelFor($restartedDeepLevel));
    }

    public function test_two_num_ids_sharing_one_abstract_num_do_not_share_counters(): void
    {
        $firstListItem = $this->numberedParagraph(numId: 1, ilvl: 0);
        $secondListItem = $this->numberedParagraph(numId: 1, ilvl: 0);
        $otherListItem = $this->numberedParagraph(numId: 2, ilvl: 0);
        $thirdListItem = $this->numberedParagraph(numId: 1, ilvl: 0);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$firstListItem, $secondListItem, $otherListItem, $thirdListItem],
            numbering: $this->numberingDefinitions(numIds: [1, 2]),
        ));

        self::assertSame('1', $labels->labelFor($otherListItem));
        self::assertSame('3', $labels->labelFor($thirdListItem));
    }

    public function test_unnumbered_paragraphs_are_absent_from_the_label_map(): void
    {
        $unnumbered = new Paragraph();

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$unnumbered],
            numbering: $this->numberingDefinitions(),
        ));

        self::assertNull($labels->labelFor($unnumbered));
    }

    public function test_numbered_paragraphs_are_resolved_recursively_in_document_order(): void
    {
        $inListItem = $this->numberedParagraph(numId: 1, ilvl: 0);
        $inTableHeader = $this->numberedParagraph(numId: 1, ilvl: 0);
        $inTableBody = $this->numberedParagraph(numId: 1, ilvl: 0);

        $document = new Document(
            content: [
                new ListNode(ListNode::TYPE_ORDERED, [
                    new ListItem([
                        $inListItem,
                        new BlockQuote([
                            new Table(
                                rows: [new TableRow([new TableCell([$inTableBody])])],
                                header: new TableRow([new TableCell([$inTableHeader])]),
                            ),
                        ]),
                    ]),
                ]),
            ],
            numbering: $this->numberingDefinitions(),
        );

        $labels = (new NumberingEngine())->resolve($document);

        self::assertSame('1', $labels->labelFor($inListItem));
        self::assertSame('2', $labels->labelFor($inTableHeader));
        self::assertSame('3', $labels->labelFor($inTableBody));
    }

    public function test_a_deep_level_paragraph_renders_missing_ancestors_as_their_start_values(): void
    {
        $paragraph = $this->numberedParagraph(numId: 1, ilvl: 2);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$paragraph],
            numbering: $this->numberingDefinitions(),
        ));

        // Word fills in never-started ancestors with their start value:
        // "%1.%2.%3" for a first paragraph at level 2 renders "1.1.1",
        // not the engine's previous "..1".
        self::assertSame('1.1.1', $labels->labelFor($paragraph));
    }

    public function test_missing_ancestor_renders_the_num_level_start_override(): void
    {
        $definitions = $this->numberingDefinitions()
            ->withNum(new Num(1, abstractNumId: 10, levelOverrides: [0 => 5]));
        $paragraph = $this->numberedParagraph(numId: 1, ilvl: 1);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$paragraph],
            numbering: $definitions,
        ));

        self::assertSame('5.1', $labels->labelFor($paragraph));
    }

    /**
     * @param int[] $numIds
     */
    private function numberingDefinitions(int $levelOneStart = 1, array $numIds = [1]): NumberingDefinitions
    {
        $definitions = (new NumberingDefinitions())->withAbstractNum(new AbstractNum(10, [
            0 => new Level(0, NumberFormat::Decimal, '%1'),
            1 => new Level(1, NumberFormat::Decimal, '%1.%2', start: $levelOneStart),
            2 => new Level(2, NumberFormat::Decimal, '%1.%2.%3'),
            3 => new Level(3, NumberFormat::Decimal, '%1.%2.%3.%4'),
        ]));

        foreach ($numIds as $numId) {
            $definitions = $definitions->withNum(new Num($numId, abstractNumId: 10));
        }

        return $definitions;
    }

    private function numberedParagraph(int $numId, int $ilvl): Paragraph
    {
        return new Paragraph(numbering: new NumberingRef($numId, $ilvl));
    }
}
