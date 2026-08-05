<?php

declare(strict_types=1);

/**
 * Build-vs-buy spike for MarkdownReader (Issue #8 candidate): parse
 * markdown using league/commonmark's MarkdownParser directly (bypassing
 * its HtmlRenderer entirely) and walk the resulting AST, to confirm we can
 * map it onto Transmark's own Block/Inline node taxonomy without ever
 * touching CommonMark's HTML output.
 */

require __DIR__.'/vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

$environment = new Environment();
$environment->addExtension(new CommonMarkCoreExtension());

$parser = new MarkdownParser($environment);

$markdown = <<<MD
# Sample Agreement

1. Term of Agreement
    1. Initial Term
    2. Renewal
2. Termination

- plain bullet
- **bold** and *emphasis*
MD;

$document = $parser->parse($markdown);

function describe(Node $node, int $depth = 0): void
{
    $indent = str_repeat('  ', $depth);
    $type = $node::class;

    $extra = match (true) {
        $node instanceof Document => '',
        $node instanceof Heading => " level={$node->getLevel()}",
        $node instanceof ListBlock => ' type='.$node->getListData()->type." start=".($node->getListData()->start ?? 'null'),
        $node instanceof ListItem => '',
        $node instanceof Paragraph => '',
        $node instanceof Text => ' text='.json_encode($node->getLiteral()),
        $node instanceof Strong, $node instanceof Emphasis => '',
        default => ' ('.get_class($node).')',
    };

    printf("%s%s%s\n", $indent, substr($type, strrpos($type, '\\') + 1), $extra);

    foreach ($node->children() as $child) {
        describe($child, $depth + 1);
    }
}

describe($document);

echo "\n--- Nesting depth check for ordered list ilvl (does CommonMark expose ilvl directly?) ---\n";
// CommonMark's AST is a true tree (ListBlock > ListItem > ...), unlike
// Word's flat numId/ilvl model — depth is implicit in tree nesting, there
// is no ilvl integer anywhere in the node. Confirms: Markdown's simple
// lists map onto Transmark's ListNode/ListItem tree structure, NOT
// NumberingRef{numId,ilvl} — exactly per the locked design decision.
var_dump($document->children()[1] instanceof ListBlock);
