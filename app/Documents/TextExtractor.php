<?php

namespace App\Documents;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * FUN-8: gets the words out of a document file so it can be searched.
 *
 *  - Plain text: read as is (converted to UTF-8).
 *  - Word (.docx) and Excel (.xlsx): read from the file's own XML, no external tools.
 *  - PDF: `pdftotext`; a PDF with (almost) no text layer is a scan, so its pages are rendered (`pdftoppm`) and read by OCR.
 *  - Images (PNG, JPEG): OCR with `tesseract`.
 *  - Legacy .doc/.xls: not supported (they need a converter); marked so, never an error.
 *
 * Never throws for a bad or unreadable file: the result says what happened.
 */
class TextExtractor
{
    /** Fewer characters than this from a PDF's text layer means it is a scan. */
    private const SCAN_THRESHOLD = 20;

    /** @return array{state: string, method: ?string, content: ?string, error: ?string} */
    public function extract(string $path, string $mime, string $extension): array
    {
        $ext = strtolower($extension);
        try {
            return match (true) {
                $mime === 'text/plain' || $ext === 'txt' => $this->done('text', $this->utf8((string) file_get_contents($path))),
                $mime === 'application/pdf' || $ext === 'pdf' => $this->pdf($path),
                $ext === 'docx' || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->done('docx', $this->docx($path)),
                $ext === 'xlsx' || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $this->done('xlsx', $this->xlsx($path)),
                str_starts_with($mime, 'image/') || in_array($ext, ['png', 'jpg', 'jpeg'], true) => $this->ocrImage($path),
                default => ['state' => 'unsupported', 'method' => null, 'content' => null, 'error' => "Text cannot be read from .{$ext} files."],
            };
        } catch (\Throwable $e) {
            return ['state' => 'failed', 'method' => null, 'content' => null, 'error' => Str::limit($e->getMessage(), 480)];
        }
    }

    private function pdf(string $path): array
    {
        $run = Process::timeout($this->timeout())->run([config('documents.text.pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-']);
        $text = $run->successful() ? $this->utf8($run->output()) : '';
        if (mb_strlen(trim($text)) >= self::SCAN_THRESHOLD) {
            return $this->done('pdf', $text);
        }
        if (! $this->ocrOn()) {
            return $this->none($run->successful() ? 'No text layer (a scan); OCR is turned off.' : 'pdftotext is not available: '.Str::limit(trim($run->errorOutput()), 200));
        }

        // a scan: render the pages and read them
        $dir = storage_path('app/tmp/ocr-'.Str::random(16));
        File::ensureDirectoryExists($dir);
        try {
            $render = Process::timeout($this->timeout())->run([config('documents.text.pdftoppm'), '-r', '200', '-png', '-l', (string) config('documents.ocr.max_pages'), $path, $dir.'/page']);
            if (! $render->successful()) {
                return $this->none('The scan could not be rendered for OCR (pdftoppm): '.Str::limit(trim($render->errorOutput()), 200));
            }
            $pages = collect(File::files($dir))->map(fn ($f) => $f->getPathname())->sort()->values();
            $text = $pages->map(fn ($p) => $this->tesseract($p))->implode("\n\f");

            return trim($text) === '' ? $this->none('OCR found no text.') : $this->done('ocr', $text);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function ocrImage(string $path): array
    {
        if (! $this->ocrOn()) {
            return $this->none('OCR is turned off.');
        }
        $text = $this->tesseract($path);

        return trim($text) === '' ? $this->none('OCR found no text.') : $this->done('ocr', $text);
    }

    private function tesseract(string $image): string
    {
        $run = Process::timeout($this->timeout())->run([config('documents.ocr.tesseract'), $image, 'stdout', '-l', (string) config('documents.ocr.languages')]);
        if (! $run->successful()) {
            throw new \RuntimeException('OCR (tesseract) failed: '.Str::limit(trim($run->errorOutput()), 300));
        }

        return $this->utf8($run->output());
    }

    private function docx(string $path): string
    {
        $xml = $this->zipEntry($path, 'word/document.xml');
        // paragraph and line breaks become new lines, table cells spaces, before the tags go
        $xml = preg_replace(['#</w:p>#', '#<w:br\s*/>#', '#<w:tab\s*/>#', '#</w:tc>#'], ["\n", "\n", "\t", ' '], $xml);

        return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function xlsx(string $path): string
    {
        $out = [];
        $shared = $this->zipEntry($path, 'xl/sharedStrings.xml', false);
        if ($shared !== '') {
            preg_match_all('#<si>(.*?)</si>#s', $shared, $m);
            foreach ($m[1] as $si) {
                $out[] = html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        // inline strings and numbers live in the sheets themselves
        $zip = new ZipArchive;
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    preg_match_all('#<is>(.*?)</is>|<v>([^<]*)</v>#s', (string) $zip->getFromIndex($i), $cells);
                    foreach ($cells[0] as $k => $_) {
                        $v = $cells[1][$k] !== '' ? strip_tags($cells[1][$k]) : $cells[2][$k];
                        // shared-string indexes are already above; keep inline text and non-index numbers
                        if ($cells[1][$k] !== '' || ! ctype_digit($v)) {
                            $out[] = html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                }
            }
            $zip->close();
        }

        return implode("\n", array_filter($out, fn ($v) => trim($v) !== ''));
    }

    private function zipEntry(string $path, string $entry, bool $required = true): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The file is not a valid Office document.');
        }
        $xml = $zip->getFromName($entry);
        $zip->close();
        if ($xml === false && $required) {
            throw new \RuntimeException("The file has no {$entry}.");
        }

        return (string) $xml;
    }

    private function done(string $method, string $text): array
    {
        $text = trim((string) preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text)));
        if ($text === '') {
            return $this->none('The document has no text.');
        }

        return ['state' => 'done', 'method' => $method, 'content' => mb_substr($text, 0, (int) config('documents.text.max_chars')), 'error' => null];
    }

    private function none(string $why): array
    {
        return ['state' => 'empty', 'method' => null, 'content' => null, 'error' => $why];
    }

    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    private function ocrOn(): bool
    {
        return (bool) config('documents.ocr.enabled');
    }

    private function timeout(): int
    {
        return (int) config('documents.text.timeout');
    }
}
