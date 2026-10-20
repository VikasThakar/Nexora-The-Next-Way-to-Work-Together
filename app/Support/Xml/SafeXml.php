<?php

declare(strict_types=1);

namespace App\Support\Xml;

use RuntimeException;
use XMLReader;

/**
 * An XML reader for documents somebody uploaded.
 *
 * The parts inside a .docx, .xlsx or .pptx are XML, and XML is where the XXE
 * family lives: a document that declares an external entity can make a parser
 * read a server file or fetch a URL. Three things close that here.
 *
 *   - A part that declares a document type is refused outright. Real Office
 *     parts never carry a DOCTYPE, so refusing one costs nothing and removes
 *     the only place an entity can be declared.
 *   - The reader is opened with LIBXML_NONET, so nothing is fetched from the
 *     network whatever the document says, and without LIBXML_NOENT or
 *     LIBXML_DTDLOAD, so no entity is substituted and no DTD is loaded.
 *   - It streams (XMLReader rather than DOMDocument), so a 40 MB part costs a
 *     few kilobytes of memory instead of a tree ten times its size — which is
 *     the difference between reading a large report and exhausting the worker.
 *
 * App\Services\AI\Attachments\OfficeProcessor used to strip tags with a regular
 * expression for the first reason alone. Structure — headings, lists, tables,
 * checkboxes — needs a real parser, so this is that parser, made safe.
 */
final class SafeXml
{
    /**
     * A reader positioned before the first node of `$xml`.
     *
     * @throws RuntimeException when the document declares a type, or cannot be
     *                          opened at all
     */
    public static function reader(string $xml): XMLReader
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new RuntimeException('The document declares a DOCTYPE, which an Office part never does.');
        }

        $reader = new XMLReader;

        if (! $reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            throw new RuntimeException('The document is not well-formed XML.');
        }

        // Belt and braces: the defaults already say no to both.
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        return $reader;
    }

    /**
     * An attribute in any namespace by its local name, or null.
     *
     * Office documents write the same attribute as `w:val`, `w14:val` or bare
     * `val` depending on the element; the local name is what matters.
     */
    public static function attribute(XMLReader $reader, string $localName): ?string
    {
        if (! $reader->hasAttributes) {
            return null;
        }

        $value = null;

        while ($reader->moveToNextAttribute()) {
            if ($reader->localName === $localName) {
                $value = $reader->value;

                break;
            }
        }

        $reader->moveToElement();

        return $value;
    }
}
