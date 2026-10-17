<?php

namespace App\Sso\Saml;

use App\Sso\SsoException;

/** SEC-12: a SAML response that is not acceptable. The reason goes to the log and audit trail, never to the browser. */
class SamlException extends SsoException
{
}
