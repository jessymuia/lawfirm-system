<!DOCTYPE html>
<html>
<head>
    <title>Registration Successful</title>
</head>
<body>
<h1>Hello {{ $username }}!</h1>
<h3>Thank you for registering!</h3>
<p>Your registration was successful. Please confirm your registration below:</p>

<p>Please click the following link to log in:</p>
<a href="{{ env('APP_URL') }}/login/{{ $token }}">Log In</a>
<p>Please ignore this email if it is not you.</p>
</body>
</html>
