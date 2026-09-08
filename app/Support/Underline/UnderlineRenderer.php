<?php

declare(strict_types=1);

namespace App\Support\Underline;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use Stringable;

/**
 * Renders an Underline as `<u>`.
 *
 * `<u>` rather than `<ins>`: HTML5 defines `<u>` as an unarticulated
 * annotation rendered with an underline, which is what this is, whereas
 * `<ins>` claims the text was *inserted* in a revision — a meaning nobody
 * typing Ctrl-U intends. It is also the element TipTap's Underline mark reads
 * and writes, so the editor round-trips its own output without a translation
 * step in between.
 */
final class UnderlineRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @param  Underline  $node
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        Underline::assertInstanceOf($node);

        return new HtmlElement('u', $node->data->get('attributes'), $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'underline';
    }

    /** @return array<string, scalar> */
    public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
