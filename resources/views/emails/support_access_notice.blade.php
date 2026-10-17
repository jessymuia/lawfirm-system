<p>{{ $headline }}</p>
<ul>
    <li><strong>Who:</strong> {{ $session->staff_name }} ({{ $session->staff_email }}), platform support</li>
    <li><strong>Reason given:</strong> {{ $session->reason }}</li>
    <li><strong>Access:</strong> {{ $session->mode === 'full' ? 'read and write' : 'read only' }}, up to {{ $session->requested_minutes }} minutes</li>
    <li><strong>Status:</strong> {{ $session->status }}</li>
</ul>
<p>You can review, approve, deny or revoke platform support access at any time under Settings, Support access. Every action taken during a session is recorded in your audit log.</p>
