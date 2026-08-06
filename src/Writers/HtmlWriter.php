<?php

declare(strict_types=1);

namespace Fissible\Transmark\Writers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
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

                $html .= $this->renderSimpleNumberedParagraphs($paragraphs, $document);
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
            if ($this->isLegalNumberedParagraph($block, $simpleNumIds, $labels)) {
                return $this->renderLegalNumberedParagraph($block, $labels);
            }

            return '<p>'.$this->renderInlines($block->inlines()).'</p>';
        }

        if ($block instanceof ListNode) {
            return $this->renderStructuralList($block, $document, $simpleNumIds, $labels);
        }

        return '';
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
    private function renderSimpleNumberedParagraphs(array $paragraphs, Document $document): string
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
            $descriptor = $this->numberedListDescriptor($document, $numId, $ilvl);

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
    private function numberedListDescriptor(Document $document, int $numId, int $ilvl): array
    {
        $level = $document->numbering()->levelFor($numId, $ilvl);
        if ($level === null) {
            return ['tag' => 'ol', 'open' => '<ol>'];
        }

        if ($level->format() === NumberFormat::Bullet) {
            return ['tag' => 'ul', 'open' => '<ul>'];
        }

        $attributes = [];
        $num = $document->numbering()->num($numId);
        $levelOverrides = $num?->levelOverrides() ?? [];
        $start = $levelOverrides[$ilvl] ?? $level->start();

        if ($start !== 1) {
            $attributes[] = sprintf('start="%d"', $start);
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
            default => '',
        };
    }

    private function renderLink(Link $link): string
    {
        $title = $link->title() === null
            ? ''
            : ' title="'.$this->escape($link->title()).'"';

        return '<a href="'.$this->escape($link->href()).'"'.$title.'>'
            .$this->renderInlines($link->children())
            .'</a>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
