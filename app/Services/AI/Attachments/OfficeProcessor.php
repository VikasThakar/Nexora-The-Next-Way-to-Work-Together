<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\NormalisesText;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use Throwable;
use ZipArchive;

/**
 * Office documents: .docx, .xlsx, .pptx.
 *
 * "Common document formats where safely supported" — and the safety is the
 * interesting half of that sentence, so it is worth being explicit about what
 * this does and does not touch.
 *
 * An OOXML file is a ZIP of XML parts. The text is read by opening the archive,
 * pulling out the specific parts that hold prose, and stripping the tags. What
 * is *not* touched is everything that makes these formats dangerous:
 *
 *   - no macro storage is read. A .docm or .xlsm is not in the allow-list at
 *     all, and even here `vbaProject.bin` is never opened;
 *   - no external references are followed. Fields, OLE links and remote images
 *     are markup we drop, not URLs we fetch;
 *   - nothing is executed, and no external converter is invoked. There is no
 *     LibreOffice process, so there is no LibreOffice attack surface;
 *   - XML is parsed with a regular expression rather than an XML parser, which
 *     is the one place in this codebase where that is the *safer* choice: it
 *     cannot resolve an external entity, so the whole XXE class of attack
 *     against an uploaded document does not arise.
 *
 * Entry limits are enforced while reading, which is what stops a zip bomb: a
 * few kilobytes of archive that expands to gigabytes of XML is trivial to
 * produce, and the defence is to stop reading, not to detect it.
 *
 * The legacy formats — .doc, .xls, .ppt — are deliberately absent. They are
 * OLE compound documents rather than ZIPs, reading them means a real parser,
 * and that parser would be a new dependency handling untrusted binary input.
 * They are refused at upload with a message that says to save as the modern
 * format, which takes one keystroke.
 */
class OfficeProcessor implements AiAttachmentProcessor
{
    use NormalisesText;
    use ReadsStoredFiles;

    /** Uncompressed bytes read from one archive, at most. The zip-bomb bound. */
    private const MAX_UNCOMPRESSED_BYTES = 40 * 1024 * 1024;

    /** Parts read from one archive, at most: a slide deck is one part per slide. */
    private const MAX_PARTS = 400;

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Document;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        if (! class_exists(ZipArchive::class)) {
            return AiAttachmentExtraction::unsupported(
                'Reading Office documents needs the PHP zip extension, which this '
                .'deployment does not have. Attaching the file as a PDF works instead.'
            );
        }

        $extension = strtolower(pathinfo($attachment->filename ?? '', PATHINFO_EXTENSION));

        $result = $this->withLocalCopy($attachment, function (string $path) use ($extension): ?array {
            return $this->read($path, $extension);
        });

        if ($result === null) {
            return AiAttachmentExtraction::failed(
                'That document could not be opened. It may be corrupt, or saved in an older '
                .'format — re-saving it as .docx, .xlsx or .pptx will fix it.'
            );
        }

        [$text, $parts] = $result;

        $text = $this->normalise($text);

        if (trim($text) === '') {
            return AiAttachmentExtraction::ready(
                summary: 'No text found',
                structured: ['parts' => $parts],
            );
        }

        [$text, $truncated] = $this->truncate($text);

        $words = str_word_count($text);

