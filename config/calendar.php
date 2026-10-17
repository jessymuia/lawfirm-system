<?php

/*
 * FUN-16: the court diary, calendar subscription feeds and Google / Microsoft calendar sync. See docs/CALENDAR.md.
 */
return [
    'timezone' => env('CALENDAR_TIMEZONE', 'Africa/Nairobi'),
    'default_hearing_minutes' => (int) env('CALENDAR_DEFAULT_HEARING_MINUTES', 60),
    'window' => [
        'past_days' => (int) env('CALENDAR_PAST_DAYS', 30),
        'future_days' => (int) env('CALENDAR_FUTURE_DAYS', 365),
    ],

    // Where the providers send people back after they allow access. One fixed address registered with Google and
    // Microsoft; in a multi-firm deployment it hands the browser on to the firm's own host.
    'oauth_redirect' => env('CALENDAR_OAUTH_REDIRECT', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/calendar/oauth/callback'),

    'google' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CALENDAR_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CALENDAR_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_CALENDAR_TENANT', 'common'),
    ],
];
