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
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
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

    private const DRAWING_WP_NAMESPACE = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    private const DRAWING_A_NAMESPACE = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const RELATIONSHIPS_ATTR_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const IMAGE_RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    private const HYPERLINK_RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    /** 96 DPI: 914400 EMU per inch / 96 pixels per inch. */
    private const EMU_PER_PIXEL = 9525;

    private const IMAGE_MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

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
                $this->resolveImages($package, $package->part('word/_rels/document.xml.rels')),
                $this->resolveHyperlinks($package->part('word/_rels/document.xml.rels')),
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
        array $images,
        array $hyperlinks = [],
    ): Document {
        $body = $documentXml->getElementsByTagNameNS(self::WORD_NAMESPACE, 'body')->item(0);
        if (!$body instanceof \DOMElement) {
            return new Document(numbering: $numbering);
        }

        return new Document($this->parseBodyChildren($body, $images, $hyperlinks), $numbering);
    }

    /**
     * Eagerly resolves every image relationship to its raw bytes and
     * inferred MIME type, keyed by relationship id, so the paragraph
     * parsing chain only ever deals with a plain array instead of the live
     * package. Non-image relationships (hyperlinks, etc.) are skipped via
     * the relationship Type check; image formats with no web/PDF
     * equivalent (WMF/EMF - Word's own vector formats) fall out of
     * IMAGE_MIME_TYPES and are skipped too, so a drawing referencing one
     * later just resolves to no image rather than throwing.
     *
     * @return array<string, array{path: string, data: string, mimeType: string}>
     */
    private function resolveImages(OoxmlPackage $package, ?\DOMDocument $relationshipsXml): array
    {
        if ($relationshipsXml === null) {
            return [];
        }

        $images = [];

        foreach ($relationshipsXml->getElementsByTagNameNS(self::PACKAGE_RELATIONSHIPS_NAMESPACE, 'Relationship') as $relationship) {
            if (!$relationship instanceof \DOMElement || $relationship->getAttribute('Type') !== self::IMAGE_RELATIONSHIP_TYPE) {
                continue;
            }

            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            if ($id === '' || $target === '') {
                continue;
            }

            $mimeType = self::IMAGE_MIME_TYPES[strtolower(pathinfo($target, PATHINFO_EXTENSION))] ?? null;
            if ($mimeType === null) {
                continue;
            }

            $partPath = 'word/'.ltrim($target, '/');
            $data = $package->rawPart($partPath);
            if ($data === null) {
                continue;
            }

            $images[$id] = ['path' => $partPath, 'data' => $data, 'mimeType' => $mimeType];
        }

        return $images;
    }

    /**
     * Resolves every external hyperlink relationship to its target URI,
     * keyed by relationship id, so w:hyperlink elements can be turned into
     * Link nodes during paragraph parsing.
     *
     * @return array<string, string> relationship id => target URI
     */
    private function resolveHyperlinks(?\DOMDocument $relationshipsXml): array
    {
        if ($relationshipsXml === null) {
            return [];
        }

        $hyperlinks = [];

        foreach ($relationshipsXml->getElementsByTagNameNS(self::PACKAGE_RELATIONSHIPS_NAMESPACE, 'Relationship') as $relationship) {
            if (!$relationship instanceof \DOMElement || $relationship->getAttribute('Type') !== self::HYPERLINK_RELATIONSHIP_TYPE) {
                continue;
            }

            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            if ($id === '' || $target === '') {
                continue;
            }

            // Only external hyperlinks become absolute URIs in the output.
            // Internal (package-relative) and anchor links are handled via w:anchor.
            $targetMode = $relationship->getAttribute('TargetMode');
            if ($targetMode !== 'External') {
                continue;
            }

            $hyperlinks[$id] = $target;
        }

        return $hyperlinks;
    }

    /**
     * @param array<string, array{path: string, data: string, mimeType: string}> $images
     * @param array<string, string>                                              $hyperlinks relationship id => external target URI
     *
     * @return BlockInterface[]
     */
    private function parseBodyChildren(\DOMElement $container, array $images, array $hyperlinks = []): array
    {
        $content = [];

        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 'p') {
                $content[] = $this->parseParagraph($child, $images, $hyperlinks);
            } elseif ($child->localName === 'tbl') {
                $content[] = $this->parseTable($child, $images, $hyperlinks);
            } elseif ($child->localName === 'sdt') {
                array_push($content, ...$this->parseSdt($child, $images, $hyperlinks));
            }
        }

        return $content;
    }

    /**
     * w:sdt is a content-control wrapper: its w:sdtContent child holds the
     * actual block content (paragraphs, tables, nested sdt). Without this
     * branch a body-level content control silently vanished.
     *
     * @param array<string, array{path: string, data: string, mimeType: string}> $images
     * @param array<string, string>                                              $hyperlinks
     *
     * @return BlockInterface[]
     */
    private function parseSdt(\DOMElement $sdt, array $images, array $hyperlinks): array
    {
        foreach ($sdt->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->namespaceURI === self::WORD_NAMESPACE
                && $child->localName === 'sdtContent'
            ) {
                return $this->parseBodyChildren($child, $images, $hyperlinks);
            }
        }

        return [];
    }

    /**
     * Only the first w:tblHeader-marked row becomes Table::header(); any
     * further header-marked rows fall through to rows() rather than being
     * dropped, mirroring HtmlReader's/PdfReader's "only the first heading
     * row" convention elsewhere in this project.
     */
    private function parseTable(\DOMElement $tbl, array $images, array $hyperlinks = []): Table
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

            $row = $this->parseTableRow($child, $images, $hyperlinks);

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

    private function parseTableRow(\DOMElement $tr, array $images, array $hyperlinks = []): TableRow
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

            $cells[] = $this->parseTableCell($child, $images, $hyperlinks);
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
    private function parseTableCell(\DOMElement $tc, array $images, array $hyperlinks = []): TableCell
    {
        $properties = $this->directChild($tc, 'tcPr');
        $gridSpan = $this->attributeValue($this->directChild($properties, 'gridSpan'), 'val');
        $colspan = $gridSpan === null ? 1 : max(1, (int) $gridSpan);

        return new TableCell($this->parseBodyChildren($tc, $images, $hyperlinks), $colspan);
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
                // Formats outside the supported set (ordinal, chicago, hex, …)
                // degrade to decimal rendering instead of aborting the read.
                try {
                    $format = NumberFormat::from($value);
                } catch (\ValueError) {
                    $format = NumberFormat::Decimal;
                }
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

    private function parseParagraph(\DOMElement $paragraphElement, array $images, array $hyperlinks = []): BlockInterface
    {
        $properties = $this->directChild($paragraphElement, 'pPr');
        $inlines = $this->parseInlineContainer($paragraphElement, $images, $hyperlinks);

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

        // numId "0" means "cancel inherited numbering" (ECMA-376 §17.9.18).
        // Any explicit or defaulted ilvl is ignored; the paragraph is unnumbered.
        if ($numId === '0') {
            return null;
        }

        // An omitted w:ilvl means level 0 (ECMA-376 §17.9.22).
        $ilvl = $this->attributeValue($this->directChild($numberingProperties, 'ilvl'), 'val') ?? '0';

        if ($numId === null) {
            return null;
        }

        return new NumberingRef((int) $numId, (int) $ilvl);
    }

    /**
     * @param array<string, array{path: string, data: string, mimeType: string}> $images
     * @param array<string, string> $hyperlinks relationship id => external target URI
     *
     * @return InlineInterface[]
     */
    private function parseInlineContainer(\DOMElement $container, array $images, array $hyperlinks = []): array
    {
        $inlines = [];
        /**
         * Field state is a stack because fields nest (e.g. a PAGE field inside
         * a HYPERLINK field). Each frame accumulates its instruction text and
         * its result runs; on end, a HYPERLINK frame wraps its result in a
         * Link, and everything is appended to the parent frame when one is
         * open. `separated` distinguishes the instruction region (content
         * before w:fldChar separate stays outside the field) from the result
         * region (content after separate is the field's output).
         *
         * @var array<int, array{instruction: ?string, pendingHyperlink: ?string, separated: bool, result: InlineInterface[]}> $fieldStack
         */
        $fieldStack = [];

        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 'r') {
                $event = $this->parseRunFieldEvent($child);

                if ($event !== null) {
                    [$eventName, $payload] = $event;

                    if ($eventName === 'begin') {
                        $fieldStack[] = ['instruction' => '', 'pendingHyperlink' => null, 'separated' => false, 'result' => []];
                    } elseif ($eventName === 'instrText') {
                        if ($fieldStack !== []) {
                            $top = array_key_last($fieldStack);
                            if ($fieldStack[$top]['instruction'] !== null) {
                                $fieldStack[$top]['instruction'] .= $payload;
                            }
                        }
                    } elseif ($eventName === 'separate') {
                        if ($fieldStack !== []) {
                            $top = array_key_last($fieldStack);
                            $fieldStack[$top]['pendingHyperlink'] = $this->hyperlinkFromFieldInstruction($fieldStack[$top]['instruction']);
                            $fieldStack[$top]['instruction'] = null;
                            $fieldStack[$top]['separated'] = true;
                        }
                    } elseif ($eventName === 'end') {
                        $frame = array_pop($fieldStack);
                        if ($frame !== null) {
                            $this->appendFieldResult($inlines, $fieldStack, $this->flushField($frame['pendingHyperlink'], $frame['result']));
                        }
                    }
                }

                // A run can carry a field event AND ordinary content
                // (w:t/w:br/w:drawing); never discard the content half. It
                // lands in the innermost separated field's result region, or
                // in the paragraph when no result region is open.
                $runInlines = $this->parseRun($child, $images);
                if ($runInlines !== []) {
                    $top = $fieldStack === [] ? null : array_key_last($fieldStack);
                    if ($top !== null && $fieldStack[$top]['separated']) {
                        $fieldStack[$top]['result'] = [...$fieldStack[$top]['result'], ...$runInlines];
                    } else {
                        array_push($inlines, ...$runInlines);
                    }
                }

                continue;
            }

            if ($child->localName === 'pPr' || $child->localName === 'rPr') {
                continue;
            }

            if ($child->localName === 'hyperlink') {
                foreach ($this->parseHyperlink($child, $images, $hyperlinks) as $inline) {
                    $inlines[] = $inline;
                }

                continue;
            }

            foreach ($this->parseInlineContainer($child, $images, $hyperlinks) as $inline) {
                $inlines[] = $inline;
            }
        }

        // Fields that never closed (malformed source) must not lose their
        // result runs; resolve from the innermost out.
        while ($fieldStack !== []) {
            $frame = array_pop($fieldStack);
            $this->appendFieldResult($inlines, $fieldStack, $this->flushField($frame['pendingHyperlink'], $frame['result']));
        }

        return $inlines;
    }

    /**
     * Appends emitted inlines either to the innermost open field's result
     * buffer or, when no field is open, straight to the paragraph's inlines.
     *
     * @param InlineInterface[]                                                                                        $inlines
     * @param array<int, array{instruction: ?string, pendingHyperlink: ?string, separated: bool, result: InlineInterface[]}> $fieldStack
     * @param InlineInterface[]                                                                                        $emitted
     */
    private function appendFieldResult(array &$inlines, array &$fieldStack, array $emitted): void
    {
        if ($emitted === []) {
            return;
        }

        if ($fieldStack === []) {
            array_push($inlines, ...$emitted);

            return;
        }

        $top = array_key_last($fieldStack);
        $fieldStack[$top]['result'] = [...$fieldStack[$top]['result'], ...$emitted];
    }

    /**
     * Detects field-code events (w:fldChar / w:instrText) inside a run.
     * Returns [eventName, payload] where eventName is one of begin|separate|
     * end|instrText, or null when the run carries ordinary content instead.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function parseRunFieldEvent(\DOMElement $run): ?array
    {
        $instruction = null;

        foreach ($run->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 'fldChar') {
                $type = $this->attributeValue($child, 'fldCharType') ?? '';

                if (in_array($type, ['begin', 'separate', 'end'], true)) {
                    return [$type, null];
                }
            } elseif ($child->localName === 'instrText') {
                $instruction = $child->textContent;
            }
        }

        return $instruction === null ? null : ['instrText', $instruction];
    }

    /**
     * Extracts the target from a field instruction. Word emits the
     * destination after optional switches that must not be mistaken for it:
     *
     *   HYPERLINK "https://example.com" \o "tip"
     *   HYPERLINK \o "tip" "https://example.com" \t "_blank"
     *   HYPERLINK \l "Bookmark"          (or: HYPERLINK \l Bookmark1)
     *
     * \l is an internal bookmark (quoted or bare); \o (screentip) and \t
     * (target frame) take a quoted argument that is skipped. Returns null
     * for any other field (PAGE, TOC, ...) so the result runs keep their
     * cached text but gain no link wrapper.
     */
    private function hyperlinkFromFieldInstruction(?string $instruction): ?string
    {
        if ($instruction === null) {
            return null;
        }

        // Tokenize: switches (\x), whole quoted strings, bare words. A quoted
        // string stays one token, so a \l inside a screentip is never read as
        // a switch and a destination containing spaces survives intact.
        preg_match_all('/\\\\.|"[^"]*"|\S+/', $instruction, $matches);
        $tokens = $matches[0] ?? [];

        $start = null;
        foreach ($tokens as $index => $token) {
            if (strcasecmp($token, 'HYPERLINK') === 0) {
                $start = $index + 1;
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $bookmark = null;
        $url = null;

        for ($i = $start, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];

            if (str_starts_with($token, '\\')) {
                $switch = strtolower(substr($token, 1));

                if ($switch === 'l') {
                    $argument = $tokens[$i + 1] ?? null;
                    if ($argument !== null) {
                        $bookmark = trim($argument, '"');
                        ++$i;
                    }
                } elseif (in_array($switch, ['o', 't'], true)) {
                    // \o (screentip) and \t (target frame) take a quoted argument.
                    $argument = $tokens[$i + 1] ?? null;
                    if ($argument !== null && str_starts_with($argument, '"')) {
                        ++$i;
                    }
                }

                continue;
            }

            if ($url === null) {
                $url = trim($token, '"');
            }
        }

        if ($bookmark !== null) {
            return '#'.$bookmark;
        }

        return $url;
    }

    /**
     * @param InlineInterface[] $fieldResult
     *
     * @return InlineInterface[]
     */
    private function flushField(?string $pendingHyperlink, array $fieldResult): array
    {
        if ($fieldResult === []) {
            return [];
        }

        if ($pendingHyperlink !== null) {
            return [new Link($pendingHyperlink, $fieldResult)];
        }

        return $fieldResult;
    }

    /**
     * A w:hyperlink carries its destination either as an external
     * relationship (r:id resolved through word/_rels/document.xml.rels)
     * or as an internal bookmark (w:anchor). When neither resolves — a
     * missing relationship part or an unknown id — the wrapped runs fall
     * back to plain inlines rather than producing a href-less Link.
     *
     * @param array<string, array{path: string, data: string, mimeType: string}> $images
     * @param array<string, string> $hyperlinks
     *
     * @return InlineInterface[]
     */
    private function parseHyperlink(\DOMElement $element, array $images, array $hyperlinks): array
    {
        $children = $this->parseInlineContainer($element, $images, $hyperlinks);

        $relationshipId = $this->relationshipAttributeValue($element, 'id');
        $anchor = $this->attributeValue($element, 'anchor');

        $href = null;

        if ($relationshipId !== null) {
            $href = $hyperlinks[$relationshipId] ?? null;
        }

        // Fall back to w:anchor when r:id is absent or unresolvable
        if ($href === null && $anchor !== null) {
            $href = '#'.$anchor;
        }

        if ($href === null) {
            return $children;
        }

        return [new Link($href, $children, $this->attributeValue($element, 'tooltip'))];
    }

    /**
     * @param array<string, array{path: string, data: string, mimeType: string}> $images
     *
     * @return InlineInterface[]
     */
    private function parseRun(\DOMElement $run, array $images): array
    {
        $children = [];

        foreach ($run->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::WORD_NAMESPACE) {
                continue;
            }

            if ($child->localName === 't') {
                $children[] = new Text($child->textContent);
            } elseif ($child->localName === 'br' || $child->localName === 'cr') {
                $children[] = new LineBreak();
            } elseif ($child->localName === 'tab') {
                $children[] = new Text("\t");
            } elseif ($child->localName === 'noBreakHyphen') {
                $children[] = new Text("\u{2011}");
            } elseif ($child->localName === 'drawing') {
                $image = $this->parseDrawing($child, $images);
                if ($image !== null) {
                    $children[] = $image;
                }
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

    /**
     * w:drawing always lives inside a w:r inside a w:p in real OOXML -
     * there is no bare "block-level image" concept the way HTML has <img>
     * outside any paragraph - so every drawing resolves to an
     * InlineImage, never a block Image. This covers both wp:inline and
     * wp:anchor (floating) drawings identically, since neither the
     * position/wrapping info nor the inline-vs-anchor distinction is
     * modeled here - only the referenced image and its declared size.
     * Returns null (skipping just this drawing, not the surrounding
     * run/paragraph) when the relationship wasn't resolved to a supported
     * image format, e.g. a WMF/EMF vector image or a dangling reference.
     *
     * @param array<string, array{path: string, data: string, mimeType: string}> $images
     */
    private function parseDrawing(\DOMElement $drawing, array $images): ?InlineImage
    {
        $blip = $drawing->getElementsByTagNameNS(self::DRAWING_A_NAMESPACE, 'blip')->item(0);
        if (!$blip instanceof \DOMElement) {
            return null;
        }

        $relationshipId = $blip->getAttributeNS(self::RELATIONSHIPS_ATTR_NAMESPACE, 'embed');
        if ($relationshipId === '' || !isset($images[$relationshipId])) {
            return null;
        }

        $image = $images[$relationshipId];

        $extent = $drawing->getElementsByTagNameNS(self::DRAWING_WP_NAMESPACE, 'extent')->item(0);
        $width = $extent instanceof \DOMElement ? $this->emuToPixels($extent->getAttribute('cx')) : null;
        $height = $extent instanceof \DOMElement ? $this->emuToPixels($extent->getAttribute('cy')) : null;

        $docPr = $drawing->getElementsByTagNameNS(self::DRAWING_WP_NAMESPACE, 'docPr')->item(0);
        $alt = $docPr instanceof \DOMElement ? $docPr->getAttribute('descr') : '';
        $name = $docPr instanceof \DOMElement ? $docPr->getAttribute('name') : '';

        return new InlineImage(
            src: $image['path'],
            alt: $alt,
            title: $name === '' ? null : $name,
            data: $image['data'],
            mimeType: $image['mimeType'],
            width: $width,
            height: $height,
        );
    }

    private function emuToPixels(string $value): ?int
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) / self::EMU_PER_PIXEL);
    }

    private function isEnabled(?\DOMElement $property): bool
    {
        if ($property === null) {
            return false;
        }

        $value = strtolower($this->attributeValue($property, 'val') ?? '');

        return !in_array($value, ['0', 'false', 'off', 'none', 'nil'], true);
    }

    private function relationshipAttributeValue(?\DOMElement $element, string $name): ?string
    {
        if ($element === null) {
            return null;
        }

        $attribute = $element->getAttributeNodeNS(self::RELATIONSHIPS_ATTR_NAMESPACE, $name);

        return $attribute instanceof \DOMAttr ? $attribute->value : null;
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
