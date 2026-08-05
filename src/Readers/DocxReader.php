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
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\NumberingRef;
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

            return $this->parseDocument($documentXml);
        } finally {
            $package?->close();

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function parseDocument(\DOMDocument $documentXml): Document
    {
        $body = $documentXml->getElementsByTagNameNS(self::WORD_NAMESPACE, 'body')->item(0);
        if (!$body instanceof \DOMElement) {
            return new Document();
        }

        $content = [];

        foreach ($body->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->namespaceURI !== self::WORD_NAMESPACE
                || $child->localName !== 'p'
            ) {
                continue;
            }

            $content[] = $this->parseParagraph($child);
        }

        return new Document($content);
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

        $value = strtolower($property->getAttributeNS(self::WORD_NAMESPACE, 'val'));

        return !in_array($value, ['0', 'false', 'off', 'none', 'nil'], true);
    }

    private function attributeValue(?\DOMElement $element, string $name): ?string
    {
        if ($element === null || !$element->hasAttributeNS(self::WORD_NAMESPACE, $name)) {
            return null;
        }

        return $element->getAttributeNS(self::WORD_NAMESPACE, $name);
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
