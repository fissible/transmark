<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

/**
 * Mirrors word/numbering.xml: abstractNums hold the level formats,
 * nums map a paragraph-facing numId onto one of them. This table
 * lives on Document; paragraphs only ever hold a NumberingRef
 * pointer into it.
 */
final class NumberingDefinitions
{
    /**
     * @param array<int, AbstractNum> $abstractNums keyed by abstractNumId
     * @param array<int, Num> $nums keyed by numId
     */
    public function __construct(
        private readonly array $abstractNums = [],
        private readonly array $nums = [],
    ) {
    }

    public function abstractNum(int $abstractNumId): ?AbstractNum
    {
        return $this->abstractNums[$abstractNumId] ?? null;
    }

    public function num(int $numId): ?Num
    {
        return $this->nums[$numId] ?? null;
    }

    /**
     * @return array<int, AbstractNum>
     */
    public function abstractNums(): array
    {
        return $this->abstractNums;
    }

    /**
     * @return array<int, Num>
     */
    public function nums(): array
    {
        return $this->nums;
    }

    public function levelFor(int $numId, int $ilvl): ?Level
    {
        $num = $this->num($numId);

        if ($num === null) {
            return null;
        }

        return $this->abstractNum($num->abstractNumId())?->level($ilvl);
    }

    public function withAbstractNum(AbstractNum $abstractNum): self
    {
        return new self(
            array_replace($this->abstractNums, [$abstractNum->id() => $abstractNum]),
            $this->nums,
        );
    }

    public function withNum(Num $num): self
    {
        return new self(
            $this->abstractNums,
            array_replace($this->nums, [$num->numId() => $num]),
        );
    }
}
