<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

/**
 * What a numbered Paragraph carries: a pointer, not a label.
 * "I belong to numbering definition numId=3, at level ilvl=1."
 * The rendered text ("1.1") is computed by NumberingEngine, never stored here.
 */
final class NumberingRef
{
    public function __construct(
        private readonly int $numId,
        private readonly int $ilvl,
    ) {
    }

    public function numId(): int
    {
        return $this->numId;
    }

    public function ilvl(): int
    {
        return $this->ilvl;
    }
}
