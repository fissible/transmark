<?php

declare(strict_types=1);

namespace Fissible\Transmark\Numbering;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;

/**
 * Classifies the numbered-paragraph shapes that native list syntax can
 * represent. A numId is simple only when every used level is an independent
 * single-placeholder counter (or a literal bullet).
 */
final class NumberingShapeClassifier
{
    /**
     * @return array<int, bool> numId => is simple
     */
    public function classify(Document $document): array
    {
        $usedLevels = [];

        foreach ($document->content() as $block) {
            if (!$block instanceof Paragraph || $block->numbering() === null) {
                continue;
            }

            $numbering = $block->numbering();
            $usedLevels[$numbering->numId()][$numbering->ilvl()] = true;
        }

        $classifications = [];
        foreach ($usedLevels as $numId => $levels) {
            $classifications[$numId] = true;

            foreach (array_keys($levels) as $ilvl) {
                $level = $document->numbering()->levelFor($numId, $ilvl);

                if ($level === null || !$this->isSimpleLevel($level)) {
                    $classifications[$numId] = false;
                    break;
                }
            }
        }

        return $classifications;
    }

    private function isSimpleLevel(Level $level): bool
    {
        if ($level->isLegal() || $level->format() === NumberFormat::None) {
            return false;
        }

        $placeholderCount = preg_match_all('/%([1-9])/', $level->lvlText(), $matches);

        if ($level->format() === NumberFormat::Bullet) {
            return $placeholderCount === 0;
        }

        return $placeholderCount === 1
            && (int) $matches[1][0] === $level->ilvl() + 1;
    }
}
