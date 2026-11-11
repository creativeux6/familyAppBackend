<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $phone,
        public readonly string $deviceName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New login to your account',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.login-alert-text',
            html: 'mail.login-alert',
        );
    }
}
