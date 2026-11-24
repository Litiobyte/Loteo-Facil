<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactInquiry extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $community,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [config('mail.contact.address')],
            replyTo: [$this->email],
            subject: 'Solicitud de demostración — Loteo Fácil',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.contact-inquiry');
    }
}
