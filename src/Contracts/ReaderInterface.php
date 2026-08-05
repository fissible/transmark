<?php

declare(strict_types=1);

namespace Fissible\Transmark\Contracts;

use Fissible\Transmark\Document;

interface ReaderInterface
{
    /**
     * Parse raw source content into the canonical document tree.
     */
    public function read(string $content): Document;
}
