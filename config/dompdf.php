<?php

/**
 * PDF rendering (invoices, certificates). Set explicitly rather than left to defaults: a PDF that downloads "blank" is
 * almost always dompdf unable to write its font cache, so the font directory is created and used from storage/fonts.
 * The default face is Helvetica, a built-in PDF font that needs no font file at all.
 */
return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,
    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => realpath(base_path()),
        'allowed_protocols' => ['data://' => ['rules' => []]],
        'log_output_file' => null,
        'enable_font_subsetting' => false,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'screen',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'Helvetica',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
