<?php

declare(strict_types=1);

namespace Pype\Mail;

/**
 * A reusable email: the class says what it needs, build() makes the Message.
 *
 *   final class WelcomeMail extends Mail
 *   {
 *       public function __construct(private readonly User $user) {}
 *
 *       public function build(): Message
 *       {
 *           return Message::to($this->user->email, $this->user->name)
 *               ->subject('Welcome to Spendwise')
 *               ->view('emails/welcome', ['user' => $this->user]);
 *       }
 *   }
 *
 *   $mailer->later(new WelcomeMail($user));
 */
abstract class Mail
{
    abstract public function build(): Message;
}
