<?php

return [
    // DATA-8: when true, invoice numbers reset each calendar year
    // (INV-2026-000123). Statutory gap-free numbering is per-year in many
    // jurisdictions. Default false keeps the historic INV-000123 format.
    'number_per_year' => (bool) env('INVOICE_NUMBER_PER_YEAR', false),
];
