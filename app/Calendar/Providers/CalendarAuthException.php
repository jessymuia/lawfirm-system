<?php

namespace App\Calendar\Providers;

/** FUN-16: the provider no longer accepts the connection (revoked, expired refresh token): the person must reconnect. */
class CalendarAuthException extends \RuntimeException {}
