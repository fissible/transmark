<?php

declare(strict_types=1);

namespace Fissible\Transmark\Format;

enum Format: string
{
    case Docx = 'docx';
    case Unknown = 'unknown';
}
