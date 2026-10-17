<?php

namespace App\Documents;

use App\Models\Cases;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FUN-14: the values a template's fields are filled with, for one matter. The list below is what the template editor
 * shows; {{ ask: Label }} fields are answered by the person creating the document.
 */
class TemplateFields
{
    public const KNOWN = [
        'client.name' => 'Client name', 'client.phone' => 'Client phone', 'client.address' => 'Client address',
        'matter.case_number' => 'Matter / case number', 'matter.description' => 'Matter description', 'matter.type' => 'Case type',
        'matter.court' => 'Court', 'matter.opposing_party' => 'Opposing party', 'matter.client_reference' => "Client's matter reference",
        'matter.start_date' => 'Matter opened', 'matter.next_hearing' => 'Next hearing date',
        'lawyer.name' => 'Responsible advocate', 'lawyer.email' => "Advocate's email", 'lawyer.phone' => "Advocate's phone",
        'user.name' => 'Person creating the document', 'firm.name' => 'Firm name',
        'today' => 'Today (05/10/2026)', 'today.long' => 'Today (5 October 2026)',
    ];

    /** @return array<string, string> */
    public function for(Cases $case, User $user, array $answers = []): array
    {
        $client = DB::table('clients')->where('id', $case->client_id)->first(['name', 'phone_number', 'address']);
        $lawyer = DB::table('users')->where('id', $case->assigned_to)->first(['name', 'email', 'phone_number']);
        $court = DB::table('courts')->where('id', $case->court_id)->first(['name', 'alias']);
        $next = DB::table('hearings')->where('case_id', $case->id)->whereNull('deleted_at')->where('hearing_date', '>=', now()->toDateString())->min('hearing_date');
        $today = CarbonImmutable::now((string) config('calendar.timezone', 'Africa/Nairobi'));
        $date = fn ($d) => $d ? CarbonImmutable::parse($d)->format('d/m/Y') : '';

        $values = [
            'client.name' => $client->name ?? '', 'client.phone' => $client->phone_number ?? '', 'client.address' => $client->address ?? '',
            'matter.case_number' => (string) $case->case_number, 'matter.description' => (string) $case->description, 'matter.type' => (string) $case->case_type,
            'matter.court' => $court ? ($court->alias ?: $court->name) : '', 'matter.opposing_party' => (string) $case->opposing_party,
            'matter.client_reference' => (string) ($case->client_matter_id ?? ''), 'matter.start_date' => $date($case->start_date), 'matter.next_hearing' => $date($next),
            'lawyer.name' => $lawyer->name ?? '', 'lawyer.email' => $lawyer->email ?? '', 'lawyer.phone' => $lawyer->phone_number ?? '',
            'user.name' => (string) $user->name, 'firm.name' => (string) config('app.name'),
            'today' => $today->format('d/m/Y'), 'today.long' => $today->format('j F Y'),
        ];
        foreach ($answers as $label => $text) {
            $values[DocxTemplate::key('ask: '.$label)] = (string) $text;
        }

        return $values;
    }

    /** Fields of a template the system does not know and does not ask for (likely typos). @param list<string> $fields */
    public static function unknown(array $fields): array
    {
        return array_values(array_filter($fields, fn ($f) => ! str_starts_with($f, 'ask:') && ! isset(self::KNOWN[$f])));
    }

    /** @param list<string> $fields @return list<string> the questions the person is asked */
    public static function questions(array $fields): array
    {
        return array_values(array_map(fn ($f) => substr($f, 4), array_filter($fields, fn ($f) => str_starts_with($f, 'ask:'))));
    }
}
