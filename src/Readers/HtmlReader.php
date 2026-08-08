<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\Exception\HtmlParseException;

final class HtmlReader implements ReaderInterface
{
    public function read(string $content): Document
    {
        if (trim($content) === '') {
            throw new HtmlParseException('Cannot parse empty HTML content.');
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$content);
        libxml_clear_errors();

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

        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
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

        return null;
    }
}
