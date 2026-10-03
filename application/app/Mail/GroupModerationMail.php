<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GroupModerationMail extends Mailable
{
    public function __construct(public string $groupTitle, public string $result, public ?string $comment, public string $groupUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Результат модерации группы');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.group-moderation', text: 'mail.group-moderation-text');
    }
}
