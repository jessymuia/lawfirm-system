# Searching inside documents (FUN-8)

Lawyers search for what a document *says*, not what it is called. Every uploaded document is read in the background,
scans and photos included, and the words are searchable from the matter's Documents tab and from global search (Ctrl/Cmd-K).

## Reading the text

After an upload (staff or client portal), a queued job (`ExtractDocumentText`) reads the file into `document_texts`:

| File | How | Needs |
|---|---|---|
| Plain text | read as is (converted to UTF-8) | — |
| Word `.docx`, Excel `.xlsx` | read from the file's XML | — (PHP zip) |
| PDF with text | `pdftotext` | poppler-utils |
| Scanned PDF (no text layer) | pages rendered with `pdftoppm`, then OCR | poppler-utils, tesseract |
| PNG / JPEG photos | OCR | tesseract |
| Legacy `.doc` / `.xls` | not read (marked *Not searchable*) | — |

- The bundled Docker image includes poppler-utils and tesseract (English). Bare-metal installs:
  `apt install poppler-utils tesseract-ocr tesseract-ocr-eng`. Add language packs and set `DOCUMENTS_OCR_LANGUAGES`
  (e.g. `eng+swa`) for other languages.
- A missing tool never breaks an upload: the document is marked *Not searchable* with the reason, and **read again**
  on the Documents tab retries it (`POST /api/v1/documents/{id}/reindex`) once the tool is installed.
- Limits: `DOCUMENTS_TEXT_MAX_CHARS` (default one million characters kept per document), `DOCUMENTS_OCR_MAX_PAGES`
  (default 30 pages of a scan), `DOCUMENTS_TEXT_TIMEOUT` (seconds per tool run). `DOCUMENTS_OCR=false` turns OCR off;
  `DOCUMENTS_TEXT_EXTRACTION=false` turns reading off entirely.
- Existing documents: `app:upgrade` (and the fleet migrator) queue `BackfillDocumentText`, which reads every current
  version that has not been read yet. Run a queue worker; with the default `sync` queue the upgrade reads them inline,
  which can take a while on a large firm.

## Searching

- `GET /api/v1/documents/search?q=…[&case_id=…]` (permission `list-documents`): every word must appear (in any order),
  `"quoted phrases"` must appear as written, and words match by their start (`coven` finds *covenant*). Results carry a
  passage around the first match with the words marked.
- Only the current version of each document is searched.
- **Access:** the search runs through the same matter-access and ethical-wall rules as every list (SEC-10), inside the
  query: a document on a matter the searcher cannot open is never matched, counted or quoted.
- **Isolation:** each firm's text lives in its own database, so the index is per firm by construction.
- **Engine:** MySQL/MariaDB use a full-text index with relevance ranking. Other databases (SQLite in development) scan,
  newest first. `DOCUMENTS_SEARCH_ENGINE=fulltext|scan` forces one. ENG-19 can put a search engine behind the same API.

## Not covered yet

- Search engine with typo tolerance and per-firm indexes (ENG-19).
- Legacy `.doc`/`.xls` (needs a converter such as LibreOffice headless).
- Searching older versions.
