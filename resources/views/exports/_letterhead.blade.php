{{-- UI-13: the firm's letterhead on every document it sends out (invoice, signature certificate). --}}
@php
    $brand = \App\Branding\Branding::get();
    $logo = \App\Branding\Branding::logoDataUri();
    $lines = array_filter([$brand['letterhead']['address'], $brand['letterhead']['phone'], $brand['letterhead']['email'], $brand['letterhead']['website'],
        $brand['letterhead']['tax_pin'] ? 'PIN '.$brand['letterhead']['tax_pin'] : null]);
@endphp
<table style="width: 100%; border-collapse: collapse; border-bottom: 3px solid {{ $brand['color'] }}; margin-bottom: 16px;">
    <tr>
        <td style="border: none; padding: 0 0 10px 0; vertical-align: middle; width: 1%; white-space: nowrap;">
            @if ($logo)
                <img src="{{ $logo }}" alt="" style="max-height: 56px; max-width: 200px;">
            @endif
        </td>
        <td style="border: none; padding: 0 0 10px 12px; vertical-align: middle;">
            <div style="font-size: 16px; font-weight: bold; color: {{ $brand['color'] }};">{{ $brand['name'] }}</div>
            @foreach ($lines as $line)
                <div style="font-size: 10px; color: #555;">{{ $line }}</div>
            @endforeach
        </td>
    </tr>
</table>
