<?php

namespace App\Sso;

/** SEC-12: any reason a single sign-on attempt or configuration is refused. The message is for logs/admins, never shown to the person signing in. */
class SsoException extends \RuntimeException
{
}
