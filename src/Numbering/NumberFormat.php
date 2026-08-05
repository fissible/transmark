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
}
