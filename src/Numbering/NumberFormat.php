<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

enum NumberFormat: string
{
    case Decimal = 'decimal';
    case LowerLetter = 'lowerLetter';
    case UpperLetter = 'upperLetter';
    case LowerRoman = 'lowerRoman';
    case UpperRoman = 'upperRoman';
    case Bullet = 'bullet';
    case None = 'none';

    public function render(int $value): string
    {
        return match ($this) {
            self::Decimal => (string) $value,
            self::LowerLetter => strtolower(self::numberToAlpha($value)),
            self::UpperLetter => self::numberToAlpha($value),
            self::LowerRoman => strtolower(self::numberToRoman($value)),
            self::UpperRoman => self::numberToRoman($value),
            self::Bullet, self::None => '',
        };
    }

    private static function numberToAlpha(int $value): string
    {
        $letters = '';

        while ($value > 0) {
            --$value;
            $letters = chr(65 + ($value % 26)).$letters;
            $value = intdiv($value, 26);
        }

        return $letters;
    }

    private static function numberToRoman(int $value): string
    {
        $numerals = [
            1000 => 'M',
            900 => 'CM',
            500 => 'D',
            400 => 'CD',
            100 => 'C',
            90 => 'XC',
            50 => 'L',
            40 => 'XL',
            10 => 'X',
            9 => 'IX',
            5 => 'V',
            4 => 'IV',
            1 => 'I',
        ];
        $result = '';

        foreach ($numerals as $number => $numeral) {
            while ($value >= $number) {
                $result .= $numeral;
                $value -= $number;
            }
        }

        return $result;
    }
}
