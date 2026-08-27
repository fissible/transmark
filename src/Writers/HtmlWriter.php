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
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\RawHtml;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingEngine;
use Fissible\Transmark\Numbering\NumberingLabelMap;
use Fissible\Transmark\Numbering\NumberingShapeClassifier;
use Fissible\Transmark\Writers\Exception\UnsupportedHtmlNodeException;

/**
 * Legal-outline paragraphs use the classes "numbered-paragraph" and
 * "legal-level-{ilvl}" so consumers can style label-bearing flat paragraphs.
 */
final class HtmlWriter implements WriterInterface
{
    public function write(Document $document): string
    {
        $labels = (new NumberingEngine())->resolve($document);

        return $this->renderBlocks(
            $document->content(),
            $document,
            (new NumberingShapeClassifier())->classify($document),
            $labels,
        );
    }

    /**
     * @param BlockInterface[] $blocks
     * @param array<int, bool> $simpleNumIds
     */
    private function renderBlocks(
        array $blocks,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $html = '';
        $count = count($blocks);

        for ($index = 0; $index < $count; ++$index) {
            $block = $blocks[$index];

            if ($block instanceof Paragraph && $this->isSimpleNumberedParagraph($block, $simpleNumIds)) {
                $paragraphs = [];

                while (
                    $index < $count
                    && $blocks[$index] instanceof Paragraph
                    && $this->isSimpleNumberedParagraph($blocks[$index], $simpleNumIds)
                ) {
                    $paragraphs[] = $blocks[$index];
                    ++$index;
                }

                $html .= $this->renderSimpleNumberedParagraphs($paragraphs, $document, $labels);
                --$index;

                continue;
            }

            $html .= $this->renderBlock($block, $document, $simpleNumIds, $labels);
        }

        return $html;
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderBlock(
        BlockInterface $block,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        if ($block instanceof Heading) {
            $tag = 'h'.$block->level();

            return sprintf('<%1$s>%2$s</%1$s>', $tag, $this->renderInlines($block->inlines()));
        }

        if ($block instanceof Paragraph) {
            // A paragraph that is exactly one RawHtml (e.g. an HTML block
            // embedded in Markdown) emits its literal content directly —
            // wrapping block-level HTML like <div> in <p> would be invalid.
            if (count($block->inlines()) === 1 && $block->inlines()[0] instanceof RawHtml) {
                return $block->inlines()[0]->content();
            }

            if ($this->isLegalNumberedParagraph($block, $simpleNumIds, $labels)) {
                return $this->renderLegalNumberedParagraph($block, $labels);
            }

            return '<p>'.$this->renderInlines($block->inlines()).'</p>';
        }

        if ($block instanceof ListNode) {
            return $this->renderStructuralList($block, $document, $simpleNumIds, $labels);
        }

        if ($block instanceof BlockQuote) {
            return '<blockquote>'.$this->renderBlocks($block->content(), $document, $simpleNumIds, $labels).'</blockquote>';
        }

        if ($block instanceof HorizontalRule) {
            return '<hr>';
        }

        if ($block instanceof CodeBlock) {
            $class = $block->language() === null ? '' : ' class="language-'.$this->escape($block->language()).'"';

            return '<pre><code'.$class.'>'.$this->escape($block->content()).'</code></pre>';
        }

        if ($block instanceof Table) {
            return $this->renderTable($block, $document, $simpleNumIds, $labels);
        }

        if ($block instanceof Image) {
            return $this->renderImageTag(
                $block->data(),
                $block->mimeType(),
                $block->src(),
                $block->alt(),
                $block->title(),
                $block->width(),
                $block->height(),
            );
        }

        throw UnsupportedHtmlNodeException::at($block);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderTable(
        Table $table,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $html = '<table>';

        $header = $table->header();
        if ($header !== null) {
            $html .= '<thead>'.$this->renderTableRow($header, 'th', $document, $simpleNumIds, $labels).'</thead>';
        }

        $html .= '<tbody>';
        foreach ($table->rows() as $row) {
            $html .= $this->renderTableRow($row, 'td', $document, $simpleNumIds, $labels);
        }
        $html .= '</tbody>';

        return $html.'</table>';
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderTableRow(
        TableRow $row,
        string $cellTag,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $html = '<tr>';
        foreach ($row->cells() as $cell) {
            $html .= $this->renderTableCell($cell, $cellTag, $document, $simpleNumIds, $labels);
        }

        return $html.'</tr>';
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderTableCell(
        TableCell $cell,
        string $tag,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $span = '';
        if ($cell->colspan() !== 1) {
            $span .= ' colspan="'.$cell->colspan().'"';
        }
        if ($cell->rowspan() !== 1) {
            $span .= ' rowspan="'.$cell->rowspan().'"';
        }

        $content = $this->renderBlocks($cell->content(), $document, $simpleNumIds, $labels);

        return sprintf('<%1$s%2$s>%3$s</%1$s>', $tag, $span, $content);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderStructuralList(
        ListNode $list,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $tag = $list->type() === ListNode::TYPE_UNORDERED ? 'ul' : 'ol';
        $start = $tag === 'ol' && $list->start() !== 1
            ? sprintf(' start="%d"', $list->start())
            : '';
        $html = sprintf('<%s%s>', $tag, $start);

        foreach ($list->items() as $item) {
            $html .= '<li>'.$this->renderListItem($item, $document, $simpleNumIds, $labels).'</li>';
        }

        return $html.sprintf('</%s>', $tag);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function renderListItem(
        ListItem $item,
        Document $document,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): string {
        $content = $item->content();
        $unwrapLeadingParagraph = isset($content[0])
            && $content[0] instanceof Paragraph
            && $this->onlyListsFollow($content);
        $html = '';

        foreach ($content as $index => $block) {
            if ($index === 0 && $unwrapLeadingParagraph) {
                $html .= $this->renderInlines($block->inlines());
            } else {
                $html .= $this->renderBlock($block, $document, $simpleNumIds, $labels);
            }
        }

        return $html;
    }

    /**
     * @param BlockInterface[] $content
     */
    private function onlyListsFollow(array $content): bool
    {
        foreach (array_slice($content, 1) as $block) {
            if (!$block instanceof ListNode) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Paragraph[] $paragraphs
     */
    private function renderSimpleNumberedParagraphs(array $paragraphs, Document $document, NumberingLabelMap $labels): string
    {
        $html = '';

        /** @var list<array{ilvl: int, numId: int, tag: string}> $stack */
        $stack = [];

        foreach ($paragraphs as $paragraph) {
            $numbering = $paragraph->numbering();
            if ($numbering === null) {
                continue;
            }

            $ilvl = $numbering->ilvl();
            $numId = $numbering->numId();
            $descriptor = $this->numberedListDescriptor(
                $document,
                $numId,
                $ilvl,
                // Word continues a numId's counter across intervening blocks;
                // when this run opens a fresh list mid-document the browser
                // must start from the engine-computed counter, not 1.
                $labels->counterFor($paragraph),
            );

            while ($stack !== [] && $stack[array_key_last($stack)]['ilvl'] > $ilvl) {
                $openList = array_pop($stack);
                $html .= '</li></'.$openList['tag'].'>';
            }

            if ($stack !== [] && $stack[array_key_last($stack)]['ilvl'] === $ilvl) {
                $openList = $stack[array_key_last($stack)];
                $html .= '</li>';

                if ($openList['numId'] !== $numId) {
                    array_pop($stack);
                    $html .= '</'.$openList['tag'].'>'.$descriptor['open'];
                    $stack[] = ['ilvl' => $ilvl, 'numId' => $numId, 'tag' => $descriptor['tag']];
                }
            } else {
                $html .= $descriptor['open'];
                $stack[] = ['ilvl' => $ilvl, 'numId' => $numId, 'tag' => $descriptor['tag']];
            }

            $html .= '<li>'.$this->renderInlines($paragraph->inlines());
        }

        while ($stack !== []) {
            $openList = array_pop($stack);
            $html .= '</li></'.$openList['tag'].'>';
        }

        return $html;
    }

    /**
     * @return array{tag: string, open: string}
     */
    private function numberedListDescriptor(Document $document, int $numId, int $ilvl, ?int $counter = null): array
    {
        $level = $document->numbering()->levelFor($numId, $ilvl);
        if ($level === null) {
            return ['tag' => 'ol', 'open' => '<ol>'];
        }

        if ($level->format() === NumberFormat::Bullet) {
            return ['tag' => 'ul', 'open' => '<ul>'];
        }

        $attributes = [];
        if ($counter !== null && $counter !== 1) {
            $attributes[] = sprintf('start="%d"', $counter);
        }

        $listStyleType = $this->listStyleType($level->format());
        if ($listStyleType !== null) {
            $attributes[] = sprintf('style="list-style-type: %s"', $listStyleType);
        }

        $attributeText = $attributes === [] ? '' : ' '.implode(' ', $attributes);

        return ['tag' => 'ol', 'open' => '<ol'.$attributeText.'>'];
    }

    private function listStyleType(NumberFormat $format): ?string
    {
        return match ($format) {
            NumberFormat::LowerLetter => 'lower-alpha',
            NumberFormat::UpperLetter => 'upper-alpha',
            NumberFormat::LowerRoman => 'lower-roman',
            NumberFormat::UpperRoman => 'upper-roman',
            default => null,
        };
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function isSimpleNumberedParagraph(Paragraph $paragraph, array $simpleNumIds): bool
    {
        $numbering = $paragraph->numbering();

        return $numbering !== null && ($simpleNumIds[$numbering->numId()] ?? false);
    }

    /**
     * @param array<int, bool> $simpleNumIds
     */
    private function isLegalNumberedParagraph(
        Paragraph $paragraph,
        array $simpleNumIds,
        NumberingLabelMap $labels,
    ): bool {
        $numbering = $paragraph->numbering();

        return $numbering !== null
            && array_key_exists($numbering->numId(), $simpleNumIds)
            && $simpleNumIds[$numbering->numId()] === false
            && $labels->labelFor($paragraph) !== null;
    }

    private function renderLegalNumberedParagraph(
        Paragraph $paragraph,
        NumberingLabelMap $labels,
    ): string {
        $numbering = $paragraph->numbering();
        $label = $labels->labelFor($paragraph);

        if ($numbering === null || $label === null) {
            return '<p>'.$this->renderInlines($paragraph->inlines()).'</p>';
        }

        $prefix = $label === '' ? '' : $this->escape($label).' ';

        return sprintf(
            '<p class="numbered-paragraph legal-level-%d">%s%s</p>',
            $numbering->ilvl(),
            $prefix,
            $this->renderInlines($paragraph->inlines()),
        );
    }

    /**
     * @param InlineInterface[] $inlines
     */
    private function renderInlines(array $inlines): string
    {
        $html = '';

        foreach ($inlines as $inline) {
            $html .= $this->renderInline($inline);
        }

        return $html;
    }

    private function renderInline(InlineInterface $inline): string
    {
        return match (true) {
            $inline instanceof Text => $this->escape($inline->content()),
            $inline instanceof Strong => '<strong>'.$this->renderInlines($inline->children()).'</strong>',
            $inline instanceof Emphasis => '<em>'.$this->renderInlines($inline->children()).'</em>',
            $inline instanceof Underline => '<u>'.$this->renderInlines($inline->children()).'</u>',
            $inline instanceof Strike => '<s>'.$this->renderInlines($inline->children()).'</s>',
            $inline instanceof Superscript => '<sup>'.$this->renderInlines($inline->children()).'</sup>',
            $inline instanceof Subscript => '<sub>'.$this->renderInlines($inline->children()).'</sub>',
            $inline instanceof Code => '<code>'.$this->escape($inline->content()).'</code>',
            $inline instanceof Link => $this->renderLink($inline),
            $inline instanceof LineBreak => '<br>',
            $inline instanceof InlineImage => $this->renderInlineImage($inline),
            $inline instanceof RawHtml => $inline->content(),
            default => throw UnsupportedHtmlNodeException::at($inline),
        };
    }

    private function renderInlineImage(InlineImage $image): string
    {
        return $this->renderImageTag(
            $image->data(),
            $image->mimeType(),
            $image->src(),
            $image->alt(),
            $image->title(),
            $image->width(),
            $image->height(),
        );
    }

    /**
     * Shared by the block Image and inline InlineImage nodes: embeds `$data`
     * as a base64 data URI when present (an embedded image, e.g. from
     * DOCX, which has no natural `src` of its own), falling back to `$src`
     * as a plain reference otherwise (e.g. an HTML-sourced <img src="...">).
     */
    private function renderImageTag(
        ?string $data,
        ?string $mimeType,
        string $src,
        string $alt,
        ?string $title,
        ?int $width,
        ?int $height,
    ): string {
        $resolvedSrc = $data !== null && $mimeType !== null
            ? 'data:'.$mimeType.';base64,'.base64_encode($data)
            : $src;

        if (!$this->isSafeImageSrc($resolvedSrc)) {
            // Degrade gracefully like renderLink: emit alt text (+ title)
            // instead of vanishing entirely. Wrap in <span> for CSS hooks.
            $fallback = $this->escape($alt);
            if ($title !== null) {
                $fallback .= ' ('.$this->escape($title).')';
            }

            return '<span class="transmark-unsafe-image">'.$fallback.'</span>';
        }

        $html = '<img src="'.$this->escape($resolvedSrc).'" alt="'.$this->escape($alt).'"';

        if ($title !== null) {
            $html .= ' title="'.$this->escape($title).'"';
        }

        if ($width !== null) {
            $html .= ' width="'.$width.'"';
        }

        if ($height !== null) {
            $html .= ' height="'.$height.'"';
        }

        return $html.'>';
    }

    private function renderLink(Link $link): string
    {
        if (!$this->isSafeLinkHref($link->href())) {
            // Unsafe scheme (javascript:, vbscript:, …): render the link
            // text without the anchor rather than emitting a live URI.
            return $this->renderInlines($link->children());
        }

        $title = $link->title() === null
            ? ''
            : ' title="'.$this->escape($link->title()).'"';

        return '<a href="'.$this->escape($link->href()).'"'.$title.'>'
            .$this->renderInlines($link->children())
            .'</a>';
    }

    /**
     * Browsers ignore ASCII control characters and whitespace when
     * resolving a URI scheme ("jav\tascript:" executes), so they are
     * stripped before the scheme check. A URI with no scheme at all is
     * relative (or a fragment) and is treated as safe.
     */
    private function uriScheme(string $uri): ?string
    {
        // Strip control chars/whitespace FIRST (browsers ignore them when
        // resolving a scheme), then locate the colon. No truncation —
        // the regex validation on the scheme part is the protection.
        $cleaned = preg_replace('/[\x00-\x20]/', '', $uri) ?? '';
        $colon = strpos($cleaned, ':');

        if ($colon === false || $colon === 0 || !preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*$/', substr($cleaned, 0, $colon))) {
            return null;
        }

        return strtolower(substr($cleaned, 0, $colon));
    }

    private function isSafeLinkHref(string $href): bool
    {
        $scheme = $this->uriScheme($href);

        return $scheme === null
            || in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    private function isSafeImageSrc(string $src): bool
    {
        $scheme = $this->uriScheme($src);

        if ($scheme === null) {
            return true;
        }

        return in_array($scheme, ['http', 'https'], true)
            || $scheme === 'data' && str_starts_with(strtolower($src), 'data:image/');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
