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

final class HtmlReader implements ReaderInterface
{
    public function read(string $content): Document
    {
        if (trim($content) === '') {
            throw new HtmlParseException('Cannot parse empty HTML content.');
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$content);
            libxml_clear_errors();
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
     * @return BlockInterface[]
     */
    private function mapBlockChildren(\DOMNode $container): array
    {
        $blocks = [];

        foreach ($container->childNodes as $child) {
            array_push($blocks, ...$this->mapBlockChild($child));
        }

        return $blocks;
    }

    /**
     * @return BlockInterface[]
     */
    private function mapBlockChild(\DOMNode $node): array
    {
        if ($node instanceof \DOMText) {
            $text = trim($node->textContent);

            return $text === '' ? [] : [new Paragraph([new Text($text)])];
        }

        if (!$node instanceof \DOMElement) {
            return [];
        }

        $tag = strtolower($node->localName);

        if (preg_match('/^h([1-6])$/', $tag, $matches) === 1) {
            return [new Heading((int) $matches[1], $this->mapInlineChildren($node))];
        }

        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            'blockquote' => new BlockQuote($this->mapBlockChildren($node)),
            'hr' => new HorizontalRule(),
            'pre' => $this->mapCodeBlock($node),
            'table' => $this->mapTable($node),
            'img' => new Image(
                $node->getAttribute('src'),
                $node->getAttribute('alt'),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            default => null,
        };

        return $block !== null ? [$block] : [];
    }

    /**
     * @return InlineInterface[]
     */
    private function mapInlineChildren(\DOMNode $container): array
    {
        $inlines = [];

        foreach ($container->childNodes as $child) {
            $inline = $this->mapInline($child);
            if ($inline !== null) {
                $inlines[] = $inline;
            }
        }

        return $inlines;
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
                if (str_starts_with($class, 'language-')) {
                    $language = substr($class, strlen('language-'));
                    break;
                }
            }
        }

        return new CodeBlock($content, $language);
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
                    if ($tr instanceof \DOMElement && strtolower($tr->localName) === 'tr') {
                        $header ??= $this->mapTableRow($tr);
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

            $cells[] = new TableCell(
                [new Paragraph($this->mapInlineChildren($child))],
                $colspan,
                $rowspan,
            );
        }

        return new TableRow($cells);
    }
}
