<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecuperarSenhaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $codigo,
        public string $nomeApp,
        public int $minutosValidade,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Código para redefinir sua senha',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recuperar-senha',
        );
    }
}
