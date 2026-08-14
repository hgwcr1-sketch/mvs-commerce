<?php

namespace App\Mail;

use App\Models\CashSession;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CashSessionOpenedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CashSession $cashSession) {}

    public function envelope(): Envelope
    {
        $this->cashSession->loadMissing('cashRegister:id,name');
        return new Envelope(subject: '[MVS] Apertura '.$this->cashSession->session_number.' — '.$this->cashSession->cashRegister->name);
    }

    public function content(): Content
    {
        $session = $this->cashSession->loadMissing(['company:id,trade_name,timezone', 'branch:id,name', 'cashRegister:id,name', 'openedBy:id,name']);
        $timezone = $this->timezone((string) $session->company->timezone);
        return new Content(
            view: 'emails.cash.session-opened',
            text: 'emails.cash.session-opened-text',
            with: ['session' => $session, 'timezone' => $timezone, 'openedAt' => $session->opened_at->copy()->timezone($timezone)],
        );
    }

    private function timezone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : config('app.timezone', 'UTC');
    }
}
