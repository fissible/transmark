<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

/**
 * Word's indirection layer: paragraphs reference a numId, and a
 * numId points at an abstractNumId (+ optional per-level overrides).
 * This lets multiple lists share one abstract definition while
 * restarting or overriding independently.
 */
final class Num
{
    /**
     * @param array<int, int> $levelOverrides ilvl => overridden start value
     */
    public function __construct(
        private readonly int $numId,
        private readonly int $abstractNumId,
        private readonly array $levelOverrides = [],
    ) {
    }

    public function numId(): int
    {
        return $this->numId;
    }

    public function abstractNumId(): int
    {
        return $this->abstractNumId;
    }

    /**
     * @return array<int, int>
     */
    public function levelOverrides(): array
    {
        return $this->levelOverrides;
    }
}
