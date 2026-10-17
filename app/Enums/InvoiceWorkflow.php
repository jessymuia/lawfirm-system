<?php

namespace App\Enums;

/** DATA-12: an invoice's workflow (invoices.workflow_status): a draft can change; a submitted one is frozen (DATA-10). */
enum InvoiceWorkflow: string
{
    use EnumValues;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Void = 'void';
}
