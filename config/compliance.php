<?php

use App\Compliance\RecordClasses\CaseDocuments;
use App\Compliance\RecordClasses\InactiveClients;
use App\Compliance\RecordClasses\MatterEmails;
use App\Compliance\RecordClasses\PortalMessages;

/** SEC-13: retention, legal hold and data-subject requests (see docs/COMPLIANCE.md). */
return [
    // The shortest retention a firm may configure for case documents. This is a guard against typing 0, not legal advice:
    // the right period depends on the firm's regulator and jurisdiction.
    'minimum_retention_years' => (int) env('COMPLIANCE_MIN_RETENTION_YEARS', 1),

    // Days to answer a data-subject request (Kenya DPA 2019: 30 days; GDPR: one month).
    'dsar_days' => (int) env('COMPLIANCE_DSAR_DAYS', 30),

    // Record classes. Only `purgeable` classes can ever be disposed of by the retention job; each names the handler that
    // finds and disposes of its records (App\Compliance\RecordClasses\RecordClass). Each firm sets its own period per class.
    'classes' => [
        'case_documents' => ['label' => 'Documents of closed or settled matters', 'purgeable' => true,
            'clock' => 'matter end date', 'handler' => CaseDocuments::class,
            'disposal' => 'file and extracted text deleted; the row stays as a stub'],
        'matter_emails' => ['label' => 'Emails filed to closed or settled matters', 'purgeable' => true,
            'clock' => 'matter end date', 'handler' => MatterEmails::class,
            'disposal' => 'original .eml deleted; addresses, subject and body emptied; the row stays as a stub'],
        'portal_messages' => ['label' => 'Client portal messages of closed or settled matters', 'purgeable' => true,
            'clock' => 'matter end date', 'handler' => PortalMessages::class,
            'disposal' => 'message deleted'],
        'inactive_clients' => ['label' => 'Personal data of former clients', 'purgeable' => true,
            'clock' => 'end date of the client\'s last matter (or when it was added, if it had none)',
            'handler' => InactiveClients::class,
            'disposal' => 'anonymised as for an erasure request; financial records kept'],
        'financial_records' => ['label' => 'Invoices, payments and trust ledger', 'purgeable' => false,
            'clock' => 'append-only by design (DATA-10); never disposed of by this job'],
        'audit_log' => ['label' => 'Audit trail', 'purgeable' => false,
            'clock' => 'kept; PII inside it is redacted on erasure'],
    ],

    // Personal-data fields of a client that erasure replaces, and that are redacted inside audit history.
    'client_pii' => ['name', 'phone_number', 'extra_phone_number', 'address'],
];
