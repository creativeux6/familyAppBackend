<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportRecoveryRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $phone,
        public readonly ?string $note,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password recovery help — no email on account',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.support-recovery-text',
            html: 'mail.support-recovery',
        );
    }
}
