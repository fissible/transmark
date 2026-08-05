<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Numbering;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingEngine;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Numbering\RestartRule;
use Fissible\Transmark\Tests\Support\NumberingFixture;
use PHPUnit\Framework\TestCase;

final class NumberingEngineFixtureTest extends TestCase
{
    public function test_legal_outline_fixture_resolves_every_documented_label(): void
    {
        $this->assertFixtureLabels('legal-outline', [
            'Definitions' => '1.',
            'Term of Agreement' => '2.',
            'Initial Term' => '2.1.',
            'Renewal' => '2.2.',
            'Automatic renewal' => '2.2.1.',
            'Written notice' => '2.2.1.1.',
            'Termination' => '3.',
        ]);
    }

    public function test_simple_nested_list_fixture_resolves_independent_num_ids(): void
    {
        $this->assertFixtureLabels('simple-nested-lists', [
            'Term of Agreement' => '1.',
            'Initial Term' => '1.',
            'Renewal' => '2.',
            'Automatic renewal' => '(a)',
            'Notice of non-renewal' => '(b)',
            'Written notice' => '(i)',
            'Delivery method' => '(ii)',
            'Termination' => '2.',
        ]);
    }

    public function test_explicit_restart_level_skips_intermediate_ancestor_increments(): void
    {
        $parent = $this->numberedParagraph(10, 0);
        $firstChild = $this->numberedParagraph(10, 1);
        $firstGrandchild = $this->numberedParagraph(10, 2);
        $secondChild = $this->numberedParagraph(10, 1);
        $secondGrandchild = $this->numberedParagraph(10, 2);
        $nextParent = $this->numberedParagraph(10, 0);
        $nextChild = $this->numberedParagraph(10, 1);
        $restartedGrandchild = $this->numberedParagraph(10, 2);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1'),
                1 => new Level(1, NumberFormat::Decimal, '%2'),
                2 => new Level(
                    2,
                    NumberFormat::Decimal,
                    '%3',
                    restartRule: RestartRule::AfterIlvl,
                    restartAfterIlvl: 0,
                ),
            ])],
            nums: [10 => new Num(10, 1)],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            [
                $parent,
                $firstChild,
                $firstGrandchild,
                $secondChild,
                $secondGrandchild,
                $nextParent,
                $nextChild,
                $restartedGrandchild,
            ],
            $definitions,
        ));

        self::assertSame('1', $labels->labelFor($firstGrandchild));
        self::assertSame('2', $labels->labelFor($secondGrandchild));
        self::assertSame('1', $labels->labelFor($restartedGrandchild));
    }

    public function test_unnumbered_content_does_not_perturb_numbered_counters(): void
    {
        $plainBefore = new Paragraph();
        $firstNumbered = $this->numberedParagraph(10, 0);
        $plainBetween = new Paragraph();
        $secondNumbered = $this->numberedParagraph(10, 0);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1.'),
            ])],
            nums: [10 => new Num(10, 1)],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            [$plainBefore, $firstNumbered, $plainBetween, $secondNumbered],
            $definitions,
        ));

        self::assertNull($labels->labelFor($plainBefore));
        self::assertSame('1.', $labels->labelFor($firstNumbered));
        self::assertNull($labels->labelFor($plainBetween));
        self::assertSame('2.', $labels->labelFor($secondNumbered));
    }

    public function test_numbered_paragraph_inside_block_quote_is_resolved(): void
    {
        $nested = $this->numberedParagraph(10, 0);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1.'),
            ])],
            nums: [10 => new Num(10, 1)],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            [new BlockQuote([$nested])],
            $definitions,
        ));

        self::assertSame('1.', $labels->labelFor($nested));
    }

    /**
     * @param array<string, string> $expected
     */
    private function assertFixtureLabels(string $fixtureName, array $expected): void
    {
        $fixture = NumberingFixture::load($fixtureName);
        $labels = (new NumberingEngine())->resolve($fixture->document());

        foreach ($expected as $text => $expectedLabel) {
            self::assertSame($expectedLabel, $labels->labelFor($fixture->paragraph($text)), $text);
        }
    }

    private function numberedParagraph(int $numId, int $ilvl): Paragraph
    {
        return new Paragraph(numbering: new NumberingRef($numId, $ilvl));
    }
}
