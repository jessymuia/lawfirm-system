<?php

namespace App\Documents;

use ZipArchive;

/**
 * FUN-14: fills a Word (.docx) template. Fields are written in the document as {{ name }}: {{ client.name }},
 * {{ matter.case_number }}, {{ today }}… and {{ ask: Purchase price }} for something the person fills in when they
 * create the document (see TemplateFields).
 *
 * Word often splits what looks like one word into several runs (spell-check marks, a change of formatting, an edit
 * made later), so a field can be broken across runs. A paragraph that holds a field is therefore rewritten as one run
 * that keeps the formatting of the run where the paragraph's text starts; paragraphs without fields are untouched.
 * Body, headers, footers, footnotes and endnotes are all filled. In a paragraph holding a drawing or text box, only
 * fields written in one go (not split across runs) are filled, so the drawing is never touched.
 */
class DocxTemplate
{
    public const FIELD = '/\{\{\s*([^{}]+?)\s*\}\}/u';

    /** The fields a template uses, in order of first appearance. @return list<string> */
    public static function fields(string $path): array
    {
        $found = [];
        foreach (self::parts($path) as $xml) {
            foreach (self::paragraphs($xml) as $p) {
                preg_match_all(self::FIELD, self::text($p), $m);
                foreach ($m[1] as $f) {
                    $found[self::key($f)] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** Writes the filled document to $out. @param array<string, string> $values field => text (missing fields stay visible as [field]) */
    public static function fill(string $path, string $out, array $values): void
    {
        if (! copy($path, $out)) {
            throw new \RuntimeException('Could not prepare the document.');
        }
        $zip = new ZipArchive;
        if ($zip->open($out) !== true) {
            throw new \RuntimeException('The template is not a valid Word document.');
        }
        foreach (self::partNames($zip) as $name) {
            $xml = (string) $zip->getFromName($name);
            $filled = preg_replace_callback('#<w:p\b[^>]*>.*?</w:p>#s', fn ($m) => self::fillParagraph($m[0], $values), $xml);
            if ($filled !== null && $filled !== $xml) {
                $zip->addFromString($name, $filled);
            }
        }
        $zip->close();
    }

    public static function key(string $field): string
    {
        $field = trim((string) preg_replace('/\s+/u', ' ', $field));
        if (preg_match('/^ask\s*:\s*(.+)$/iu', $field, $m)) {
            return 'ask:'.trim($m[1]);
        }

        return strtolower($field);
    }

    private static function fillParagraph(string $p, array $values): string
    {
        $text = self::text($p);
        if (! preg_match(self::FIELD, $text)) {
            return $p;
        }
        $replace = fn (string $t) => (string) preg_replace_callback(self::FIELD, function ($m) use ($values) {
            $k = self::key($m[1]);

            return array_key_exists($k, $values) ? (string) $values[$k] : '['.trim($m[1]).']';
        }, $t);

        // drawings, text boxes and embedded objects sit inside runs: rewriting such a paragraph would lose them, so only
        // fields wholly inside one piece of text are filled there
        if (preg_match('#<w:(drawing|txbxContent|pict|object|sdt)\b#', $p)) {
            return (string) preg_replace_callback('#(<w:t(?:\s[^>]*)?>)(.*?)(</w:t>)#s', fn ($m) => $m[1]
                .htmlspecialchars($replace(html_entity_decode($m[2], ENT_XML1 | ENT_QUOTES, 'UTF-8')), ENT_XML1 | ENT_QUOTES, 'UTF-8').$m[3], $p);
        }
        $filled = $replace($text);

        // keep the paragraph's properties and the first run's formatting; one run carries the whole text
        preg_match('#^<w:p\b[^>]*>#s', $p, $open);
        preg_match('#<w:pPr>.*?</w:pPr>#s', $p, $pPr);
        preg_match('#<w:r\b[^>]*>\s*(<w:rPr>.*?</w:rPr>)?\s*<w:t\b#s', $p, $firstRun);
        $rPr = $firstRun[1] ?? '';
        $lines = explode("\n", $filled);
        $runs = [];
        foreach ($lines as $i => $line) {
            $runs[] = ($i > 0 ? '<w:br/>' : '').'<w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t>';
        }

        return $open[0].($pPr[0] ?? '').'<w:r>'.$rPr.implode('', $runs).'</w:r></w:p>';
    }

    /** The visible text of a paragraph (all <w:t> joined; tabs kept as spaces). */
    private static function text(string $p): string
    {
        preg_match_all('#<w:t(?:\s[^>]*)?>(.*?)</w:t>|<w:tab\s*/>#s', $p, $m);
        $out = '';
        foreach ($m[0] as $i => $whole) {
            $out .= str_starts_with($whole, '<w:tab') ? ' ' : html_entity_decode($m[1][$i], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        return $out;
    }

    /** @return list<string> */
    private static function paragraphs(string $xml): array
    {
        preg_match_all('#<w:p\b[^>]*>.*?</w:p>#s', $xml, $m);

        return $m[0];
    }

    /** @return list<string> the XML of every part that can hold fields */
    private static function parts(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The template is not a valid Word document (.docx).');
        }
        $out = [];
        foreach (self::partNames($zip) as $name) {
            $out[] = (string) $zip->getFromName($name);
        }
        $zip->close();
        if (! $out) {
            throw new \RuntimeException('The template is not a valid Word document (.docx).');
        }

        return $out;
    }

    /** @return list<string> */
    private static function partNames(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $n)) {
                $names[] = $n;
            }
        }

        return $names;
    }
}
