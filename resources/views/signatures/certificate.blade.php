<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Signature certificate</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
  h1 { font-size: 18px; margin: 0 0 4px; } h2 { font-size: 13px; margin: 18px 0 6px; }
  .muted { color: #64748b; } table { width: 100%; border-collapse: collapse; }
  td, th { text-align: left; vertical-align: top; padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
  th { width: 32%; color: #475569; font-weight: normal; } .sig { height: 60px; } .mono { font-family: DejaVu Sans Mono, monospace; font-size: 10px; word-break: break-all; }
  .signer { page-break-inside: avoid; margin-bottom: 10px; }
</style>
</head>
<body>
    @include('exports._letterhead')
  <h1>Signature certificate</h1>
  <p class="muted">{{ $firm }} · completed {{ $completed->utc()->format('j F Y, H:i:s') }} UTC</p>

  <h2>Document</h2>
  <table>
    <tr><th>Title</th><td>{{ $r->title }}</td></tr>
    <tr><th>File</th><td>{{ $doc?->filename }}</td></tr>
    <tr><th>SHA-256 of the signed file</th><td class="mono">{{ $r->document_sha256 }}</td></tr>
    <tr><th>Sent for signature by</th><td>{{ $sender }} on {{ \Carbon\Carbon::parse($r->created_at)->utc()->format('j F Y, H:i:s') }} UTC</td></tr>
    <tr><th>Signing order</th><td>{{ $r->in_order ? 'One after another, in the order below' : 'Any order' }}</td></tr>
  </table>

  <h2>Signatures</h2>
  @foreach ($signers as $s)
    <div class="signer">
      <table>
        <tr><th>Signer {{ $s->position }}</th><td><b>{{ $s->name }}</b>@if ($s->email) · {{ $s->email }}@endif @if ($s->phone) · {{ $s->phone }}@endif</td></tr>
        <tr><th>Link sent</th><td>{{ $s->notified_at ? \Carbon\Carbon::parse($s->notified_at)->utc()->format('j M Y, H:i:s').' UTC' : '' }}</td></tr>
        <tr><th>Document opened</th><td>{{ $s->viewed_at ? \Carbon\Carbon::parse($s->viewed_at)->utc()->format('j M Y, H:i:s').' UTC' : '' }}</td></tr>
        <tr><th>Signed</th><td>{{ \Carbon\Carbon::parse($s->signed_at)->utc()->format('j M Y, H:i:s') }} UTC, typing the name "{{ $s->signed_name }}" and confirming the intent to sign</td></tr>
        <tr><th>From</th><td>IP {{ $s->ip_address }}<br><span class="muted">{{ $s->user_agent }}</span></td></tr>
        <tr><th>Signature</th><td>@if ($s->signature_image)<img class="sig" src="{{ $s->signature_image }}" alt="signature">@endif</td></tr>
      </table>
    </div>
  @endforeach

  <h2>How this was signed</h2>
  <p>Each signer received a personal link by email or SMS. Before signing, the system checked that the file was identical
  (same SHA-256) to the one sent. Each signer typed their name, drew their signature and confirmed that they intend to
  sign. The record of each step is kept in the firm's audit trail.</p>
</body>
</html>
