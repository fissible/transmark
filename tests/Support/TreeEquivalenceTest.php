<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Support;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Text;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class TreeEquivalenceTest extends TestCase
{
    public function test_equivalence_detects_a_dropped_inline_node(): void
    {
        $expected = new Document([new Paragraph([new Strong([new Text('important')])])]);
        $actual = new Document([new Paragraph([new Text('important')])]);

        $this->expectException(AssertionFailedError::class);

        TreeEquivalence::assertEquivalent($expected, $actual);
    }

    public function test_expected_loss_requires_a_documented_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TreeEquivalence::assertExpectedLoss(
            $this->document('before'),
            $this->document('after'),
            '',
            static function (Document $_actual): void {
            },
        );
    }

    public function test_expected_loss_still_fails_when_the_documented_result_regresses(): void
    {
        $this->expectException(AssertionFailedError::class);

        TreeEquivalence::assertExpectedLoss(
            $this->document('before'),
            $this->document('unexpected regression'),
            'The conversion intentionally replaces the original text.',
            static function (Document $actual): void {
                $paragraph = $actual->content()[0];
                self::assertInstanceOf(Paragraph::class, $paragraph);
                self::assertInstanceOf(Text::class, $paragraph->inlines()[0]);
                self::assertSame('documented replacement', $paragraph->inlines()[0]->content());
            },
        );
    }

    private function document(string $text): Document
    {
        return new Document([new Paragraph([new Text($text)])]);
    }
}
