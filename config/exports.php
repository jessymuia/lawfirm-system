<?php

return [
    // ENG-21: how long a finished firm-wide export can be downloaded before the file is deleted.
    'firm_link_hours' => (int) env('FIRM_EXPORT_LINK_HOURS', 24),
    // How long the signed download URL handed to the browser stays valid (it is re-issued on every status check).
    'signed_url_minutes' => (int) env('FIRM_EXPORT_SIGNED_URL_MINUTES', 15),
];
