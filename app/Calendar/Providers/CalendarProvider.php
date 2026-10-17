<?php

namespace App\Calendar\Providers;

/** FUN-16: one external calendar service (Google Calendar, Microsoft 365) as the sync needs it. */
interface CalendarProvider
{
    public function configured(): bool;

    public function authUrl(string $state): string;

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, email: ?string} */
    public function exchange(string $code): array;

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function refresh(string $refreshToken): array;

    /** Create (no id) or update the event for an item; returns the event's id (a new one if the old was gone). */
    public function upsert(string $token, string $calendarId, array $item, ?string $externalId): string;

    public function delete(string $token, string $calendarId, string $externalId): void;

    /**
     * Events changed in the calendar since the cursor (null: start one).
     *
     * @return array{changes: list<array{id: string, date: ?string, cancelled: bool}>, cursor: ?string}
     */
    public function changes(string $token, string $calendarId, ?string $cursor): array;
}
