<?php

/*
 * FUN-8: reading the text inside documents so they can be searched. See docs/DOCUMENT_SEARCH.md.
 * Extraction runs on the queue after each upload; the tools below are optional system packages:
 *   pdftotext / pdftoppm (poppler-utils) for PDFs, tesseract (tesseract-ocr) for scans and photos.
 * Missing tools do not break uploads: the document is simply marked as not (yet) searchable.
 */
return [
    // SEC-14: scan every document for malware before it is filed (app/Documents/MalwareScanner.php). off | clamd
    'malware_scan' => [
        'driver' => env('DOCUMENTS_MALWARE_SCAN', 'off'),
        'host' => env('CLAMD_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMD_PORT', 3310),
        'timeout' => (float) env('CLAMD_TIMEOUT', 30),
        // when clamd cannot be reached: refuse the upload (default) or accept it unscanned
        'fail_open' => (bool) env('DOCUMENTS_MALWARE_FAIL_OPEN', false),
    ],

    'text' => [
        'enabled' => env('DOCUMENTS_TEXT_EXTRACTION', true),
        'max_chars' => (int) env('DOCUMENTS_TEXT_MAX_CHARS', 1_000_000),   // longer text is cut (searches still find the first million characters)
        'timeout' => (int) env('DOCUMENTS_TEXT_TIMEOUT', 120),              // seconds per tool run
        'pdftotext' => env('DOCUMENTS_PDFTOTEXT', 'pdftotext'),
        'pdftoppm' => env('DOCUMENTS_PDFTOPPM', 'pdftoppm'),
    ],

    // auto: the database's full-text index on MySQL/MariaDB, a scan elsewhere; fulltext | scan to force one
    'search' => [
        'engine' => env('DOCUMENTS_SEARCH_ENGINE', 'auto'),
    ],

    'ocr' => [
        'enabled' => env('DOCUMENTS_OCR', true),
        'tesseract' => env('DOCUMENTS_TESSERACT', 'tesseract'),
        'languages' => env('DOCUMENTS_OCR_LANGUAGES', 'eng'),             // tesseract -l, e.g. "eng+swa"
        'max_pages' => (int) env('DOCUMENTS_OCR_MAX_PAGES', 30),
    ],

    // ENG-22: browsers upload straight to an S3-compatible firm disk (signed POST policy), not through PHP. The bucket needs a
    // CORS rule allowing POST from the firm hosts (docs/ops/DIRECT_UPLOADS.md). Elsewhere uploads go through the API.
    'direct_uploads' => [
        'enabled' => env('DOCUMENTS_DIRECT_UPLOADS', true),
        'max_mb' => (int) env('DOCUMENTS_DIRECT_MAX_MB', 500),
    ],
];
