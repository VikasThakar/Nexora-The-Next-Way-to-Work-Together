<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

/**
 * The kinds of file a documentation page can be made from.
 *
 * Decided by the file's extension and then checked against the type its bytes
 * actually have, so a renamed executable is not read as a text file. The type
 * rules are the same prefixes the assistant's attachments use.
 *
 * The legacy Office formats are named only to be refused with a message that
 * says what to do: they are binary OLE documents rather than ZIPs of XML, and
 * reading them would mean a new parser handling untrusted binary input.
 */
enum ImportFormat: string
{
    case Markdown = 'markdown';
    case Text = 'text';
    case Csv = 'csv';
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Xlsx = 'xlsx';
    case Pptx = 'pptx';

    /**
     * The format a filename names, or null when it is not one this imports.
     */
    public static function fromFilename(string $filename): ?self
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'md', 'markdown' => self::Markdown,
            'txt', 'text', 'log' => self::Text,
            'csv', 'tsv' => self::Csv,
            'pdf' => self::Pdf,
            'docx' => self::Docx,
            'xlsx' => self::Xlsx,
            'pptx' => self::Pptx,
            default => null,
        };
    }

    /**
     * What to say about an extension this does not import.
     */
    public static function refusal(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'doc' => 'That is an older Word document (.doc). Save it as .docx in Word and import that.',
            'xls' => 'That is an older Excel workbook (.xls). Save it as .xlsx and import that.',
            'ppt' => 'That is an older PowerPoint file (.ppt). Save it as .pptx and import that.',
            '' => 'That file has no extension, so its type is unknown. Pages can be made from '.self::describe().'.',
            default => 'A .'.$extension.' file cannot be turned into a page. Pages can be made from '.self::describe().'.',
        };
    }

    /**
     * Every extension, for an `accept` attribute and for validation.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return ['md', 'markdown', 'txt', 'csv', 'tsv', 'pdf', 'docx', 'xlsx', 'pptx'];
    }

    public static function describe(): string
    {
        return 'PDF, Word (.docx), text, Markdown, CSV, Excel (.xlsx) and PowerPoint (.pptx) files';
    }

    /**
     * Does a type detected from the bytes fit this format?
     *
     * Text formats accept any `text/` type — sniffing cannot tell Markdown from
     * plain text — and the Office formats accept the generic ZIP type that
     * sniffing reports for them.
     */
    public function acceptsDetectedType(?string $mime): bool
    {
        $mime = strtolower(trim((string) $mime));

        if ($mime === '') {
            return false;
        }

        $allowed = match ($this) {
            self::Markdown, self::Text => ['text/', 'application/json', 'application/x-empty', 'inode/x-empty'],
            self::Csv => ['text/', 'application/csv', 'application/vnd.ms-excel'],
            self::Pdf => ['application/pdf'],
            self::Docx => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            self::Xlsx => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            self::Pptx => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        };

        foreach ($allowed as $type) {
            if (str_ends_with($type, '/') ? str_starts_with($mime, $type) : $mime === $type) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return match ($this) {
            self::Markdown => 'Markdown',
            self::Text => 'text',
            self::Csv => 'CSV',
            self::Pdf => 'PDF',
            self::Docx => 'Word',
            self::Xlsx => 'Excel',
            self::Pptx => 'PowerPoint',
        };
    }
}
