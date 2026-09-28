<?php

namespace App\Mail;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SaleReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Sale $sale, private readonly string $pdfContent) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Comprobante {$this->sale->sale_number} — {$this->sale->company->trade_name}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sales.receipt',
            text: 'emails.sales.receipt-text',
            with: ['sale' => $this->sale],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, "comprobante-{$this->sale->sale_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
