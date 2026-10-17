<?php

namespace App\Tenancy\Keys;

use RuntimeException;

/** TEN-13: a firm's data key cannot be used: destroyed, or its wrapping key is unavailable (for BYOK: revoked by the firm). */
class KeyUnavailable extends RuntimeException {}
