<?php

namespace App\EBilling;

/**
 * FUN-13: the Uniform Task-Based Management System codes (ABA Litigation Code Set) that corporate and insurer clients
 * require on e-billed invoices. They are a fixed public standard, not firm data: firms cannot add or rename them, so they
 * live here rather than in a table (see docs/EBILLING.md). Only the leaf task codes are billable; L100, L200… are the
 * phases they group under.
 */
final class Utbms
{
    public const PHASES = [
        'L100' => 'Case Assessment, Development and Administration',
        'L200' => 'Pre-Trial Pleadings and Motions',
        'L300' => 'Discovery',
        'L400' => 'Trial Preparation and Trial',
        'L500' => 'Appeal',
    ];

    public const TASKS = [
        'L110' => 'Fact Investigation/Development',
        'L120' => 'Analysis/Strategy',
        'L130' => 'Experts/Consultants',
        'L140' => 'Document/File Management',
        'L150' => 'Budgeting',
        'L160' => 'Settlement/Non-Binding ADR',
        'L190' => 'Other Case Assessment, Development and Administration',
        'L210' => 'Pleadings',
        'L220' => 'Preliminary Injunctions/Provisional Remedies',
        'L230' => 'Court Mandated Conferences',
        'L240' => 'Dispositive Motions',
        'L250' => 'Other Written Motions and Submissions',
        'L260' => 'Class Action Certification and Notice',
        'L310' => 'Written Discovery',
        'L320' => 'Document Production',
        'L330' => 'Depositions',
        'L340' => 'Expert Discovery',
        'L350' => 'Discovery Motions',
        'L390' => 'Other Discovery',
        'L410' => 'Fact Witnesses',
        'L420' => 'Expert Witnesses',
        'L430' => 'Written Motions and Submissions',
        'L440' => 'Other Trial Preparation and Support',
        'L450' => 'Trial and Hearing Attendance',
        'L460' => 'Post-Trial Motions and Submissions',
        'L470' => 'Enforcement',
        'L510' => 'Appellate Motions and Submissions',
        'L520' => 'Appellate Briefs',
        'L530' => 'Oral Argument',
    ];

    public const ACTIVITIES = [
        'A101' => 'Plan and prepare for',
        'A102' => 'Research',
        'A103' => 'Draft/revise',
        'A104' => 'Review/analyze',
        'A105' => 'Communicate (in firm)',
        'A106' => 'Communicate (with client)',
        'A107' => 'Communicate (other outside counsel)',
        'A108' => 'Communicate (other external)',
        'A109' => 'Appear for/attend',
        'A110' => 'Manage data/files',
        'A111' => 'Other',
    ];

    public const EXPENSES = [
        'E101' => 'Copying',
        'E102' => 'Outside printing',
        'E103' => 'Word processing',
        'E104' => 'Facsimile',
        'E105' => 'Telephone',
        'E106' => 'Online research',
        'E107' => 'Delivery services/messengers',
        'E108' => 'Postage',
        'E109' => 'Local travel',
        'E110' => 'Out-of-town travel',
        'E111' => 'Meals',
        'E112' => 'Court fees',
        'E113' => 'Subpoena fees',
        'E114' => 'Witness fees',
        'E115' => 'Deposition transcripts',
        'E116' => 'Trial transcripts',
        'E117' => 'Trial exhibits',
        'E118' => 'Litigation support vendors',
        'E119' => 'Experts',
        'E120' => 'Private investigators',
        'E121' => 'Arbitrators/mediators',
        'E122' => 'Local counsel',
        'E123' => 'Other professionals',
        'E124' => 'Other',
    ];

    /** LEDES timekeeper classifications. */
    public const TIMEKEEPER_CLASSES = [
        'PT' => 'Partner',
        'AS' => 'Associate',
        'OC' => 'Of counsel',
        'LA' => 'Legal assistant',
        'PL' => 'Paralegal',
        'OT' => 'Other timekeeper',
    ];

    /** The phase a task code belongs to (L110 -> L100). */
    public static function phaseOf(string $task): ?string
    {
        $phase = substr($task, 0, 2).'00';

        return isset(self::PHASES[$phase]) ? $phase : null;
    }

    /** @return array<string, array<int, array{code: string, name: string, phase?: string}>> for the pickers */
    public static function options(): array
    {
        $list = fn (array $codes) => array_map(fn ($code, $name) => ['code' => $code, 'name' => $name], array_keys($codes), $codes);

        return [
            'tasks' => array_map(fn ($t) => $t + ['phase' => self::PHASES[self::phaseOf($t['code'])]], $list(self::TASKS)),
            'activities' => $list(self::ACTIVITIES),
            'expenses' => $list(self::EXPENSES),
            'timekeeper_classes' => $list(self::TIMEKEEPER_CLASSES),
        ];
    }
}
