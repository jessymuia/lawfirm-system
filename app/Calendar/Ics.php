<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

/**
 * FUN-16: an iCalendar (RFC 5545) document of calendar items, for subscription in Google Calendar, Outlook or Apple
 * Calendar. Times are written in UTC; all-day entries as dates. Text is escaped and long lines folded as the RFC requires.
 */
class Ics
{
    /** @param list<array<string, mixed>> $items */
    public function render(string $name, array $items): string
    {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//'.$this->text((string) config('app.name')).'//Court diary//EN', 'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->text($name), 'X-WR-TIMEZONE:'.config('calendar.timezone'),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
        ];
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($items as $i) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$i['uid'];
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($i['start']) {
                $lines[] = 'DTSTART:'.$i['start']->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$i['end']->utc()->format('Ymd\THis\Z');
            } else {
                $day = CarbonImmutable::parse($i['date']);
                $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd');
                $lines[] = 'TRANSP:TRANSPARENT';
            }
            $lines[] = 'SUMMARY:'.$this->text($i['title']);
            $i['location'] && $lines[] = 'LOCATION:'.$this->text($i['location']);
            $lines[] = 'DESCRIPTION:'.$this->text($i['description']);
            $lines[] = 'URL:'.$i['url'];
            $lines[] = 'LAST-MODIFIED:'.$i['updated']->utc()->format('Ymd\THis\Z');
            $lines[] = 'CATEGORIES:'.($i['type'] === 'hearing' ? 'Hearing' : 'Task');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";
    }

    /** RFC 5545 §3.3.11: backslash, semicolon, comma and new lines are escaped. */
    private function text(string $s): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', ''], $s);
    }

    /** RFC 5545 §3.1: lines longer than 75 octets continue on the next line after a space, never inside a UTF-8 character. */
    private function fold(string $line): string
    {
        $out = '';
        $len = 0;
        foreach (mb_str_split($line) as $ch) {
            $bytes = strlen($ch);
            if ($len + $bytes > 75) {
                $out .= "\r\n ";
                $len = 1;
            }
            $out .= $ch;
            $len += $bytes;
        }

        return $out;
    }
}
