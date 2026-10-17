<?php

namespace App\Tenancy;

/**
 * TEN-7: marks a queued job that works on the fleet from the control plane (migrate a firm, back one up, eject one),
 * not inside any one firm. Such a job carries no firm in its payload, the worker leaves its default connection
 * fail-closed, and the job activates whichever firm it names itself (allowing inactive firms, which only platform
 * work may touch). Every other job must carry its firm (TEN-3); this marker is the one, explicit exception.
 */
interface PlatformJob
{
}
