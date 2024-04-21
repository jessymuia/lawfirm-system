<!DOCTYPE html>
<html>
<head>
    <title>Password Reset request</title>
</head>
<body>
<h1>Hello {{ $username }}!</h1>
<p>Your password request received:</p>

<p>Please click the following link to reset password:</p>
<a href="https://portal.mercedesbenzclub.co.ke/login/{{ $token }}">Reset Password</a>
<p>Please ignore this email if it is not you.</p>
</body>
</html>
