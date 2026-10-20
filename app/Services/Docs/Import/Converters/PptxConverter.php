<?php

declare(strict_types=1);

namespace App\Services\Docs\Import\Converters;

use App\Services\Docs\Import\ConvertedDocument;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\Docs\Import\MarkdownBlocks;
use App\Services\Docs\Import\MarkdownText;
use App\Support\Ooxml\OoxmlPackage;
use App\Support\Xml\SafeXml;
use RuntimeException;
use XMLReader;

/**
 * A PowerPoint deck (.pptx) as Markdown: a heading per slide, its title in the
 * heading, its points as a list nested by indent level, and the speaker notes
 * as a quote underneath.
 *
 * Slides are read in the order the presentation lists them, not the order of
 * their file names — a deck that was reordered keeps its new order.
 */
final class PptxConverter
{
    public function convert(string $path): ConvertedDocument
    {
        $package = OoxmlPackage::open($path);

        if ($package === null) {
            throw new DocumentImportException(
                'That presentation could not be opened. It may be corrupt, or saved in an older format — '
                .'re-saving it as .pptx will fix it.'
            );
        }

        $blocks = [];

        try {
            foreach ($this->slides($package) as $number => $slide) {
                $xml = $package->read($slide);

                if ($xml === null) {
                    continue;
                }

                [$title, $points] = $this->shapes($xml);

                $blocks[] = [
                    'type' => 'heading',
                    'level' => 2,
                    'text' => 'Slide '.($number + 1).($title !== '' ? ': '.MarkdownText::inline($title) : ''),
                ];

                foreach ($points as [$level, $text]) {
                    $blocks[] = ['type' => 'item', 'depth' => $level, 'ordered' => false, 'number' => 0, 'task' => null, 'text' => MarkdownText::inline($text)];
                }

                $notes = $this->notes($package, $slide);

                if ($notes !== '') {
                    $blocks[] = ['type' => 'quote', 'text' => '**Notes:** '.MarkdownText::inline($notes)];
                }
            }
        } catch (RuntimeException) {
            throw new DocumentImportException(
                'That presentation could not be read. It may be corrupt — opening it in PowerPoint and saving it again usually fixes this.'
            );
        } finally {
            $package->close();
        }

        return new ConvertedDocument(MarkdownBlocks::render($blocks));
    }

    /**
     * The slide parts, in presentation order.
     *
     * @return list<string>
     */
    private function slides(OoxmlPackage $package): array
    {
        $presentation = $package->read('ppt/presentation.xml');
        $relationships = $package->relationships('ppt/presentation.xml');

        $slides = [];

        if ($presentation !== null) {
            $reader = SafeXml::reader($presentation);

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sldId') {
                    // `<p:sldId id="256" r:id="rId2"/>`: both attributes are
                    // called "id", so the relationship one is read by namespace.
                    $rid = $this->relationshipId($reader);

                    if ($rid !== null && isset($relationships[$rid])) {
                        $slides[] = $relationships[$rid]['target'];
                    }
                }
            }

            $reader->close();
        }

        return $slides !== [] ? $slides : $package->matching('#^ppt/slides/slide\d+\.xml$#');
    }

    /**
     * The `r:id` attribute of the current element.
     */
    private function relationshipId(XMLReader $reader): ?string
    {
        $value = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A slide's title and its other paragraphs with their indent levels.
     *
     * @return array{0: string, 1: list<array{0: int, 1: string}>}
     */
    private function shapes(string $xml): array
    {
        $reader = SafeXml::reader($xml);

        $title = '';
        $points = [];

        $inTitle = false;
        $level = 0;
        $paragraph = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'sp':
                        $inTitle = false;
                        break;

                    case 'ph':
                        $type = (string) SafeXml::attribute($reader, 'type');
                        $inTitle = in_array($type, ['title', 'ctrTitle'], true);
                        break;

                    case 'p':
                        $paragraph = '';
                        $level = 0;
                        break;

                    case 'pPr':
                        $level = min(5, max(0, (int) SafeXml::attribute($reader, 'lvl')));
                        break;

                    case 't':
                        if ($paragraph !== null && ! $reader->isEmptyElement) {
                            $paragraph .= $reader->readString();
                        }
                        break;

                    case 'br':
                        if ($paragraph !== null) {
                            $paragraph .= ' ';
                        }
                        break;
                }

                continue;
            }

            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'p' && $paragraph !== null) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $paragraph));

                if ($text !== '') {
                    if ($inTitle) {
                        $title = trim($title.' '.$text);
                    } else {
                        $points[] = [$level, $text];
                    }
                }

                $paragraph = null;
            }
        }

        $reader->close();

        return [$title, $points];
    }

    /**
     * A slide's speaker notes, without the slide number and header
     * placeholders a notes page also carries.
     */
    private function notes(OoxmlPackage $package, string $slide): string
    {
        $target = null;

        foreach ($package->relationships($slide) as $relationship) {
            if (str_ends_with($relationship['type'], '/notesSlide')) {
                $target = $relationship['target'];
            }
        }

        $xml = $target !== null ? $package->read($target) : null;

        if ($xml === null) {
            return '';
        }

        $reader = SafeXml::reader($xml);
        $text = [];
        $body = false;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'sp') {
                $body = false;
            } elseif ($reader->localName === 'ph') {
                $body = SafeXml::attribute($reader, 'type') === 'body';
            } elseif ($reader->localName === 't' && $body && ! $reader->isEmptyElement) {
                $text[] = $reader->readString();
            }
        }

        $reader->close();

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $text)));
    }
}
