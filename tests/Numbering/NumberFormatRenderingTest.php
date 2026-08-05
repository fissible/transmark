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
use Fissible\Transmark\Tests\Support\NumberingFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberFormatRenderingTest extends TestCase
{
    #[DataProvider('decimalValues')]
    public function test_decimal_format_renders_value_as_is(int $value, string $expected): void
    {
        self::assertSame($expected, NumberFormat::Decimal->render($value));
    }

    #[DataProvider('alphaValues')]
    public function test_letter_formats_render_bijective_base26(int $value, string $lower, string $upper): void
    {
        self::assertSame($lower, NumberFormat::LowerLetter->render($value));
        self::assertSame($upper, NumberFormat::UpperLetter->render($value));
    }

    #[DataProvider('romanValues')]
    public function test_roman_formats_render_standard_numerals(int $value, string $lower, string $upper): void
    {
        self::assertSame($lower, NumberFormat::LowerRoman->render($value));
        self::assertSame($upper, NumberFormat::UpperRoman->render($value));
    }

    public function test_lvltext_concatenates_multiple_ancestor_placeholders(): void
    {
        $firstParent = $this->numberedParagraph(10, 0);
        $secondParent = $this->numberedParagraph(10, 0);
        $child = $this->numberedParagraph(10, 1);
        $grandchild = $this->numberedParagraph(10, 2);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1.'),
                1 => new Level(1, NumberFormat::LowerLetter, '%1.(%2)'),
                2 => new Level(2, NumberFormat::UpperRoman, 'Article %1(%2)(%3)'),
            ])],
            nums: [10 => new Num(10, 1)],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$firstParent, $secondParent, $child, $grandchild],
            numbering: $definitions,
        ));

        self::assertSame('Article 2(a)(I)', $labels->labelFor($grandchild));
    }

    public function test_islgl_forces_decimal_on_non_decimal_ancestor_levels(): void
    {
        $parent = $this->numberedParagraph(10, 0);
        $firstChild = $this->numberedParagraph(10, 1);
        $secondChild = $this->numberedParagraph(10, 1);
        $grandchild = $this->numberedParagraph(10, 2);
        $greatGrandchild = $this->numberedParagraph(10, 3);
        $definitions = new NumberingDefinitions(
            abstractNums: [1 => new AbstractNum(1, [
                0 => new Level(0, NumberFormat::Decimal, '%1.'),
                1 => new Level(1, NumberFormat::LowerLetter, '%1.%2.'),
                2 => new Level(2, NumberFormat::LowerRoman, '%1.%2.%3.'),
                3 => new Level(3, NumberFormat::UpperLetter, '%1.%2.%3.%4.', isLegal: true),
            ])],
            nums: [10 => new Num(10, 1)],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$parent, $firstChild, $secondChild, $grandchild, $greatGrandchild],
            numbering: $definitions,
        ));

        self::assertSame('1.2.1.1.', $labels->labelFor($greatGrandchild));
    }

    public function test_bullet_and_none_placeholders_render_as_empty_strings(): void
    {
        $bullet = $this->numberedParagraph(10, 0);
        $none = $this->numberedParagraph(20, 0);
        $definitions = new NumberingDefinitions(
            abstractNums: [
                1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Bullet, '• %1'),
                ]),
                2 => new AbstractNum(2, [
                    0 => new Level(0, NumberFormat::None, 'before%1after'),
                ]),
            ],
            nums: [
                10 => new Num(10, 1),
                20 => new Num(20, 2),
            ],
        );

        $labels = (new NumberingEngine())->resolve(new Document(
            content: [$bullet, $none],
            numbering: $definitions,
        ));

        self::assertSame('• ', $labels->labelFor($bullet));
        self::assertSame('beforeafter', $labels->labelFor($none));
    }

    public function test_legal_outline_fixture_resolves_to_documented_labels(): void
    {
        $fixture = NumberingFixture::load('legal-outline');
        $labels = (new NumberingEngine())->resolve($fixture->document());
        $expected = [
            'Definitions' => '1.',
            'Term of Agreement' => '2.',
            'Initial Term' => '2.1.',
            'Renewal' => '2.2.',
            'Automatic renewal' => '2.2.1.',
            'Written notice' => '2.2.1.1.',
            'Termination' => '3.',
        ];

        foreach ($expected as $text => $expectedLabel) {
            self::assertSame($expectedLabel, $labels->labelFor($fixture->paragraph($text)), $text);
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function decimalValues(): iterable
    {
        foreach (range(1, 30) as $value) {
            yield (string) $value => [$value, (string) $value];
        }
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function alphaValues(): iterable
    {
        $values = [
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j',
            'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't',
            'u', 'v', 'w', 'x', 'y', 'z', 'aa', 'ab', 'ac', 'ad',
        ];

        foreach ($values as $offset => $lower) {
            yield (string) ($offset + 1) => [$offset + 1, $lower, strtoupper($lower)];
        }

        yield '52' => [52, 'az', 'AZ'];
        yield '53' => [53, 'ba', 'BA'];
        yield '702' => [702, 'zz', 'ZZ'];
        yield '703' => [703, 'aaa', 'AAA'];
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function romanValues(): iterable
    {
        $values = [
            'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x',
            'xi', 'xii', 'xiii', 'xiv', 'xv', 'xvi', 'xvii', 'xviii', 'xix', 'xx',
            'xxi', 'xxii', 'xxiii', 'xxiv', 'xxv', 'xxvi', 'xxvii', 'xxviii', 'xxix', 'xxx',
        ];

        foreach ($values as $offset => $lower) {
            yield (string) ($offset + 1) => [$offset + 1, $lower, strtoupper($lower)];
        }

        yield '40' => [40, 'xl', 'XL'];
        yield '49' => [49, 'xlix', 'XLIX'];
        yield '50' => [50, 'l', 'L'];
        yield '90' => [90, 'xc', 'XC'];
        yield '99' => [99, 'xcix', 'XCIX'];
        yield '400' => [400, 'cd', 'CD'];
        yield '944' => [944, 'cmxliv', 'CMXLIV'];
        yield '1994' => [1994, 'mcmxciv', 'MCMXCIV'];
        yield '3999' => [3999, 'mmmcmxcix', 'MMMCMXCIX'];
    }

    private function numberedParagraph(int $numId, int $ilvl): Paragraph
    {
        return new Paragraph(numbering: new NumberingRef($numId, $ilvl));
    }
}
