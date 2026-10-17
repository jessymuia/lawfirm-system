<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 20px; margin-bottom: 0; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; }
        .totals td { border: none; }
        .totals .label { text-align: right; font-weight: bold; }
        .header-row { width: 100%; }
        .header-row td { border: none; vertical-align: top; }
    </style>
</head>
<body>
    @include('exports._letterhead')
    <table class="header-row">
        <tr>
            <td>
                <h1>Invoice {{ $invoice->invoice_number }}</h1>
                <div class="muted">Case: {{ $invoice->case?->case_number ?? 'Unknown case' }}</div>
            </td>
            <td style="text-align: right;">
                <div><strong>Date:</strong> {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') }}</div>
                <div><strong>Due:</strong> {{ \Carbon\Carbon::parse($invoice->invoice_due_date)->format('d/m/Y') }}</div>
                <div><strong>Status:</strong> @if ($invoice->payment_status === 'paid')<strong style="color: #166534;">PAID</strong>@else{{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}@endif</div>
            </td>
        </tr>
    </table>

    <div style="margin-top: 20px;">
        <strong>Bill to:</strong><br>
        {{ $invoice->client?->name ?? 'Unknown client' }}<br>
        {{ $invoice->client?->address }}
    </div>

    <table>
        <thead>
        <tr>
            <th>Description</th>
            <th>Qty</th>
            <th>Rate</th>
            <th>Tax</th>
            <th>Amount</th>
        </tr>
        </thead>
        <tbody>
        @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td>{{ $item->quantity }}</td>
                <td>{{ number_format($item->rate, 2) }}</td>
                <td>{{ number_format($item->tax, 2) }}</td>
                <td>{{ number_format($item->total_amount, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="width: 300px; margin-left: auto;">
        <tr>
            <td class="label">Subtotal</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Tax</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->tax_total, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Total</td>
            <td><strong>{{ $invoice->currency }} {{ number_format($invoice->total_amount, 2) }}</strong></td>
        </tr>
        <tr>
            <td class="label">Paid</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->amount_paid, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Balance Due</td>
            <td><strong>{{ $invoice->currency }} {{ number_format($invoice->total_amount - $invoice->amount_paid, 2) }}</strong></td>
        </tr>
    </table>
    @php($footer = \App\Branding\Branding::get()['invoice_footer'])
    @if ($footer)
        <div style="margin-top: 28px; font-size: 10px; color: #555; white-space: pre-line;">{{ $footer }}</div>
    @endif
</body>
</html>
