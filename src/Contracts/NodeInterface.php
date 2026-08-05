<?php

declare(strict_types=1);

namespace Fissible\Transmark\Contracts;

use Fissible\Transmark\Attributes;

interface NodeInterface
{
    public function attributes(): Attributes;
}
