<?php

namespace App\Platform\Access\Exceptions;

use RuntimeException;

/**
 * WS4 P0 — a refused staff-invitation redemption.
 *
 * The default message is intentionally uniform and safe to present: whether the
 * proof was unknown, tampered with, already redeemed or expired, the caller is
 * told the same thing. That keeps the flow fail-closed without letting an
 * attacker probe which proofs exist or how far along an invitation is.
 *
 * The one exception is a *valid* proof for an EXISTING identity that has simply
 * not been authenticated yet: the caller is asked to sign in as the invited
 * identity. That reveals nothing (the invitee already holds the proof) and is
 * the only way an established account's membership can be accepted — its
 * password is never reset by an invitation.
 */
final class StaffInvitationException extends RuntimeException
{
    private function __construct(string $message, private readonly bool $authenticationRequired = false)
    {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self('The invitation link is invalid or has expired.');
    }

    public static function authenticationRequired(): self
    {
        return new self(
            'Sign in as the invited identity to accept this invitation.',
            authenticationRequired: true,
        );
    }

    public function isAuthenticationRequired(): bool
    {
        return $this->authenticationRequired;
    }
}
