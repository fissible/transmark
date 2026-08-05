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
        private readonly RestartRule $restartRule = RestartRule::DefaultImmediateParent,
        private readonly ?int $restartAfterIlvl = null,
    ) {
        if ($this->restartRule === RestartRule::AfterIlvl) {
            if ($this->restartAfterIlvl === null) {
                throw new \InvalidArgumentException('An AfterIlvl restart rule requires an ancestor ilvl.');
            }

            if ($this->restartAfterIlvl < 0 || $this->restartAfterIlvl >= $this->ilvl) {
                throw new \InvalidArgumentException(sprintf(
                    'Restart ilvl must be an ancestor of level %d, got %d.',
                    $this->ilvl,
                    $this->restartAfterIlvl,
                ));
            }
        } elseif ($this->restartAfterIlvl !== null) {
            throw new \InvalidArgumentException('Only an AfterIlvl restart rule may specify an ancestor ilvl.');
        }
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

    public function restartRule(): RestartRule
    {
        return $this->restartRule;
    }

    public function restartAfterIlvl(): ?int
    {
        return $this->restartAfterIlvl;
    }

    public function restartsAfter(int $incrementedIlvl): bool
    {
        if ($incrementedIlvl >= $this->ilvl) {
            return false;
        }

        return match ($this->restartRule) {
            // Incrementing any ancestor invalidates the default
            // immediate-parent chain transitively.
            RestartRule::DefaultImmediateParent => true,
            RestartRule::Never => false,
            RestartRule::AfterIlvl => $this->restartAfterIlvl === $incrementedIlvl,
        };
    }
}
