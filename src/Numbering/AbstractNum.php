<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

final class AbstractNum
{
    /**
     * @param array<int, Level> $levels keyed by ilvl (0-8)
     */
    public function __construct(
        private readonly int $id,
        private readonly array $levels,
        private readonly ?string $multiLevelType = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function level(int $ilvl): ?Level
    {
        return $this->levels[$ilvl] ?? null;
    }

    /**
     * @return array<int, Level>
     */
    public function levels(): array
    {
        return $this->levels;
    }

    public function multiLevelType(): ?string
    {
        return $this->multiLevelType;
    }
}
