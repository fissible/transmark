<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\ReaderInterface;
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
use Fissible\Transmark\Readers\Exception\HtmlParseException;

/**
 * Reads arbitrary, real-world HTML into the canonical Document tree.
 *
 * Best-effort by design, with a hard failure mode rather than a silently
 * lossy one. Three fixed policies govern every element encountered, in both
 * block and inline position:
 *
 * - **Strip silently:** scaffolding that is not reader-visible content
 *   (`script`, `style`, `head`, `meta`, `link`, `title`, `noscript`) and HTML
 *   comments. No node is emitted and no error is raised.
 * - **Unwrap transparently:** any other unrecognized element (`div`,
 *   `section`, `article`, ...). No node is emitted for the container itself,
 *   but its children are walked and spliced into the parent's content, so
 *   nothing is lost.
 * - **Throw `HtmlParseException`:** genuinely unmappable content — forms,
 *   embeds and media (`form`, `button`, `iframe`, `svg`, `video`, ...) and any
 *   custom element (a tag name containing a hyphen). These have no
 *   representable target in the node taxonomy, so failing loudly lets the
 *   caller find and replace them instead of losing their content.
 *
 * Consecutive inline-level siblings of a block container (bare text, and any
 * tag in the closed `INLINE_TAGS` phrasing-content list — `a`, `strong`,
 * `ins`, `font`, ...) coalesce into a single `Paragraph`, so
 * `<li>text with <a href="...">a link</a></li>` reads as one paragraph
 * rather than a fragmented sequence. A `<p>`/heading's own content is
 * edge-trimmed the same way, so pretty-printed markup like
 * `<p>\n  Hello\n</p>` reads as `'Hello'`, not `"\n  Hello\n"` — interior
 * spacing between inline siblings is untouched either way. `NumberingRef` is
 * never emitted: visually numbered HTML is read as plain
 * `Paragraph`/`ListNode` content.
 */
final class HtmlReader implements ReaderInterface
{
    private const STRIP_TAGS = ['script', 'style', 'head', 'meta', 'link', 'title', 'noscript'];

    private const DENY_TAGS = [
        'form', 'input', 'button', 'select', 'textarea', 'canvas', 'svg', 'iframe',
        'video', 'audio', 'object', 'embed', 'map', 'area',
    ];

