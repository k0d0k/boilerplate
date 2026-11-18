<?php

namespace App\Platform\Access\Exceptions;

use RuntimeException;

/**
 * WS9.E — a refused self-service login-email change.
 *
 * The message is uniform and safe to surface: whether the current password was
 * wrong or the request was otherwise malformed, the caller only learns that the
 * change was not accepted. No credential, hash or token ever appears here.
 */
final class AccountEmailChangeException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function currentPasswordMismatch(): self
    {
        return new self('The current password is incorrect.');
    }

    public static function invalid(): self
    {
        return new self('The login email could not be changed.');
    }
}
