<!DOCTYPE html>
<html>
<head>
    <title>Password Reset request</title>
</head>
<body>
<h1>Hello {{ $username }}!</h1>
<p>Your password reset request was received:</p>

<p>Please click the following link to reset your password:</p>
<a href="{{ env('APP_URL') }}auth/reset/{{ $token }}">Reset Password</a>

<p>Please ignore this email if it was not initiated by you.</p>
</body>
</html>