        return AiAttachmentExtraction::ready(
            text: $text,
            structured: ['parts' => $parts, 'words' => $words],
            summary: number_format($words).' '.($words === 1 ? 'word' : 'words')
                .($parts > 1 ? ', '.$parts.' '.$this->partNoun($extension, $parts) : ''),
            truncated: $truncated,
        );
    }

    // -----------------------------------------------------------------

    /**
     * Open the archive and read the parts that hold prose.
     *
     * @return array{0: string, 1: int}|null the text and how many parts it came
     *                                       from, or null if it could not be
     *                                       opened at all
     */
    private function read(string $path, string $extension): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $names = $this->partsFor($zip, $extension);

            if ($names === []) {
                return null;
            }

            $chunks = [];
            $bytes = 0;
            $parts = 0;

            foreach ($names as $name) {
                if ($parts >= self::MAX_PARTS || $bytes >= self::MAX_UNCOMPRESSED_BYTES) {
                    break;
                }

                /*
                 * The declared uncompressed size, checked *before* reading.
                 *
                 * This is the zip-bomb check and it has to come first: asking
                 * for the contents of an entry that claims to be 8 GB is how
                 * the worker dies, and the claim is available for free from the
                 * archive index.
                 */
                $stat = $zip->statName($name);
                $size = (int) ($stat['size'] ?? 0);

                if ($size <= 0 || $bytes + $size > self::MAX_UNCOMPRESSED_BYTES) {
                    continue;
                }

                $xml = $zip->getFromName($name);

                if (! is_string($xml) || $xml === '') {
                    continue;
                }

                $bytes += strlen($xml);
                $parts++;

                $text = $this->textFromXml($xml);

                if (trim($text) !== '') {
                    $chunks[] = $text;
                }
            }

            return [implode("\n\n", $chunks), $parts];
        } catch (Throwable) {
            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Which entries hold the text, for each format.
     *
     * Named explicitly rather than "every .xml in the archive": the archive
     * also contains settings, themes, relationships and revision metadata, and
     * reading those would fill a prompt with markup nobody asked about.
     *
     * @return list<string>
     */
    private function partsFor(ZipArchive $zip, string $extension): array
    {
        if ($extension === 'docx') {
            return array_values(array_filter(
                ['word/document.xml', 'word/footnotes.xml', 'word/endnotes.xml'],
                static fn (string $name): bool => $zip->locateName($name) !== false
            ));
        }

        if ($extension === 'xlsx') {
            // The shared string table holds every text cell in the workbook,
            // and the sheets hold the numbers. Both are wanted.
            $names = array_values(array_filter(
                ['xl/sharedStrings.xml'],
                static fn (string $name): bool => $zip->locateName($name) !== false
            ));

            return array_merge($names, $this->matching($zip, '#^xl/worksheets/sheet\d+\.xml$#'));
        }

        if ($extension === 'pptx') {
            return array_merge(
                $this->matching($zip, '#^ppt/slides/slide\d+\.xml$#'),
                $this->matching($zip, '#^ppt/notesSlides/notesSlide\d+\.xml$#'),
            );
        }

        return [];
    }

    /**
     * Archive entries whose names match a pattern, in numeric order.
     *
     * Sorted naturally, because `slide10.xml` sorts before `slide2.xml`
     * alphabetically and a deck read in that order reads like a deck somebody
     * shuffled.
     *
     * @return list<string>
     */
    private function matching(ZipArchive $zip, string $pattern): array
    {
        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name) && preg_match($pattern, $name) === 1) {
                $names[] = $name;
            }
        }

        natsort($names);

        return array_values($names);
    }

    /**
     * Strip the markup, keeping the breaks that carry meaning.
     *
     * Deliberately a regular expression and not DOMDocument or SimpleXML. Both
     * of those resolve entities, and an uploaded document that declares an
     * external entity is the classic XXE — a file that reads /etc/passwd or
     * makes the server fetch a URL. There is no configuration of a regular
     * expression that does either.
     *
     * The trade-off is that this cannot understand nesting, which for
     * extracting prose is not something it needs to.
     */
    private function textFromXml(string $xml): string
    {
        // Paragraph, line-break, table-row and cell boundaries become newlines
        // and tabs before the tags go, or a table would come out as one long
        // run of words.
        $xml = (string) preg_replace(
            '#</(w:p|a:p|w:tr|row|text:p)>#',
            "\n",
            $xml
        );

        $xml = (string) preg_replace('#<(w:br|w:cr|a:br)\s*/?>#', "\n", $xml);
        $xml = (string) preg_replace('#</(w:tc|c)>#', "\t", $xml);

        // Everything else.
        $text = (string) preg_replace('#<[^>]*>#', '', $xml);

        /*
         * Only the five predefined XML entities are decoded, and nothing else.
         *
         * html_entity_decode with ENT_XML1 does not resolve a DOCTYPE-declared
         * entity — it has no document to resolve one against — so a declared
         * external entity survives as literal text rather than being fetched.
         */
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

        return trim($text);
    }

    private function partNoun(string $extension, int $count): string
    {
        return match ($extension) {
            'pptx' => $count === 1 ? 'slide' : 'slides',
            'xlsx' => $count === 1 ? 'sheet' : 'sheets',
            default => $count === 1 ? 'part' : 'parts',
        };
    }
}
