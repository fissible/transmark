<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

enum RestartRule
{
    case DefaultImmediateParent;
    case Never;
    case AfterIlvl;
}
