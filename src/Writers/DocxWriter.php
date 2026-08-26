<?php

declare(strict_types=1);

namespace Fissible\Transmark\Writers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\Image;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Comment;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\Footnote;
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
use Fissible\Transmark\Writers\Exception\DocxWriteException;
use Fissible\Transmark\Writers\Exception\UnsupportedNodeException;

final class DocxWriter implements WriterInterface
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const OFFICE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';
    private const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    /** @var array<int, array{id: string, type: string, target: string, external: bool}> */
    private array $relationships = [];

    /** @var array<int, AbstractNum> */
    private array $syntheticAbstractNums = [];

    /** @var array<int, Num> */
    private array $syntheticNums = [];

    private int $nextRelationshipId = 1;
    private int $nextAbstractNumId = 1;
    private int $nextNumId = 1;

    private NumberingDefinitions $numberingDefinitions;

    public function __construct(
        private readonly ?string $temporaryDirectory = null,
    ) {
    }

    public function write(Document $document): string
    {
        $this->initialize($document);

        $hasNumbering = $document->numbering()->abstractNums() !== []
            || $document->numbering()->nums() !== []
            || $this->containsNumberedParagraph($document->content())
            || $this->containsStructuralList($document->content());

        $this->addRelationship(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles',
            'styles.xml',
        );

        if ($hasNumbering) {
            $this->addRelationship(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering',
                'numbering.xml',
            );
        }

        $parts = [
            '[Content_Types].xml' => '',
            '_rels/.rels' => $this->packageRelationshipsXml(),
            'word/document.xml' => $this->documentXml($document),
            'word/styles.xml' => $this->stylesXml(),
            'word/_rels/document.xml.rels' => '',
        ];

        if ($hasNumbering) {
            $parts['word/numbering.xml'] = $this->numberingXml($document->numbering());
        }

        $parts['word/_rels/document.xml.rels'] = $this->documentRelationshipsXml();
        $parts['[Content_Types].xml'] = $this->contentTypesXml($hasNumbering);

        return $this->package($parts);
    }

    private function initialize(Document $document): void
    {
        $this->relationships = [];
        $this->syntheticAbstractNums = [];
        $this->syntheticNums = [];
        $this->nextRelationshipId = 1;
        $this->numberingDefinitions = $document->numbering();

        foreach ($document->numbering()->nums() as $num) {
            if ($document->numbering()->abstractNum($num->abstractNumId()) === null) {
                throw new DocxWriteException(sprintf(
                    'Numbering numId %d references missing abstractNumId %d.',
                    $num->numId(),
                    $num->abstractNumId(),
                ));
            }
        }

        $abstractIds = array_keys($document->numbering()->abstractNums());
        $numIds = array_keys($document->numbering()->nums());
        $this->nextAbstractNumId = $abstractIds === [] ? 1 : max($abstractIds) + 1;
        $this->nextNumId = $numIds === [] ? 1 : max($numIds) + 1;
    }

    /**
     * @param BlockInterface[] $blocks
     */
    private function containsStructuralList(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block instanceof ListNode) {
                return true;
            }

            if ($block instanceof BlockQuote && $this->containsStructuralList($block->content())) {
                return true;
            }

            if ($block instanceof Table) {
                $rows = $block->header() === null ? $block->rows() : [$block->header(), ...$block->rows()];
                foreach ($rows as $row) {
                    foreach ($row->cells() as $cell) {
                        if ($this->containsStructuralList($cell->content())) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param BlockInterface[] $blocks
     */
    private function containsNumberedParagraph(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block instanceof Paragraph && $block->isNumbered()) {
                return true;
            }

            if ($block instanceof BlockQuote && $this->containsNumberedParagraph($block->content())) {
                return true;
            }

            if ($block instanceof ListNode) {
                foreach ($block->items() as $item) {
                    if ($this->containsNumberedParagraph($item->content())) {
                        return true;
                    }
                }
            }

            if ($block instanceof Table) {
                $rows = $block->header() === null ? $block->rows() : [$block->header(), ...$block->rows()];
                foreach ($rows as $row) {
                    foreach ($row->cells() as $cell) {
                        if ($this->containsNumberedParagraph($cell->content())) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function documentXml(Document $document): string
    {
        $dom = $this->dom();
        $root = $dom->createElementNS(self::WORD_NAMESPACE, 'w:document');
        $root->setAttributeNS(self::XMLNS_NAMESPACE, 'xmlns:r', self::OFFICE_RELATIONSHIPS);
        $dom->appendChild($root);
        $body = $this->wordElement($dom, $root, 'body');

        foreach ($document->content() as $index => $block) {
            $this->renderBlock($dom, $body, $block, sprintf('document.content[%d]', $index));
        }

        $this->wordElement($dom, $body, 'sectPr');

        return $this->xml($dom);
    }

    private function renderBlock(
        \DOMDocument $dom,
        \DOMNode $parent,
        BlockInterface $block,
        string $path,
    ): void {
        if ($block instanceof Paragraph) {
            $this->renderParagraph($dom, $parent, $block, $path);

            return;
        }

        if ($block instanceof Heading) {
            $this->renderParagraphContent(
                $dom,
                $parent,
                $block->inlines(),
                $path,
                styleName: 'Heading'.$block->level(),
            );

            return;
        }

        if ($block instanceof BlockQuote) {
            foreach ($block->content() as $index => $child) {
                if ($child instanceof Paragraph) {
                    $this->renderParagraph(
                        $dom,
                        $parent,
                        $child,
                        sprintf('%s.content[%d]', $path, $index),
                        forcedStyleName: 'Quote',
                    );
                } else {
                    $this->renderBlock(
                        $dom,
                        $parent,
                        $child,
                        sprintf('%s.content[%d]', $path, $index),
                    );
                }
            }

            return;
        }

        if ($block instanceof HorizontalRule) {
            $paragraph = $this->wordElement($dom, $parent, 'p');
            $properties = $this->wordElement($dom, $paragraph, 'pPr');
            $borders = $this->wordElement($dom, $properties, 'pBdr');
            $bottom = $this->wordElement($dom, $borders, 'bottom');
            $this->wordAttribute($bottom, 'val', 'single');
            $this->wordAttribute($bottom, 'sz', '6');
            $this->wordAttribute($bottom, 'space', '1');
            $this->wordAttribute($bottom, 'color', 'auto');

            return;
        }

        if ($block instanceof ListNode) {
            $this->renderStructuralList($dom, $parent, $block, $path);

            return;
        }

        if ($block instanceof Table) {
            $this->renderTable($dom, $parent, $block, $path);

            return;
        }

        if ($block instanceof CodeBlock) {
            $inlines = [];
            $segments = explode("\n", str_replace(["\r\n", "\r"], "\n", $block->content()));
            foreach ($segments as $index => $segment) {
                if ($segment !== '') {
                    $inlines[] = new Code($segment);
                }
                if ($index < count($segments) - 1) {
                    $inlines[] = new LineBreak();
                }
            }
            $this->renderParagraphContent($dom, $parent, $inlines, $path, styleName: 'CodeBlock');

            return;
        }

        if ($block instanceof Image) {
            throw UnsupportedNodeException::at($block, $path);
        }

        throw UnsupportedNodeException::at($block, $path);
    }

    private function renderParagraph(
        \DOMDocument $dom,
        \DOMNode $parent,
        Paragraph $paragraph,
        string $path,
        ?NumberingRef $forcedNumbering = null,
        ?string $forcedStyleName = null,
    ): void {
        $this->renderParagraphContent(
            $dom,
            $parent,
            $paragraph->inlines(),
            $path,
            $forcedStyleName ?? $paragraph->styleName(),
            $forcedNumbering ?? $paragraph->numbering(),
        );
    }

    /**
     * @param InlineInterface[] $inlines
     */
    private function renderParagraphContent(
        \DOMDocument $dom,
        \DOMNode $parent,
        array $inlines,
        string $path,
        ?string $styleName = null,
        ?NumberingRef $numbering = null,
    ): void {
        $paragraph = $this->wordElement($dom, $parent, 'p');

        if ($styleName !== null || $numbering !== null) {
            $properties = $this->wordElement($dom, $paragraph, 'pPr');

            if ($styleName !== null) {
                $style = $this->wordElement($dom, $properties, 'pStyle');
                $this->wordAttribute($style, 'val', $styleName);
            }

            if ($numbering !== null) {
                $this->assertValidNumberingRef($numbering, $path);
                $numberingProperties = $this->wordElement($dom, $properties, 'numPr');
                $ilvl = $this->wordElement($dom, $numberingProperties, 'ilvl');
                $this->wordAttribute($ilvl, 'val', (string) $numbering->ilvl());
                $numId = $this->wordElement($dom, $numberingProperties, 'numId');
                $this->wordAttribute($numId, 'val', (string) $numbering->numId());
            }
        }

        $this->renderInlines($dom, $paragraph, $inlines, $path.'.inlines');
    }

    private function assertValidNumberingRef(NumberingRef $numbering, string $path): void
    {
        $num = $this->numberingDefinitions->num($numbering->numId())
            ?? $this->syntheticNums[$numbering->numId()]
            ?? null;

        if ($num === null) {
            throw new DocxWriteException(sprintf(
                'Numbering reference at %s uses missing numId %d.',
                $path,
                $numbering->numId(),
            ));
        }

        $abstractNum = $this->numberingDefinitions->abstractNum($num->abstractNumId())
            ?? $this->syntheticAbstractNums[$num->abstractNumId()]
            ?? null;

        if ($abstractNum?->level($numbering->ilvl()) === null) {
            throw new DocxWriteException(sprintf(
                'Numbering reference at %s uses missing level %d for numId %d.',
                $path,
                $numbering->ilvl(),
                $numbering->numId(),
            ));
        }
    }

    private function renderStructuralList(
        \DOMDocument $dom,
        \DOMNode $parent,
        ListNode $list,
        string $path,
        int $depth = 0,
    ): void {
        $numId = $this->allocateStructuralList($list, $depth);

        foreach ($list->items() as $itemIndex => $item) {
            $this->renderStructuralListItem(
                $dom,
                $parent,
                $item,
                $numId,
                $depth,
                sprintf('%s.items[%d]', $path, $itemIndex),
            );
        }
    }

    private function renderStructuralListItem(
        \DOMDocument $dom,
        \DOMNode $parent,
        ListItem $item,
        int $numId,
        int $depth,
        string $path,
    ): void {
        $numberedParagraphWritten = false;

        foreach ($item->content() as $index => $block) {
            $childPath = sprintf('%s.content[%d]', $path, $index);

            if ($block instanceof ListNode) {
                if (!$numberedParagraphWritten) {
                    $this->renderParagraphContent(
                        $dom,
                        $parent,
                        [],
                        $path,
                        numbering: new NumberingRef($numId, $depth),
                    );
                    $numberedParagraphWritten = true;
                }

                $this->renderStructuralList($dom, $parent, $block, $childPath, $depth + 1);
                continue;
            }

            if (!$numberedParagraphWritten && $block instanceof Paragraph) {
                $this->renderParagraph(
                    $dom,
                    $parent,
                    $block,
                    $childPath,
                    forcedNumbering: new NumberingRef($numId, $depth),
                );
                $numberedParagraphWritten = true;
                continue;
            }

            if (!$numberedParagraphWritten) {
                $this->renderParagraphContent(
                    $dom,
                    $parent,
                    [],
                    $path,
                    numbering: new NumberingRef($numId, $depth),
                );
                $numberedParagraphWritten = true;
            }

            $this->renderBlock($dom, $parent, $block, $childPath);
        }

        if (!$numberedParagraphWritten) {
            $this->renderParagraphContent(
                $dom,
                $parent,
                [],
                $path,
                numbering: new NumberingRef($numId, $depth),
            );
        }
    }

    private function allocateStructuralList(ListNode $list, int $depth): int
    {
        $abstractNumId = $this->nextAbstractNumId++;
        $numId = $this->nextNumId++;
        $levels = [];

        for ($ilvl = 0; $ilvl <= $depth; ++$ilvl) {
            $isTargetLevel = $ilvl === $depth;
            $isBullet = $isTargetLevel && $list->type() === ListNode::TYPE_UNORDERED;
            $levels[$ilvl] = new Level(
                ilvl: $ilvl,
                format: $isBullet ? NumberFormat::Bullet : NumberFormat::Decimal,
                lvlText: $isBullet ? '•' : '%'.($ilvl + 1).'.',
            );
        }

        $this->syntheticAbstractNums[$abstractNumId] = new AbstractNum(
            $abstractNumId,
            $levels,
            'hybridMultilevel',
        );
        $this->syntheticNums[$numId] = new Num(
            $numId,
            $abstractNumId,
            $list->start() === 1 ? [] : [$depth => $list->start()],
        );

        return $numId;
    }

    private function renderTable(
        \DOMDocument $dom,
        \DOMNode $parent,
        Table $table,
        string $path,
    ): void {
        $element = $this->wordElement($dom, $parent, 'tbl');
        $properties = $this->wordElement($dom, $element, 'tblPr');
        $width = $this->wordElement($dom, $properties, 'tblW');
        $this->wordAttribute($width, 'w', '0');
        $this->wordAttribute($width, 'type', 'auto');

        if ($table->header() !== null) {
            $this->renderTableRow($dom, $element, $table->header(), $path.'.header', true);
        }

        foreach ($table->rows() as $index => $row) {
            $this->renderTableRow($dom, $element, $row, sprintf('%s.rows[%d]', $path, $index));
        }
    }

    private function renderTableRow(
        \DOMDocument $dom,
        \DOMNode $parent,
        TableRow $row,
        string $path,
        bool $header = false,
    ): void {
        $element = $this->wordElement($dom, $parent, 'tr');

        if ($header) {
            $properties = $this->wordElement($dom, $element, 'trPr');
            $this->wordElement($dom, $properties, 'tblHeader');
        }

        foreach ($row->cells() as $index => $cell) {
            $this->renderTableCell($dom, $element, $cell, sprintf('%s.cells[%d]', $path, $index));
        }
    }

    private function renderTableCell(
        \DOMDocument $dom,
        \DOMNode $parent,
        TableCell $cell,
        string $path,
    ): void {
        if ($cell->rowspan() !== 1) {
            throw new DocxWriteException(sprintf(
                'DocxWriter does not yet support rowspan=%d at %s.',
                $cell->rowspan(),
                $path,
            ));
        }

        if ($cell->colspan() < 1) {
            throw new DocxWriteException(sprintf(
                'DocxWriter requires colspan >= 1 at %s, got %d.',
                $path,
                $cell->colspan(),
            ));
        }

        $element = $this->wordElement($dom, $parent, 'tc');
        $properties = $this->wordElement($dom, $element, 'tcPr');

        if ($cell->colspan() !== 1) {
            $gridSpan = $this->wordElement($dom, $properties, 'gridSpan');
            $this->wordAttribute($gridSpan, 'val', (string) $cell->colspan());
        }

        foreach ($cell->content() as $index => $block) {
            $this->renderBlock($dom, $element, $block, sprintf('%s.content[%d]', $path, $index));
        }

        if ($cell->content() === []) {
            $this->wordElement($dom, $element, 'p');
        }
    }

    /**
     * @param InlineInterface[] $inlines
     * @param array<string, bool|string> $properties
     */
    private function renderInlines(
        \DOMDocument $dom,
        \DOMNode $parent,
        array $inlines,
        string $path,
        array $properties = [],
    ): void {
        foreach ($inlines as $index => $inline) {
            $inlinePath = sprintf('%s[%d]', $path, $index);

            if ($inline instanceof Text) {
                $this->appendTextRuns($dom, $parent, $inline->content(), $properties);
            } elseif ($inline instanceof LineBreak) {
                $this->appendBreakRun($dom, $parent, $properties);
            } elseif ($inline instanceof Strong) {
                $this->renderInlines($dom, $parent, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'bold' => true,
                ]);
            } elseif ($inline instanceof Emphasis) {
                $this->renderInlines($dom, $parent, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'italic' => true,
                ]);
            } elseif ($inline instanceof Underline) {
                $this->renderInlines($dom, $parent, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'underline' => true,
                ]);
            } elseif ($inline instanceof Strike) {
                $this->renderInlines($dom, $parent, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'strike' => true,
                ]);
            } elseif ($inline instanceof Superscript) {
                $this->renderInlines($dom, $parent, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'verticalAlignment' => 'superscript',
                ]);
            } elseif ($inline instanceof Subscript) {
                $this->renderInlines($dom, $parent, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'verticalAlignment' => 'subscript',
                ]);
            } elseif ($inline instanceof Code) {
                $this->appendTextRuns($dom, $parent, $inline->content(), [
                    ...$properties,
                    'code' => true,
                ]);
            } elseif ($inline instanceof Link) {
                $relationshipId = $this->addRelationship(
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
                    $inline->href(),
                    true,
                );
                $hyperlink = $this->wordElement($dom, $parent, 'hyperlink');
                $hyperlink->setAttributeNS(self::OFFICE_RELATIONSHIPS, 'r:id', $relationshipId);
                if ($inline->title() !== null) {
                    $this->wordAttribute($hyperlink, 'tooltip', $inline->title());
                }
                $this->renderInlines($dom, $hyperlink, $inline->children(), $inlinePath.'.children', [
                    ...$properties,
                    'hyperlink' => true,
                ]);
            } elseif (
                $inline instanceof InlineImage
                || $inline instanceof Footnote
                || $inline instanceof Comment
            ) {
                throw UnsupportedNodeException::at($inline, $inlinePath);
            } else {
                throw UnsupportedNodeException::at($inline, $inlinePath);
            }
        }
    }

    /**
     * @param array<string, bool|string> $properties
     */
    private function appendTextRuns(
        \DOMDocument $dom,
        \DOMNode $parent,
        string $text,
        array $properties,
    ): void {
        $segments = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        foreach ($segments as $index => $segment) {
            if ($segment !== '') {
                $run = $this->appendRun($dom, $parent, $properties);
                $textElement = $this->wordElement($dom, $run, 't');
                if (
                    preg_match('/^\s|\s$/u', $segment) === 1
                    || preg_match('/\s{2,}/u', $segment) === 1
                ) {
                    $textElement->setAttributeNS(self::XML_NAMESPACE, 'xml:space', 'preserve');
                }
                $textElement->appendChild($dom->createTextNode($segment));
            }

            if ($index < count($segments) - 1) {
                $this->appendBreakRun($dom, $parent, $properties);
            }
        }
    }

    /**
     * @param array<string, bool|string> $properties
     */
    private function appendBreakRun(
        \DOMDocument $dom,
        \DOMNode $parent,
        array $properties,
    ): void {
        $run = $this->appendRun($dom, $parent, $properties);
        $this->wordElement($dom, $run, 'br');
    }

    /**
     * @param array<string, bool|string> $properties
     */
    private function appendRun(
        \DOMDocument $dom,
        \DOMNode $parent,
        array $properties,
    ): \DOMElement {
        $run = $this->wordElement($dom, $parent, 'r');

        if ($properties === []) {
            return $run;
        }

        $runProperties = $this->wordElement($dom, $run, 'rPr');

        if (($properties['hyperlink'] ?? false) === true) {
            $style = $this->wordElement($dom, $runProperties, 'rStyle');
            $this->wordAttribute($style, 'val', 'Hyperlink');
        }
        // rPr children follow the CT_RPr schema sequence (ECMA-376
        // §17.3.2.28): rStyle, rFonts, b, i, u, strike, … vertAlign.
        if (($properties['code'] ?? false) === true) {
            $fonts = $this->wordElement($dom, $runProperties, 'rFonts');
            $this->wordAttribute($fonts, 'ascii', 'Courier New');
            $this->wordAttribute($fonts, 'hAnsi', 'Courier New');
        }
        if (($properties['bold'] ?? false) === true) {
            $this->wordElement($dom, $runProperties, 'b');
        }
        if (($properties['italic'] ?? false) === true) {
            $this->wordElement($dom, $runProperties, 'i');
        }
        if (($properties['underline'] ?? false) === true) {
            $underline = $this->wordElement($dom, $runProperties, 'u');
            $this->wordAttribute($underline, 'val', 'single');
        }
        if (($properties['strike'] ?? false) === true) {
            $this->wordElement($dom, $runProperties, 'strike');
        }
        if (isset($properties['verticalAlignment'])) {
            $alignment = $this->wordElement($dom, $runProperties, 'vertAlign');
            $this->wordAttribute($alignment, 'val', (string) $properties['verticalAlignment']);
        }

        return $run;
    }

    private function numberingXml(NumberingDefinitions $definitions): string
    {
        $dom = $this->dom();
        $root = $dom->createElementNS(self::WORD_NAMESPACE, 'w:numbering');
        $dom->appendChild($root);

        $abstractNums = array_replace($definitions->abstractNums(), $this->syntheticAbstractNums);
        $nums = array_replace($definitions->nums(), $this->syntheticNums);
        ksort($abstractNums);
        ksort($nums);

        foreach ($abstractNums as $abstractNum) {
            $element = $this->wordElement($dom, $root, 'abstractNum');
            $this->wordAttribute($element, 'abstractNumId', (string) $abstractNum->id());

            if ($abstractNum->multiLevelType() !== null) {
                $type = $this->wordElement($dom, $element, 'multiLevelType');
                $this->wordAttribute($type, 'val', $abstractNum->multiLevelType());
            }

            $levels = $abstractNum->levels();
            ksort($levels);
            foreach ($levels as $level) {
                $this->appendNumberingLevel($dom, $element, $level, isset($this->syntheticAbstractNums[$abstractNum->id()]));
            }
        }

        foreach ($nums as $num) {
            $element = $this->wordElement($dom, $root, 'num');
            $this->wordAttribute($element, 'numId', (string) $num->numId());
            $abstractNumId = $this->wordElement($dom, $element, 'abstractNumId');
            $this->wordAttribute($abstractNumId, 'val', (string) $num->abstractNumId());

            $overrides = $num->levelOverrides();
            ksort($overrides);
            foreach ($overrides as $ilvl => $start) {
                $override = $this->wordElement($dom, $element, 'lvlOverride');
                $this->wordAttribute($override, 'ilvl', (string) $ilvl);
                $startOverride = $this->wordElement($dom, $override, 'startOverride');
                $this->wordAttribute($startOverride, 'val', (string) $start);
            }
        }

        return $this->xml($dom);
    }

    private function appendNumberingLevel(
        \DOMDocument $dom,
        \DOMNode $parent,
        Level $level,
        bool $synthetic,
    ): void {
        $element = $this->wordElement($dom, $parent, 'lvl');
        $this->wordAttribute($element, 'ilvl', (string) $level->ilvl());
        $start = $this->wordElement($dom, $element, 'start');
        $this->wordAttribute($start, 'val', (string) $level->start());
        $format = $this->wordElement($dom, $element, 'numFmt');
        $this->wordAttribute($format, 'val', $level->format()->value);

        if ($level->restartRule() !== RestartRule::DefaultImmediateParent) {
            $restart = $this->wordElement($dom, $element, 'lvlRestart');
            $this->wordAttribute(
                $restart,
                'val',
                $level->restartRule() === RestartRule::Never
                    ? '0'
                    : (string) (($level->restartAfterIlvl() ?? 0) + 1),
            );
        }

        if ($level->isLegal()) {
            $this->wordElement($dom, $element, 'isLgl');
        }

        $text = $this->wordElement($dom, $element, 'lvlText');
        $this->wordAttribute($text, 'val', $level->lvlText());

        if ($synthetic) {
            $justification = $this->wordElement($dom, $element, 'lvlJc');
            $this->wordAttribute($justification, 'val', 'left');
            $paragraphProperties = $this->wordElement($dom, $element, 'pPr');
            $indent = $this->wordElement($dom, $paragraphProperties, 'ind');
            $this->wordAttribute($indent, 'left', (string) (($level->ilvl() + 1) * 720));
            $this->wordAttribute($indent, 'hanging', '360');
        }
    }

    private function stylesXml(): string
    {
        $dom = $this->dom();
        $root = $dom->createElementNS(self::WORD_NAMESPACE, 'w:styles');
        $dom->appendChild($root);

        $this->appendParagraphStyle($dom, $root, 'Normal', 'Normal', true);
        for ($level = 1; $level <= 6; ++$level) {
            $this->appendParagraphStyle($dom, $root, 'Heading'.$level, 'heading '.$level, false, $level - 1);
        }
        $this->appendParagraphStyle($dom, $root, 'Quote', 'Quote');
        $this->appendParagraphStyle($dom, $root, 'CodeBlock', 'Code Block', false, null, true);
        $this->appendCharacterStyle($dom, $root, 'Hyperlink', 'Hyperlink', true);

        return $this->xml($dom);
    }

    private function appendParagraphStyle(
        \DOMDocument $dom,
        \DOMNode $parent,
        string $styleId,
        string $name,
        bool $default = false,
        ?int $outlineLevel = null,
        bool $monospace = false,
    ): void {
        $style = $this->wordElement($dom, $parent, 'style');
        $this->wordAttribute($style, 'type', 'paragraph');
        $this->wordAttribute($style, 'styleId', $styleId);
        if ($default) {
            $this->wordAttribute($style, 'default', '1');
        }
        $nameElement = $this->wordElement($dom, $style, 'name');
        $this->wordAttribute($nameElement, 'val', $name);

        if (!$default) {
            $basedOn = $this->wordElement($dom, $style, 'basedOn');
            $this->wordAttribute($basedOn, 'val', 'Normal');
        }

        if ($outlineLevel !== null) {
            $this->wordElement($dom, $style, 'qFormat');
            $properties = $this->wordElement($dom, $style, 'pPr');
            $outline = $this->wordElement($dom, $properties, 'outlineLvl');
            $this->wordAttribute($outline, 'val', (string) $outlineLevel);
        }

        if ($monospace) {
            $runProperties = $this->wordElement($dom, $style, 'rPr');
            $fonts = $this->wordElement($dom, $runProperties, 'rFonts');
            $this->wordAttribute($fonts, 'ascii', 'Courier New');
            $this->wordAttribute($fonts, 'hAnsi', 'Courier New');
        }
    }

    private function appendCharacterStyle(
        \DOMDocument $dom,
        \DOMNode $parent,
        string $styleId,
        string $name,
        bool $hyperlink = false,
    ): void {
        $style = $this->wordElement($dom, $parent, 'style');
        $this->wordAttribute($style, 'type', 'character');
        $this->wordAttribute($style, 'styleId', $styleId);
        $nameElement = $this->wordElement($dom, $style, 'name');
        $this->wordAttribute($nameElement, 'val', $name);

        if ($hyperlink) {
            $runProperties = $this->wordElement($dom, $style, 'rPr');
            $color = $this->wordElement($dom, $runProperties, 'color');
            $this->wordAttribute($color, 'val', '0563C1');
            $underline = $this->wordElement($dom, $runProperties, 'u');
            $this->wordAttribute($underline, 'val', 'single');
        }
    }

    private function contentTypesXml(bool $hasNumbering): string
    {
        $dom = $this->dom();
        $root = $dom->createElementNS(self::CONTENT_TYPES, 'Types');
        $dom->appendChild($root);
        $this->appendContentType($dom, $root, 'Default', [
            'Extension' => 'rels',
            'ContentType' => 'application/vnd.openxmlformats-package.relationships+xml',
        ]);
        $this->appendContentType($dom, $root, 'Default', [
            'Extension' => 'xml',
            'ContentType' => 'application/xml',
        ]);
        $this->appendContentType($dom, $root, 'Override', [
            'PartName' => '/word/document.xml',
            'ContentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        ]);
        $this->appendContentType($dom, $root, 'Override', [
            'PartName' => '/word/styles.xml',
            'ContentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
        ]);

        if ($hasNumbering) {
            $this->appendContentType($dom, $root, 'Override', [
                'PartName' => '/word/numbering.xml',
                'ContentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
            ]);
        }

        return $this->xml($dom);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function appendContentType(
        \DOMDocument $dom,
        \DOMNode $parent,
        string $name,
        array $attributes,
    ): void {
        $element = $dom->createElementNS(self::CONTENT_TYPES, $name);
        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }
        $parent->appendChild($element);
    }

    private function packageRelationshipsXml(): string
    {
        $dom = $this->dom();
        $root = $dom->createElementNS(self::PACKAGE_RELATIONSHIPS, 'Relationships');
        $dom->appendChild($root);
        $relationship = $dom->createElementNS(self::PACKAGE_RELATIONSHIPS, 'Relationship');
        $relationship->setAttribute('Id', 'rId1');
        $relationship->setAttribute(
            'Type',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument',
        );
        $relationship->setAttribute('Target', 'word/document.xml');
        $root->appendChild($relationship);

        return $this->xml($dom);
    }

    private function documentRelationshipsXml(): string
    {
        $dom = $this->dom();
        $root = $dom->createElementNS(self::PACKAGE_RELATIONSHIPS, 'Relationships');
        $dom->appendChild($root);

        foreach ($this->relationships as $relationshipData) {
            $relationship = $dom->createElementNS(self::PACKAGE_RELATIONSHIPS, 'Relationship');
            $relationship->setAttribute('Id', $relationshipData['id']);
            $relationship->setAttribute('Type', $relationshipData['type']);
            $relationship->setAttribute('Target', $relationshipData['target']);
            if ($relationshipData['external']) {
                $relationship->setAttribute('TargetMode', 'External');
            }
            $root->appendChild($relationship);
        }

        return $this->xml($dom);
    }

    private function addRelationship(string $type, string $target, bool $external = false): string
    {
        // Repeated links to the same URL share one relationship part.
        $existingKey = array_search(
            true,
            array_map(
                static fn (array $relationship): bool => $relationship['type'] === $type
                    && $relationship['target'] === $target
                    && $relationship['external'] === $external,
                $this->relationships,
            ),
            true,
        );

        if ($existingKey !== false) {
            return $this->relationships[$existingKey]['id'];
        }

        $id = 'rId'.$this->nextRelationshipId++;
        $this->relationships[] = [
            'id' => $id,
            'type' => $type,
            'target' => $target,
            'external' => $external,
        ];

        return $id;
    }

    /**
     * @param array<string, string> $parts
     */
    private function package(array $parts): string
    {
        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new DocxWriteException(sprintf(
                'Unable to create a temporary DOCX file in "%s".',
                $directory,
            ));
        }

        $path = @tempnam($directory, 'transmark-docx-writer-');
        if ($path === false) {
            throw new DocxWriteException(sprintf(
                'Unable to create a temporary DOCX file in "%s".',
                $directory,
            ));
        }

        $zip = null;

        try {
            $zip = new \ZipArchive();
            $status = $zip->open($path, \ZipArchive::OVERWRITE);
            if ($status !== true) {
                throw new DocxWriteException(sprintf(
                    'Unable to open temporary DOCX package (ZipArchive error code %d).',
                    $status,
                ));
            }

            foreach ($parts as $partPath => $contents) {
                if (!$zip->addFromString($partPath, $contents)) {
                    throw new DocxWriteException(sprintf('Unable to add DOCX part "%s".', $partPath));
                }
            }

            if (!$zip->close()) {
                throw new DocxWriteException('Unable to finalize the DOCX package.');
            }
            $zip = null;

            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new DocxWriteException('Unable to read the completed DOCX package.');
            }

            return $bytes;
        } catch (DocxWriteException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new DocxWriteException('Unable to create the DOCX package.', previous: $exception);
        } finally {
            if ($zip instanceof \ZipArchive) {
                $zip->close();
            }

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function dom(): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        return $dom;
    }

    private function wordElement(
        \DOMDocument $dom,
        \DOMNode $parent,
        string $name,
    ): \DOMElement {
        $element = $dom->createElementNS(self::WORD_NAMESPACE, 'w:'.$name);
        $parent->appendChild($element);

        return $element;
    }

    private function wordAttribute(\DOMElement $element, string $name, string $value): void
    {
        $element->setAttributeNS(self::WORD_NAMESPACE, 'w:'.$name, $value);
    }

    private function xml(\DOMDocument $dom): string
    {
        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new DocxWriteException('Unable to serialize a DOCX XML part.');
        }

        return $xml;
    }
}
