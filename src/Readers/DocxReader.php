<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Numbering\RestartRule;
use Fissible\Transmark\Ooxml\Exception\InvalidPackageException;
use Fissible\Transmark\Ooxml\OoxmlPackage;

/**
 * Reads the paragraph/run core of word/document.xml.
 *
 * Quote and horizontal-rule nodes use Word style/border heuristics. Run
 * wrappers nest deterministically as Strong > Emphasis > Underline > Strike
 * > vertical alignment > content.
 */
final class DocxReader implements ReaderInterface
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function read(string $content): Document
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-docx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file for the DOCX package.');
        }

        $package = null;

        try {
            $bytesWritten = file_put_contents($path, $content);
            if ($bytesWritten !== strlen($content)) {
                throw new \RuntimeException('Unable to write the DOCX package to a temporary file.');
            }

            $package = OoxmlPackage::open($path);
            $documentXml = $package->part('word/document.xml');

            if ($documentXml === null) {
                throw new InvalidPackageException('The DOCX package does not contain word/document.xml.');
            }

            return $this->parseDocument(
                $documentXml,
                $this->parseNumbering($package->part('word/numbering.xml')),
            );
        } finally {
            $package?->close();

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function parseDocument(
        \DOMDocument $documentXml,
        NumberingDefinitions $numbering,
    ): Document {
        $body = $documentXml->getElementsByTagNameNS(self::WORD_NAMESPACE, 'body')->item(0);
        if (!$body instanceof \DOMElement) {
            return new Document(numbering: $numbering);
        }

        return new Document($this->parseBodyChildren($body), $numbering);
    }

    /**
     * @return BlockInterface[]
     */
    private function parseBodyChildren(\DOMElement $container): array
    {
        $content = [];

        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 'p') {
                $content[] = $this->parseParagraph($child);
            } elseif ($child->localName === 'tbl') {
                $content[] = $this->parseTable($child);
            }
        }

        return $content;
    }

    /**
     * Only the first w:tblHeader-marked row becomes Table::header(); any
     * further header-marked rows fall through to rows() rather than being
     * dropped, mirroring HtmlReader's/PdfReader's "only the first heading
     * row" convention elsewhere in this project.
     */
    private function parseTable(\DOMElement $tbl): Table
    {
        $header = null;
        $rows = [];

        foreach ($tbl->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->namespaceURI !== self::WORD_NAMESPACE
                || $child->localName !== 'tr'
            ) {
                continue;
            }

            $row = $this->parseTableRow($child);

            if ($header === null && $this->isHeaderRow($child)) {
                $header = $row;
            } else {
                $rows[] = $row;
            }
        }

        return new Table($rows, $header);
    }

    private function isHeaderRow(\DOMElement $tr): bool
    {
        $properties = $this->directChild($tr, 'trPr');

        return $this->directChild($properties, 'tblHeader') !== null;
    }

    private function parseTableRow(\DOMElement $tr): TableRow
    {
        $cells = [];

        foreach ($tr->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->namespaceURI !== self::WORD_NAMESPACE
                || $child->localName !== 'tc'
            ) {
                continue;
            }

            $cells[] = $this->parseTableCell($child);
        }

        return new TableRow($cells);
    }

    /**
     * Only w:gridSpan (horizontal merge) is reconstructed as colspan.
     * w:vMerge (vertical merge) is not reconstructed as rowspan - DocxWriter
     * itself already throws on rowspan !== 1 (it doesn't support writing
     * vMerge either), so a continuation cell just reads as its own
     * ordinary, usually-empty cell rather than being folded into the
     * originating cell's rowspan. No content is lost; the merge itself
     * doesn't round-trip.
     */
    private function parseTableCell(\DOMElement $tc): TableCell
    {
        $properties = $this->directChild($tc, 'tcPr');
        $gridSpan = $this->attributeValue($this->directChild($properties, 'gridSpan'), 'val');
        $colspan = $gridSpan === null ? 1 : max(1, (int) $gridSpan);

        return new TableCell($this->parseBodyChildren($tc), $colspan);
    }

    private function parseNumbering(?\DOMDocument $numberingXml): NumberingDefinitions
    {
        if ($numberingXml === null) {
            return new NumberingDefinitions();
        }

        $abstractNums = [];
        foreach ($numberingXml->getElementsByTagNameNS(self::WORD_NAMESPACE, 'abstractNum') as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }

            $abstractNum = $this->parseAbstractNum($element);
            if ($abstractNum !== null) {
                $abstractNums[$abstractNum->id()] = $abstractNum;
            }
        }

        $nums = [];
        foreach ($numberingXml->getElementsByTagNameNS(self::WORD_NAMESPACE, 'num') as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }

            $num = $this->parseNum($element);
            if ($num !== null) {
                $nums[$num->numId()] = $num;
            }
        }

        return new NumberingDefinitions($abstractNums, $nums);
    }

    private function parseAbstractNum(\DOMElement $element): ?AbstractNum
    {
        $id = $this->attributeValue($element, 'abstractNumId');
        if ($id === null) {
            return null;
        }

        $levels = [];
        foreach ($element->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->namespaceURI !== self::WORD_NAMESPACE
                || $child->localName !== 'lvl'
            ) {
                continue;
            }

            $level = $this->parseLevel($child);
            if ($level !== null) {
                $levels[$level->ilvl()] = $level;
            }
        }

        return new AbstractNum(
            id: (int) $id,
            levels: $levels,
            multiLevelType: $this->attributeValue(
                $this->directChild($element, 'multiLevelType'),
                'val',
            ),
        );
    }

    private function parseLevel(\DOMElement $element): ?Level
    {
        $ilvl = $this->attributeValue($element, 'ilvl');
        if ($ilvl === null) {
            return null;
        }

        $start = 1;
        $format = NumberFormat::Decimal;
        $lvlText = '';
        $isLegal = false;
        $restartRule = RestartRule::DefaultImmediateParent;
        $restartAfterIlvl = null;

        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            $value = $this->attributeValue($child, 'val');

            if ($child->localName === 'start' && $value !== null) {
                $start = (int) $value;
            } elseif ($child->localName === 'numFmt' && $value !== null) {
                $format = NumberFormat::from($value);
            } elseif ($child->localName === 'lvlText' && $value !== null) {
                $lvlText = $value;
            } elseif ($child->localName === 'isLgl') {
                $isLegal = $this->isEnabled($child);
            } elseif ($child->localName === 'lvlRestart' && $value !== null) {
                [$restartRule, $restartAfterIlvl] = $this->parseRestartRule((int) $value);
            }
        }

        return new Level(
            ilvl: (int) $ilvl,
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
    private function parseRestartRule(int $value): array
    {
        if ($value === 0) {
            return [RestartRule::Never, null];
        }

        return [RestartRule::AfterIlvl, $value - 1];
    }

    private function parseNum(\DOMElement $element): ?Num
    {
        $numId = $this->attributeValue($element, 'numId');
        $abstractNumId = $this->attributeValue(
            $this->directChild($element, 'abstractNumId'),
            'val',
        );

        if ($numId === null || $abstractNumId === null) {
            return null;
        }

        $levelOverrides = [];
        foreach ($element->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->namespaceURI !== self::WORD_NAMESPACE
                || $child->localName !== 'lvlOverride'
            ) {
                continue;
            }

            $ilvl = $this->attributeValue($child, 'ilvl');
            $startOverride = $this->attributeValue(
                $this->directChild($child, 'startOverride'),
                'val',
            );

            if ($ilvl !== null && $startOverride !== null) {
                $levelOverrides[(int) $ilvl] = (int) $startOverride;
            }
        }

        return new Num((int) $numId, (int) $abstractNumId, $levelOverrides);
    }

    private function parseParagraph(\DOMElement $paragraphElement): BlockInterface
    {
        $properties = $this->directChild($paragraphElement, 'pPr');
        $inlines = $this->parseInlineContainer($paragraphElement);

        if ($this->isHorizontalRule($paragraphElement, $properties)) {
            return new HorizontalRule();
        }

        $styleName = $this->attributeValue($this->directChild($properties, 'pStyle'), 'val');

        if ($styleName !== null && preg_match('/^Heading([1-6])$/', $styleName, $matches) === 1) {
            return new Heading((int) $matches[1], $inlines);
        }

        $numbering = $this->parseNumberingRef($properties);
        $paragraph = new Paragraph($inlines, styleName: $styleName, numbering: $numbering);

        if ($styleName === 'Quote' || $styleName === 'IntenseQuote') {
            return new BlockQuote([$paragraph]);
        }

        return $paragraph;
    }

    private function isHorizontalRule(\DOMElement $paragraph, ?\DOMElement $properties): bool
    {
        $borders = $this->directChild($properties, 'pBdr');
        $bottom = $this->directChild($borders, 'bottom');

        return $bottom !== null
            && $this->isEnabled($bottom)
            && trim($paragraph->textContent) === '';
    }

    private function parseNumberingRef(?\DOMElement $properties): ?NumberingRef
    {
        $numberingProperties = $this->directChild($properties, 'numPr');
        $numId = $this->attributeValue($this->directChild($numberingProperties, 'numId'), 'val');
        $ilvl = $this->attributeValue($this->directChild($numberingProperties, 'ilvl'), 'val');

        if ($numId === null || $ilvl === null) {
            return null;
        }

        return new NumberingRef((int) $numId, (int) $ilvl);
    }

    /**
     * @return InlineInterface[]
     */
    private function parseInlineContainer(\DOMElement $container): array
    {
        $inlines = [];

        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 'r') {
                foreach ($this->parseRun($child) as $inline) {
                    $inlines[] = $inline;
                }

                continue;
            }

            if ($child->localName === 'pPr' || $child->localName === 'rPr') {
                continue;
            }

            foreach ($this->parseInlineContainer($child) as $inline) {
                $inlines[] = $inline;
            }
        }

        return $inlines;
    }

    /**
     * @return InlineInterface[]
     */
    private function parseRun(\DOMElement $run): array
    {
        $children = [];

        foreach ($run->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 't') {
                $children[] = new Text($child->textContent);
            } elseif ($child->localName === 'br') {
                $children[] = new LineBreak();
            }
        }

        if ($children === []) {
            return [];
        }

        $properties = $this->directChild($run, 'rPr');
        $wrapped = $children;
        $verticalAlignment = $this->attributeValue(
            $this->directChild($properties, 'vertAlign'),
            'val',
        );

        if ($verticalAlignment === 'superscript') {
            $wrapped = [new Superscript($wrapped)];
        } elseif ($verticalAlignment === 'subscript') {
            $wrapped = [new Subscript($wrapped)];
        }

        if ($this->isEnabled($this->directChild($properties, 'strike'))) {
            $wrapped = [new Strike($wrapped)];
        }

        if ($this->isEnabled($this->directChild($properties, 'u'))) {
            $wrapped = [new Underline($wrapped)];
        }

        if ($this->isEnabled($this->directChild($properties, 'i'))) {
            $wrapped = [new Emphasis($wrapped)];
        }

        if ($this->isEnabled($this->directChild($properties, 'b'))) {
            $wrapped = [new Strong($wrapped)];
        }

        return $wrapped;
    }

    private function isEnabled(?\DOMElement $property): bool
    {
        if ($property === null) {
            return false;
        }

        $value = strtolower($this->attributeValue($property, 'val') ?? '');

        return !in_array($value, ['0', 'false', 'off', 'none', 'nil'], true);
    }

    private function attributeValue(?\DOMElement $element, string $name): ?string
    {
        if ($element === null) {
            return null;
        }

        $attribute = $element->getAttributeNodeNS(self::WORD_NAMESPACE, $name);
        if ($attribute instanceof \DOMAttr) {
            return $attribute->value;
        }

        $attribute = $element->getAttributeNode($name);

        return $attribute instanceof \DOMAttr ? $attribute->value : null;
    }

    private function directChild(?\DOMElement $parent, string $localName): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->namespaceURI === self::WORD_NAMESPACE
                && $child->localName === $localName
            ) {
                return $child;
            }
        }

        return null;
    }
}
