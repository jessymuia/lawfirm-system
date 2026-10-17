<?php

// app:install defaults for unattended installs (Docker/Helm). Read through config() so they survive `config:cache`.
return [
    'admin_name' => env('INSTALL_ADMIN_NAME', 'Administrator'),
    'admin_email' => env('INSTALL_ADMIN_EMAIL'),
    'admin_phone' => env('INSTALL_ADMIN_PHONE', ''),
    'admin_password' => env('INSTALL_ADMIN_PASSWORD'),
];
