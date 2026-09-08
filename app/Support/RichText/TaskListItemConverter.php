<?php

declare(strict_types=1);

namespace App\Support\RichText;

use League\HTMLToMarkdown\Converter\ListItemConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Writes a checklist item back as GFM: `- [x] done`.
 *
 * Extends the library's own list-item converter rather than replacing it, so
 * nested indentation, ordered-list numbering and `start` offsets keep working
 * exactly as they did — this class only inserts the box. Registering it after
 * the default wins, because League\HTMLToMarkdown\Environment keys converters
 * by tag and the last registration for `li` replaces the earlier one.
 *
 * The state is read from `data-checked`, not from an `<input>` element:
 * App\Support\RichText\EditorHtml has already normalised both CommonMark's
 * shape and TipTap's onto that one attribute, so there is a single thing to
 * read here rather than two shapes to sniff.
 */
class TaskListItemConverter extends ListItemConverter
{
    public function convert(ElementInterface $element): string
    {
        $markdown = parent::convert($element);

        // getAttribute returns '' for an absent attribute, which is how an
        // ordinary bullet is told apart from an unchecked task item.
        $checked = $element->getAttribute('data-checked');

        if ($checked === '') {
            return $markdown;
        }

        $box = $checked === 'true' ? '[x] ' : '[ ] ';

        /*
         * Inserted after the bullet the parent produced, whatever it happens to
         * be: the marker character is configurable, the parent may indent a
         * nested item, and it prefixes a blank line before the first item of a
         * nested list. Matching its output instead of rebuilding the line keeps
         * all of that intact.
         */
        return (string) preg_replace(
            '/^(\n*[ \t]*(?:[-*+]|\d+\.)[ \t])/',
            '$1'.$box,
            $markdown,
            1
        );
    }
}
