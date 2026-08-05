<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Support;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Numbering\RestartRule;

final class NumberingFixture
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @param array<string, Paragraph> $paragraphs keyed by paragraph text
     */
    private function __construct(
        private readonly Document $document,
        private readonly array $paragraphs,
    ) {
    }

    public static function load(string $name): self
    {
        $fixturePath = dirname(__DIR__).'/fixtures/numbering/'.$name;
        $definitions = self::loadDefinitions($fixturePath.'/numbering.xml');
        [$content, $paragraphs] = self::loadParagraphs($fixturePath.'/document.xml');

        return new self(new Document($content, $definitions), $paragraphs);
    }

    public function document(): Document
    {
        return $this->document;
    }

    public function paragraph(string $text): Paragraph
    {
        return $this->paragraphs[$text]
            ?? throw new \OutOfBoundsException(sprintf('Fixture paragraph not found: %s', $text));
    }

    private static function loadDefinitions(string $path): NumberingDefinitions
    {
        $dom = self::loadXml($path);
        $abstractNums = [];

        foreach ($dom->getElementsByTagNameNS(self::WORD_NAMESPACE, 'abstractNum') as $abstractNumElement) {
            if (!$abstractNumElement instanceof \DOMElement) {
                continue;
            }

            $abstractNumId = (int) $abstractNumElement->getAttributeNS(
                self::WORD_NAMESPACE,
                'abstractNumId',
            );
            $levels = [];

            foreach ($abstractNumElement->getElementsByTagNameNS(self::WORD_NAMESPACE, 'lvl') as $levelElement) {
                if (!$levelElement instanceof \DOMElement) {
                    continue;
                }

                $level = self::loadLevel($levelElement);
                $levels[$level->ilvl()] = $level;
            }

            $abstractNums[$abstractNumId] = new AbstractNum($abstractNumId, $levels);
        }

        $nums = [];
        foreach ($dom->getElementsByTagNameNS(self::WORD_NAMESPACE, 'num') as $numElement) {
            if (!$numElement instanceof \DOMElement) {
                continue;
            }

            $abstractNumIdElement = self::firstElement($numElement, 'abstractNumId');
            if ($abstractNumIdElement === null) {
                throw new \RuntimeException(sprintf('Missing abstractNumId in fixture: %s', $path));
            }

            $numId = (int) $numElement->getAttributeNS(self::WORD_NAMESPACE, 'numId');
            $abstractNumId = (int) $abstractNumIdElement->getAttributeNS(self::WORD_NAMESPACE, 'val');
            $levelOverrides = [];

            foreach ($numElement->getElementsByTagNameNS(self::WORD_NAMESPACE, 'lvlOverride') as $overrideElement) {
                if (!$overrideElement instanceof \DOMElement) {
                    continue;
                }

                $startOverride = self::firstElement($overrideElement, 'startOverride');
                if ($startOverride === null) {
                    continue;
                }

                $ilvl = (int) $overrideElement->getAttributeNS(self::WORD_NAMESPACE, 'ilvl');
                $levelOverrides[$ilvl] = (int) $startOverride->getAttributeNS(self::WORD_NAMESPACE, 'val');
            }

            $nums[$numId] = new Num($numId, $abstractNumId, $levelOverrides);
        }

        return new NumberingDefinitions($abstractNums, $nums);
    }

    private static function loadLevel(\DOMElement $levelElement): Level
    {
        $ilvl = (int) $levelElement->getAttributeNS(self::WORD_NAMESPACE, 'ilvl');
        $start = 1;
        $format = NumberFormat::Decimal;
        $lvlText = '';
        $isLegal = false;
        $restartRule = RestartRule::DefaultImmediateParent;
        $restartAfterIlvl = null;

        foreach ($levelElement->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            match ($child->localName) {
                'start' => $start = (int) $child->getAttributeNS(self::WORD_NAMESPACE, 'val'),
                'numFmt' => $format = NumberFormat::from(
                    $child->getAttributeNS(self::WORD_NAMESPACE, 'val'),
                ),
                'lvlText' => $lvlText = $child->getAttributeNS(self::WORD_NAMESPACE, 'val'),
                'isLgl' => $isLegal = true,
                'lvlRestart' => [$restartRule, $restartAfterIlvl] = self::loadRestartRule($child),
                default => null,
            };
        }

        return new Level(
            ilvl: $ilvl,
            format: $format,
            lvlText: $lvlText,
            start: $start,
            isLegal: $isLegal,
            restartRule: $restartRule,
            restartAfterIlvl: $restartAfterIlvl,
        );
    }

    /**
     * @return array{RestartRule, ?int}
     */
    private static function loadRestartRule(\DOMElement $element): array
    {
        $value = (int) $element->getAttributeNS(self::WORD_NAMESPACE, 'val');

        if ($value === 0) {
            return [RestartRule::Never, null];
        }

        return [RestartRule::AfterIlvl, $value - 1];
    }

    /**
     * @return array{list<Paragraph>, array<string, Paragraph>}
     */
    private static function loadParagraphs(string $path): array
    {
        $dom = self::loadXml($path);
        $content = [];
        $paragraphs = [];

        foreach ($dom->getElementsByTagNameNS(self::WORD_NAMESPACE, 'p') as $paragraphElement) {
            if (!$paragraphElement instanceof \DOMElement) {
                continue;
            }

            $text = '';
            foreach ($paragraphElement->getElementsByTagNameNS(self::WORD_NAMESPACE, 't') as $textElement) {
                $text .= $textElement->textContent;
            }

            $numbering = self::loadNumberingRef($paragraphElement);
            $paragraph = new Paragraph([new Text($text)], numbering: $numbering);
            $content[] = $paragraph;
            $paragraphs[$text] = $paragraph;
        }

        return [$content, $paragraphs];
    }

    private static function loadNumberingRef(\DOMElement $paragraphElement): ?NumberingRef
    {
        $numPr = self::firstElement($paragraphElement, 'numPr');
        if ($numPr === null) {
            return null;
        }

        $ilvlElement = self::firstElement($numPr, 'ilvl');
        $numIdElement = self::firstElement($numPr, 'numId');
        if ($ilvlElement === null || $numIdElement === null) {
            return null;
        }

        return new NumberingRef(
            (int) $numIdElement->getAttributeNS(self::WORD_NAMESPACE, 'val'),
            (int) $ilvlElement->getAttributeNS(self::WORD_NAMESPACE, 'val'),
        );
    }

    private static function firstElement(\DOMElement $parent, string $localName): ?\DOMElement
    {
        $element = $parent->getElementsByTagNameNS(self::WORD_NAMESPACE, $localName)->item(0);

        return $element instanceof \DOMElement ? $element : null;
    }

    private static function loadXml(string $path): \DOMDocument
    {
        $dom = new \DOMDocument();
        if (!$dom->load($path)) {
            throw new \RuntimeException(sprintf('Unable to load numbering fixture XML: %s', $path));
        }

        return $dom;
    }
}