    /**
     * Tags treated as inline-level when they appear as a direct child of a
     * block container, so that a run of them coalesces into one Paragraph.
     * This is the closed set of standard (and common legacy/obsolete)
     * HTML phrasing-content elements; anything outside it is treated as an
     * unrecognized structural container instead. `img` is deliberately
     * excluded: at block position it maps to the block `Image` node, not to
     * `InlineImage`, so joining it to a run would change that mapping.
     */
    private const INLINE_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'sub', 'sup', 'code',
        'span', 'br', 'small', 'mark', 'abbr', 'time', 'cite', 'q', 'kbd', 'samp',
        'var', 'dfn', 'ins', 'font', 'tt', 'big', 'bdi', 'bdo', 'data', 'label',
        'output', 'ruby', 'nobr', 'wbr',
    ];

    /**
     * @throws HtmlParseException when the content is empty, has no <body>,
     *                            yields no blocks, or contains an element with
     *                            no representable content model
     */
    public function read(string $content): Document
    {
        if (trim($content) === '') {
            throw new HtmlParseException('Cannot parse empty HTML content.');
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$content);

            // Only clear the buffer when this call is what populated it. If the
            // caller already had internal error collection enabled, the buffer
            // is theirs and may hold errors they intend to read.
            if ($previous === false) {
                libxml_clear_errors();
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        if (!$body instanceof \DOMElement) {
            throw new HtmlParseException('The HTML content has no <body> to read.');
        }

        $blocks = $this->mapBlockChildren($body);

        if ($blocks === []) {
            throw new HtmlParseException('No parsable content was found in the HTML.');
        }

        return new Document($blocks);
    }

    /**
     * Walks a block container, coalescing every run of consecutive
     * inline-level siblings (text nodes and inline elements) into a single
     * Paragraph instead of emitting one Paragraph per sibling.
     *
     * @return BlockInterface[]
     */
    private function mapBlockChildren(\DOMNode $container): array
    {
        $blocks = [];
        /** @var \DOMNode[] $run inline-level nodes buffered for the current paragraph */
        $run = [];
        /** @var \DOMNode[] $pending whitespace-only text nodes held until we know whether the run continues */
        $pending = [];

        foreach ($container->childNodes as $child) {
            if ($this->isWhitespaceText($child)) {
                if ($run !== []) {
                    $pending[] = $child;
                }

                continue;
            }

            if ($this->isInlineLevel($child)) {
                array_push($run, ...$pending);
                $pending = [];
                $run[] = $child;

                continue;
            }

            array_push($blocks, ...$this->flushInlineRun($run));
            $run = [];
            $pending = [];

            array_push($blocks, ...$this->mapBlockChild($child));
        }

        array_push($blocks, ...$this->flushInlineRun($run));

        return $blocks;
    }

    /**
     * True for nodes that participate in an inline run at block position.
     * Whitespace-only text is handled separately by the caller.
     */
    private function isInlineLevel(\DOMNode $node): bool
    {
        if ($node instanceof \DOMText) {
            return true;
        }

        if (!$node instanceof \DOMElement) {
            return false;
        }

        return in_array(strtolower($node->localName), self::INLINE_TAGS, true);
    }

    private function isWhitespaceText(\DOMNode $node): bool
    {
        return $node instanceof \DOMText && trim($node->textContent) === '';
    }

    /**
     * Turns a buffered run of inline-level nodes into at most one Paragraph.
     *
     * @param \DOMNode[] $run
     *
     * @return BlockInterface[]
     */
    private function flushInlineRun(array $run): array
    {
        if ($run === []) {
            return [];
        }

        $inlines = [];

        foreach ($run as $node) {
            array_push($inlines, ...$this->mapInlineChild($node));
        }

        $inlines = $this->trimInlineEdges($inlines);

        return $inlines === [] ? [] : [new Paragraph($inlines)];
    }

    /**
     * Trims leading/trailing whitespace off the paragraph as a whole rather
     * than off every individual piece, so inter-word spacing at the boundary
     * between a text run and an adjacent inline element survives.
     *
     * @param InlineInterface[] $inlines
     *
     * @return InlineInterface[]
     */
    private function trimInlineEdges(array $inlines): array
    {
        $inlines = array_values($inlines);

        while ($inlines !== []) {
            $first = $inlines[0];
            if (!$first instanceof Text) {
                break;
            }

            $trimmed = ltrim($first->content());
            if ($trimmed === '') {
                array_shift($inlines);

                continue;
            }

            $inlines[0] = new Text($trimmed);
            break;
        }

        while ($inlines !== []) {
            $last = end($inlines);
            $lastIndex = array_key_last($inlines);
            if (!$last instanceof Text || $lastIndex === null) {
                break;
            }

            $trimmed = rtrim($last->content());
            if ($trimmed === '') {
                array_pop($inlines);

                continue;
            }

            $inlines[$lastIndex] = new Text($trimmed);
            break;
        }

        return $inlines;
    }

    /**
     * Maps a single node at block position. Kept total over every node kind:
     * mapBlockChildren normally coalesces inline-level nodes before they reach
     * here, but this method must stay independently correct for any node.
     *
     * @return BlockInterface[]
     */
    private function mapBlockChild(\DOMNode $node): array
    {
        if ($node instanceof \DOMText) {
            return $this->flushInlineRun([$node]);
        }

        if (!$node instanceof \DOMElement) {
            return [];
        }

        $tag = strtolower($node->localName);

        if (preg_match('/^h([1-6])$/', $tag, $matches) === 1) {
            return [new Heading((int) $matches[1], $this->trimInlineEdges($this->mapInlineChildren($node)))];
        }

        // A table can contribute a caption Paragraph alongside the Table node,
        // so it is handled outside the single-node match below.
        if ($tag === 'table') {
            return $this->mapTableBlocks($node);
        }

        $block = match ($tag) {
            'p' => new Paragraph($this->trimInlineEdges($this->mapInlineChildren($node))),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            'blockquote' => new BlockQuote($this->mapBlockChildren($node)),
            'hr' => new HorizontalRule(),
            'pre' => $this->mapCodeBlock($node),
            'img' => new Image(
                $node->getAttribute('src'),
                $node->getAttribute('alt'),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            default => null,
        };

        if ($block !== null) {
            return [$block];
        }

        if (in_array($tag, self::STRIP_TAGS, true)) {
            return [];
        }

        if (in_array($tag, self::DENY_TAGS, true) || str_contains($tag, '-')) {
            throw new HtmlParseException(sprintf(
                'Cannot parse <%s>: no representable content model for this element.',
                $tag,
            ));
        }

        if (in_array($tag, self::INLINE_TAGS, true)) {
            $inlines = $this->mapInlineChild($node);

            return $inlines === [] ? [] : [new Paragraph($inlines)];
        }

        // Unrecognized structural container (div/section/article/...): pass its
        // content through transparently instead of inventing a node for it.
        return $this->mapBlockChildren($node);
    }

    /**
     * @return InlineInterface[]
     */
    private function mapInlineChildren(\DOMNode $container): array
    {
        $inlines = [];

        foreach ($container->childNodes as $child) {
            array_push($inlines, ...$this->mapInlineChild($child));
        }

        return $inlines;
    }

    /**
     * The inline-position mirror of mapBlockChild: applies the same three-way
     * strip / throw / unwrap policy so that no inline content is silently
     * dropped and no unmappable element silently vanishes.
     *
     * @return InlineInterface[]
     */
    private function mapInlineChild(\DOMNode $node): array
    {
        $inline = $this->mapInline($node);

        if ($inline !== null) {
            return [$inline];
        }

        // Text nodes are fully handled by mapInline; comments, processing
        // instructions and the like carry no inline content.
        if (!$node instanceof \DOMElement) {
            return [];
        }

        $tag = strtolower($node->localName);

        if (in_array($tag, self::STRIP_TAGS, true)) {
            return [];
        }

        if (in_array($tag, self::DENY_TAGS, true) || str_contains($tag, '-')) {
            throw new HtmlParseException(sprintf(
                'Cannot parse <%s>: no representable content model for this element.',
                $tag,
            ));
        }

        // Unrecognized inline wrapper (span/mark/ins/font/...): splice its
        // children into the parent list instead of inventing a node for it.
        return $this->mapInlineChildren($node);
    }

    private function mapInline(\DOMNode $node): ?InlineInterface
    {
        if ($node instanceof \DOMText) {
            return $node->textContent === '' ? null : new Text($node->textContent);
        }

        if (!$node instanceof \DOMElement) {
            return null;
        }

        $tag = strtolower($node->localName);

        return match ($tag) {
            'strong', 'b' => new Strong($this->mapInlineChildren($node)),
            'em', 'i' => new Emphasis($this->mapInlineChildren($node)),
            'u' => new Underline($this->mapInlineChildren($node)),
            's', 'strike', 'del' => new Strike($this->mapInlineChildren($node)),
            'sub' => new Subscript($this->mapInlineChildren($node)),
            'sup' => new Superscript($this->mapInlineChildren($node)),
            'code' => new Code($node->textContent),
            'a' => new Link(
                $node->getAttribute('href'),
                $this->mapInlineChildren($node),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            'br' => new LineBreak(),
            'img' => new InlineImage(
                $node->getAttribute('src'),
                $node->getAttribute('alt'),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            default => null,
        };
    }

    private function mapList(\DOMElement $node, string $type): ListNode
    {
        $items = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->localName) === 'li') {
                $items[] = new ListItem($this->mapBlockChildren($child));
            }
        }

        $start = $node->hasAttribute('start') ? (int) $node->getAttribute('start') : 1;

        return new ListNode($type, $items, $start);
    }

    private function mapCodeBlock(\DOMElement $node): CodeBlock
    {
        $codeElement = null;

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->localName) === 'code') {
                $codeElement = $child;
                break;
            }
        }

        $content = $codeElement !== null ? $codeElement->textContent : $node->textContent;
        $language = null;

        if ($codeElement !== null) {
            foreach (explode(' ', $codeElement->getAttribute('class')) as $class) {
                if (!str_starts_with($class, 'language-')) {
                    continue;
                }

                // A bare "language-" prefix carries no language; treat it as none.
                $suffix = substr($class, strlen('language-'));
                if ($suffix !== '') {
                    $language = $suffix;
                    break;
                }
            }
        }

        return new CodeBlock($content, $language);
    }

    /**
     * The Table node has no caption slot, so a <caption> is emitted as a
     * Paragraph sibling immediately before the table.
     *
     * @return BlockInterface[]
     */
    private function mapTableBlocks(\DOMElement $node): array
    {
        $blocks = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->localName) === 'caption') {
                $inlines = $this->trimInlineEdges($this->mapInlineChildren($child));
                if ($inlines !== []) {
                    $blocks[] = new Paragraph($inlines);
                }

                break;
            }
        }

        $blocks[] = $this->mapTable($node);

        return $blocks;
    }

    private function mapTable(\DOMElement $node): Table
    {
        $header = null;
        $rows = [];

        foreach ($node->childNodes as $section) {
            if (!$section instanceof \DOMElement) {
                continue;
            }

            $sectionTag = strtolower($section->localName);

            if ($sectionTag === 'thead') {
                foreach ($section->childNodes as $tr) {
                    if (!$tr instanceof \DOMElement || strtolower($tr->localName) !== 'tr') {
                        continue;
                    }

                    // Only the first thead row can be the header; keep any
                    // further ones as ordinary rows rather than dropping them.
                    $row = $this->mapTableRow($tr);
                    if ($header === null) {
                        $header = $row;
                    } else {
                        $rows[] = $row;
                    }
                }
            } elseif ($sectionTag === 'tbody' || $sectionTag === 'tfoot') {
                foreach ($section->childNodes as $tr) {
                    if ($tr instanceof \DOMElement && strtolower($tr->localName) === 'tr') {
                        $rows[] = $this->mapTableRow($tr);
                    }
                }
            } elseif ($sectionTag === 'tr') {
                $rows[] = $this->mapTableRow($section);
            }
        }

        return new Table($rows, $header);
    }

    private function mapTableRow(\DOMElement $node): TableRow
    {
        $cells = [];

        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->localName);
            if ($tag !== 'td' && $tag !== 'th') {
                continue;
            }

            $colspan = $child->hasAttribute('colspan') ? max(1, (int) $child->getAttribute('colspan')) : 1;
            $rowspan = $child->hasAttribute('rowspan') ? max(1, (int) $child->getAttribute('rowspan')) : 1;

            // Real-world cells usually wrap their content in block elements
            // (<td><p>...</p></td>); map those first and only fall back to
            // inline-in-a-paragraph for genuinely inline-only cell content.
            $content = $this->mapBlockChildren($child);
            if ($content === []) {
                $content = [new Paragraph($this->mapInlineChildren($child))];
            }

            $cells[] = new TableCell($content, $colspan, $rowspan);
        }

        return new TableRow($cells);
    }
}
