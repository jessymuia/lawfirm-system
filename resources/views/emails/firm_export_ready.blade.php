<p>Hello {{ $export->requester?->name }},</p>
<p>The full export of your firm's data that you requested is ready ({{ number_format(($export->size ?? 0) / 1048576, 1) }} MB).</p>
<p>Sign in and open the dashboard to download it. The download is available until {{ $export->expires_at?->toDayDateTimeString() }} (UTC); after that the file is deleted and you can request a new one.</p>
<p>If you did not request this export, tell your administrator.</p>
