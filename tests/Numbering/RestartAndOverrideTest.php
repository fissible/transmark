<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Numbering;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingEngine;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Numbering\RestartRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestartAndOverrideTest extends TestCase
{
    public function test_default_restart_resets_on_immediate_parent_increment(): void
    {
        $childLevel = new Level(1, NumberFormat::Decimal, '%2');
        $parent = $this->numberedParagraph(10, 0);
        $firstChild = $this->numberedParagraph(10, 1);
        $secondChild = $this->numberedParagraph(10, 1);
        $nextParent = $this->numberedParagraph(10, 0);
        $restartedChild = $this->numberedParagraph(10, 1);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$parent, $firstChild, $secondChild, $nextParent, $restartedChild],
            numbering: $this->definitions([
                0 => new Level(0, NumberFormat::Decimal, '%1'),
                1 => $childLevel,
            ]),
        ));

        self::assertSame(RestartRule::DefaultImmediateParent, $childLevel->restartRule());
        self::assertSame('2', $labels->labelFor($secondChild));
        self::assertSame('1', $labels->labelFor($restartedChild));
    }

    #[DataProvider('invalidRestartRules')]
    public function test_restart_rules_reject_invalid_ancestor_targets(
        RestartRule $restartRule,
        ?int $restartAfterIlvl,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new Level(
            2,
            NumberFormat::Decimal,
            '%3',
            restartRule: $restartRule,
            restartAfterIlvl: $restartAfterIlvl,
        );
    }

    public function test_explicit_restart_ilvl_skips_intermediate_ancestors(): void
    {
        $restartLevel = new Level(
            2,
            NumberFormat::Decimal,
            '%3',
            restartRule: RestartRule::AfterIlvl,
            restartAfterIlvl: 0,
        );
        $parent = $this->numberedParagraph(10, 0);
        $firstChild = $this->numberedParagraph(10, 1);
        $firstGrandchild = $this->numberedParagraph(10, 2);
        $secondChild = $this->numberedParagraph(10, 1);
        $secondGrandchild = $this->numberedParagraph(10, 2);
        $nextParent = $this->numberedParagraph(10, 0);
        $nextChild = $this->numberedParagraph(10, 1);
        $restartedGrandchild = $this->numberedParagraph(10, 2);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [
                $parent,
                $firstChild,
                $firstGrandchild,
                $secondChild,
                $secondGrandchild,
                $nextParent,
                $nextChild,
                $restartedGrandchild,
            ],
            numbering: $this->definitions([
                0 => new Level(0, NumberFormat::Decimal, '%1'),
                1 => new Level(1, NumberFormat::Decimal, '%2'),
                2 => $restartLevel,
            ]),
        ));

        self::assertSame(RestartRule::AfterIlvl, $restartLevel->restartRule());
        self::assertSame(0, $restartLevel->restartAfterIlvl());
        self::assertSame('1', $labels->labelFor($firstGrandchild));
        self::assertSame('2', $labels->labelFor($secondGrandchild));
        self::assertSame('1', $labels->labelFor($restartedGrandchild));
    }

    public function test_never_restart_ignores_all_ancestor_increments(): void
    {
        $neverRestartLevel = new Level(
            2,
            NumberFormat::Decimal,
            '%3',
            restartRule: RestartRule::Never,
        );
        $parent = $this->numberedParagraph(10, 0);
        $firstChild = $this->numberedParagraph(10, 1);
        $firstGrandchild = $this->numberedParagraph(10, 2);
        $secondChild = $this->numberedParagraph(10, 1);
        $secondGrandchild = $this->numberedParagraph(10, 2);
        $nextParent = $this->numberedParagraph(10, 0);
        $nextChild = $this->numberedParagraph(10, 1);
        $thirdGrandchild = $this->numberedParagraph(10, 2);

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [
                $parent,
                $firstChild,
                $firstGrandchild,
                $secondChild,
                $secondGrandchild,
                $nextParent,
                $nextChild,
                $thirdGrandchild,
            ],
            numbering: $this->definitions([
                0 => new Level(0, NumberFormat::Decimal, '%1'),
                1 => new Level(1, NumberFormat::Decimal, '%2'),
                2 => $neverRestartLevel,
            ]),
        ));

        self::assertSame(RestartRule::Never, $neverRestartLevel->restartRule());
        self::assertNull($neverRestartLevel->restartAfterIlvl());
        self::assertSame('1', $labels->labelFor($firstGrandchild));
        self::assertSame('2', $labels->labelFor($secondGrandchild));
        self::assertSame('3', $labels->labelFor($thirdGrandchild));
    }

    public function test_num_level_start_override_replaces_abstract_level_start(): void
    {
        $parent = $this->numberedParagraph(10, 0);
        $first = $this->numberedParagraph(10, 1);
        $second = $this->numberedParagraph(10, 1);
        $nextParent = $this->numberedParagraph(10, 0);
        $restarted = $this->numberedParagraph(10, 1);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1'),
                1 => new Level(1, NumberFormat::Decimal, '%2', start: 2),
            ])],
            nums: [10 => new Num(10, 1, levelOverrides: [1 => 5])],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            [$parent, $first, $second, $nextParent, $restarted],
            $definitions,
        ));

        self::assertSame('5', $labels->labelFor($first));
        self::assertSame('6', $labels->labelFor($second));
        self::assertSame('5', $labels->labelFor($restarted));
    }

    public function test_two_nums_sharing_one_abstract_num_restart_independently(): void
    {
        $firstA = $this->numberedParagraph(10, 0);
        $secondA = $this->numberedParagraph(10, 0);
        $firstB = $this->numberedParagraph(20, 0);
        $thirdA = $this->numberedParagraph(10, 0);
        $secondB = $this->numberedParagraph(20, 0);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1'),
            ])],
            nums: [
                10 => new Num(10, 1, levelOverrides: [0 => 5]),
                20 => new Num(20, 1, levelOverrides: [0 => 9]),
            ],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            [$firstA, $secondA, $firstB, $thirdA, $secondB],
            $definitions,
        ));

        self::assertSame('5', $labels->labelFor($firstA));
        self::assertSame('6', $labels->labelFor($secondA));
        self::assertSame('9', $labels->labelFor($firstB));
        self::assertSame('7', $labels->labelFor($thirdA));
        self::assertSame('10', $labels->labelFor($secondB));
    }

    /**
     * @param array<int, Level> $levels
     */
    private function definitions(array $levels): NumberingDefinitions
    {
        return new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, $levels)],
            nums: [10 => new Num(10, 1)],
        );
    }

    private function numberedParagraph(int $numId, int $ilvl): Paragraph
    {
        return new Paragraph(numbering: new NumberingRef($numId, $ilvl));
    }

    /**
     * @return iterable<string, array{RestartRule, ?int}>
     */
    public static function invalidRestartRules(): iterable
    {
        yield 'explicit rule without target' => [RestartRule::AfterIlvl, null];
        yield 'explicit rule targeting itself' => [RestartRule::AfterIlvl, 2];
        yield 'never rule with target' => [RestartRule::Never, 0];
    }
}
