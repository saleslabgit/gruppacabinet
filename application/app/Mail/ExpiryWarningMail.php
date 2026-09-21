<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ExpiryWarningMail extends Mailable
{
    public function __construct(public string $groupTitle, public string $expiresAt, public int $remainingDays, public string $groupUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Заканчивается срок размещения группы');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.expiry-warning', text: 'mail.expiry-warning-text');
    }
}
