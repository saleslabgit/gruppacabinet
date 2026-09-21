<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordSetupMail extends Mailable
{
    public function __construct(public string $setupUrl, public int $ttlHours) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Установите пароль для кабинета gruppa');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-setup', text: 'mail.password-setup-text');
    }
}
