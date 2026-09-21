<?php

namespace Tests\Support;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

class SuccessfulMailFake extends MailFake
{
    public static function install(): void
    {
        Mail::swap(new self(new MailManager(app())));
    }

    public static function receipt(): SentMessage
    {
        $email = (new Email)->from('sender@example.test')->to('recipient@example.test')->text('Synthetic receipt');

        return new SentMessage(new \Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
    }

    public function to($users)
    {
        return (new PendingMail($this))->to($users);
    }

    public function send($view, array $data = [], $callback = null)
    {
        parent::send($view, $data, $callback);

        return self::receipt();
    }
}
