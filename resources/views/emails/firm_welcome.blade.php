<!DOCTYPE html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.55; max-width: 560px;">
<h2 style="margin-bottom: 4px;">Welcome to {{ $productName }}</h2>
<p>Hello {{ $adminName ?: 'there' }},</p>
<p><strong>{{ $firmName }}</strong> has been set up and you are its administrator. Sign in with these details:</p>
<table cellpadding="8" style="border-collapse: collapse; background: #f3f4f6; border-radius: 8px;">
    <tr><td><strong>Sign-in page</strong></td><td><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></td></tr>
    <tr><td><strong>Email</strong></td><td>{{ $adminEmail }}</td></tr>
    <tr><td><strong>Password</strong></td><td>@if ($password)<code style="font-size: 15px;">{{ $password }}</code>@else The password you already use for {{ $productName }}@endif</td></tr>
    <tr><td><strong>Plan</strong></td><td>{{ $planName }}@if ($trialEnds) (free until {{ $trialEnds }})@endif</td></tr>
</table>
@if ($password)
<p>Please change this password after you first sign in (Profile, Change password). Do not forward this email.</p>
@endif
<p>On the sign-in page choose <strong>Firm</strong>, then enter the email and password above.</p>
<p style="color: #6b7280; font-size: 13px;">If you were not expecting this email, you can ignore it.</p>
</body>
</html>
