<?php

namespace App\Platform\Access\Exceptions;

use RuntimeException;

/**
 * WS6.D — a refused password-reset initiate or redemption.
 *
 * The default message is intentionally uniform and safe to present: whether the
 * account is unknown, inactive, the acting identity lacks the capability, the
 * request was throttled or the recovery token was expired/already used, the
 * caller is told the same thing. That keeps the flow fail-closed without
 * letting an attacker probe which accounts exist, which are suspended, or how
 * far along a reset is. The recovery proof is never included.
 */
final class PasswordResetException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self('This password reset request is invalid or has expired.');
    }

    public static function inactive(): self
    {
        return new self('This password reset request is invalid or has expired.');
    }

    public static function notPermitted(): self
    {
        return new self('This password reset request is invalid or has expired.');
    }

    public static function throttled(): self
    {
        return new self('This password reset request is invalid or has expired.');
    }
}
