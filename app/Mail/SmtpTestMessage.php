<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The administrator's test email, sent at once with the saved SMTP settings. */
class SmtpTestMessage extends Mailable
{
    public function __construct(private readonly string $fromAddress, private readonly string $fromName) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, $this->fromName), subject: 'Correo de prueba de MoodleWebToolkit');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Este es un correo de prueba de MoodleWebToolkit.</p>'
            .'<p>Si lo recibe, la plataforma puede enviar las notificaciones de las ejecuciones con esta configuración SMTP.</p>');
    }
}
