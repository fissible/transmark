<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers\Exception;

/**
 * Thrown when HTML content cannot be mapped into the canonical Document
 * tree: no parsable content was found, or an element has no representable
 * node-taxonomy target (forms, embeds, custom elements).
 */
final class HtmlParseException extends \RuntimeException
{
}
