<?php

namespace App\Notifications;

use App\Models\User;
use App\Platform\Access\AccountEmailChanger;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * WS9.E — verify a requested new LOGIN email before it is used.
 *
 * Delivered to the NEW address (on-demand), never to the account's current
 * address: possession of the requested mailbox is the proof of ownership. The
 * message carries only the short-lived signed link and its expiry — no
 * credential and no account secret.
 */
class VerifyAccountEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly User $user,
        private readonly string $email,
        private readonly string $verificationUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('lba.account.email_verify_subject'))
            ->line(__('lba.account.email_verify_intro', ['name' => $this->user->name]))
            ->action(__('lba.account.email_verify_action'), $this->verificationUrl)
            ->line(__('lba.account.email_verify_expiry', [
                'hours' => AccountEmailChanger::CHANGE_TTL_HOURS,
            ]))
            ->line(__('lba.account.email_verify_footer'));
    }
}
