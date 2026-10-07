<?php

namespace App\Mail;

use App\Http\Controllers\FiscalPortalController;
use App\Models\Company;
use App\Models\ElectronicDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso neutro y profesional al receptor: identifica el comprobante
 * electrónico aceptado y adjunta los archivos reales disponibles.
 * No usa identidad de Hacienda ni marca del proveedor fiscal.
 */
class ComprobanteElectronicoAceptadoMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{name: string, mime: string, content: string}>  $files
     */
    public function __construct(
        public ElectronicDocument $document,
        public Company $company,
        public array $files = [],
    ) {}

    public function typeLabel(): string
    {
        return FiscalPortalController::TYPE_LABELS[$this->document->document_type] ?? 'Comprobante electrónico';
    }

    public function attachmentNames(): array
    {
        return array_column($this->files, 'name');
    }

    public function envelope(): Envelope
    {
        $reference = $this->document->consecutivo ?: ($this->document->clave ?: 'id '.$this->document->id);

        $subject = 'Comprobante electrónico aceptado — '.$this->company->trade_name
            .' — '.$this->typeLabel().' '.$reference;

        $envelope = new Envelope(subject: $subject);

        if (config('mail.notifications.from.address')) {
            $envelope->from(config('mail.notifications.from.address'), config('mail.notifications.from.name') ?? null);
        }

        if (config('mail.reply_to.address')) {
            $envelope->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name') ?? null);
        }

        return $envelope;
    }

    public function content(): Content
    {
        $total = $this->document->sale?->total;

        return new Content(
            view: 'emails.fiscal.comprobante-electronico-aceptado',
            text: 'emails.fiscal.comprobante-electronico-aceptado-text',
            with: [
                'company' => $this->company,
                'document' => $this->document,
                'typeLabel' => $this->typeLabel(),
                'reference' => $this->document->consecutivo ?: ($this->document->clave ?: '—'),
                'clave' => $this->document->clave,
                'fecha' => $this->document->updated_at?->format('d/m/Y H:i'),
                'total' => $total !== null ? number_format((float) $total, 2, ',', '.') : null,
                'adjuntos' => $this->attachmentNames(),
            ],
        );
    }

    /**
     * Adjuntos como partes de datos (nombre + mime separados), nunca rutas.
     * Las entradas incompletas o vacías se omiten sin bloquear el envío.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->files as $file) {
            $name = is_array($file) ? (string) ($file['name'] ?? '') : '';
            $content = is_array($file) ? ($file['content'] ?? null) : null;

            if ($name === '' || ! is_string($content) || $content === '') {
                continue;
            }

            $attachments[] = Attachment::fromData(fn () => $content, $name)
                ->withMime(is_array($file) && isset($file['mime']) && is_string($file['mime'])
                    ? $file['mime']
                    : 'application/octet-stream');
        }

        return $attachments;
    }
}
