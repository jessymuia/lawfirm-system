<?php

namespace App\Tenancy;

use Illuminate\Auth\SessionGuard;

/**
 * TEN-10: a login belongs to ONE firm. Laravel keeps "who is logged in" in the session under a key derived only from the guard, so
 * a session started on firm A's host and replayed against firm B's host (same user id, different person) authenticated as B's
 * user with that id. The key and the remember-me cookie name now include the active firm, so a session can only ever mean something
 * for the firm that created it, even if a session store is shared. For a single-firm install the names are exactly Laravel's
 * (no one is logged out by this change).
 */
class TenantSessionGuard extends SessionGuard
{
    private function salt(): string
    {
        $key = app(TenantContext::class)->key();

        return $key === 'default' ? '' : '|firm:'.$key;
    }

    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(SessionGuard::class.$this->salt());
    }

    public function getRecallerName()
    {
        return 'remember_'.$this->name.'_'.sha1(SessionGuard::class.$this->salt());
    }
}
