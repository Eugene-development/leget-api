<?php

declare(strict_types=1);

namespace App\Auth;

use Tymon\JWTAuth\JWTGuard;

/** Reject sessions issued before the user's latest password reset. */
final class VersionedJwtGuard extends JWTGuard
{
    public function user()
    {
        // Preserve users explicitly set by credential login or actingAs().
        if ($this->user !== null) {
            return $this->user;
        }

        $user = parent::user();
        if ($user === null) {
            return null;
        }

        $payload = $this->jwt->getPayload();
        // JWTs issued before this feature are valid until the first reset.
        $version = $payload->hasKey('token_version') ? $payload->get('token_version') : 0;
        if (! is_int($version) || $version < 0 || $version !== (int) $user->token_version) {
            $this->user = null;

            return null;
        }

        return $user;
    }
}
