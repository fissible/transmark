<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

/**
 * One level (ilvl 0-8) of an AbstractNum: how its counter is
 * formatted and assembled into a label, e.g. lvlText "%1.%2" plus
 * two decimal levels renders as "1.1".
 */
final class Level
{
    public function __construct(
        private readonly int $ilvl,
        private readonly NumberFormat $format,
        private readonly string $lvlText,
        private readonly int $start = 1,
        private readonly bool $isLegal = false,
        private readonly ?int $restartAfterIlvl = null,
    ) {
    }

    public function ilvl(): int
    {
        return $this->ilvl;
    }

    public function format(): NumberFormat
    {
        return $this->format;
    }

    public function lvlText(): string
    {
        return $this->lvlText;
    }

    public function start(): int
    {
        return $this->start;
    }

    public function isLegal(): bool
    {
        return $this->isLegal;
    }

    public function restartAfterIlvl(): ?int
    {
        return $this->restartAfterIlvl;
    }
}
