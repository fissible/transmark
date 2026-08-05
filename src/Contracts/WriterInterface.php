<?php

declare(strict_types=1);

namespace Fissible\Transmark\Contracts;

use Fissible\Transmark\Document;

interface WriterInterface
{
    /**
     * Serialize the canonical document tree into this writer's target format.
     */
    public function write(Document $document): string;
}
