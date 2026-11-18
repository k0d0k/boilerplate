<?php

namespace App\Platform\Access\Exceptions;

use RuntimeException;

/**
 * WS6.D — a refused self-service password change.
 *
 * The message is uniform and safe to surface: whether the current password was
 * wrong or the request was otherwise malformed, the caller learns only that the
 * change did not happen. No credential, hash or token ever appears here.
 */
final class PasswordChangeException extends RuntimeException
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
        return new self('The password could not be changed.');
    }
}
