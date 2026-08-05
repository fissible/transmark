<?php

declare(strict_types=1);

namespace Fissible\Transmark\Contracts;

use Fissible\Transmark\Document;
use Fissible\Transmark\Numbering\NumberingLabelMap;

interface NumberingEngineInterface
{
    /**
     * Walk the document in order and compute a rendered label
     * (e.g. "1.1.3", "(a)") for every numbered paragraph.
     *
     * Labels are derived, never stored on the tree: a read -> write
     * round-trip preserves the numbering definitions rather than
     * this computed output, which is what keeps it convergent.
     */
    public function resolve(Document $document): NumberingLabelMap;
}
