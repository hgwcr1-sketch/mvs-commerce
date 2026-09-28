<?php

namespace App\Mail;

use App\Models\LoyaltyPortalCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoyaltyPortalPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LoyaltyPortalCredential $credential, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recupera tu acceso — '.$this->credential->company->trade_name);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.loyalty.portal-password-reset', text: 'emails.loyalty.portal-password-reset-text');
    }
}
