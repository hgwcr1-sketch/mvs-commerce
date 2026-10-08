<?php

namespace App\Jobs;

use App\Mail\ComprobanteElectronicoAceptadoMail;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentMailDelivery;
use App\Models\FiscalDocumentCustody;
use App\Models\Sale;
use App\Services\Sales\SaleReceiptService;
use App\Support\FiscalCustodyArtifacts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envío del paquete del comprobante electrónico aceptado al correo del
 * receptor. Solo archivos reales disponibles (PDF de MVS + XML custodiados
 * + respuesta de Hacienda); un archivo opcional que falte no bloquea el
 * correo. Nunca toca el documento fiscal ni consume cuota.
 */
class SendElectronicDocumentMail implements ShouldQueue
{
    use Queueable;

    /**
     * Nombre de adjunto por sufijo de FiscalCustodyArtifacts::xmls(). El
     * orden de adjuntos lo fija la fuente única, no este trabajo.
     *
     * @var array<string, string>
     */
    private const XML_ATTACHMENT_NAMES = [
        '_firmado' => '-xml-firmado.xml',
        '_enviado' => '-xml-enviado.xml',
        '_respuesta_hacienda' => '-respuesta-hacienda.xml',
    ];

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = DB::transaction(function () {
            $row = ElectronicDocumentMailDelivery::query()
                ->lockForUpdate()
                ->find($this->deliveryId);

            if ($row === null || in_array($row->status, [
                ElectronicDocumentMailDelivery::STATUS_SENT,
                ElectronicDocumentMailDelivery::STATUS_SKIPPED,
            ], true)) {
                return null;
            }

            if ($row->status === ElectronicDocumentMailDelivery::STATUS_PROCESSING
                || $row->attempts >= ElectronicDocumentMailDelivery::MAX_ATTEMPTS) {
                return null;
            }

            if (! in_array($row->status, [
                ElectronicDocumentMailDelivery::STATUS_PENDING,
                ElectronicDocumentMailDelivery::STATUS_FAILED,
            ], true)) {
                return null;
            }

            $row->update([
                'status' => ElectronicDocumentMailDelivery::STATUS_PROCESSING,
                'attempts' => $row->attempts + 1,
                'last_error' => null,
            ]);

            return $row->fresh();
        });

        if ($delivery === null || $delivery->recipient === null) {
            return;
        }

        $document = ElectronicDocument::query()
            ->where('company_id', $delivery->company_id)
            ->find($delivery->electronic_document_id);

        if ($document === null || $document->status !== 'accepted') {
            $delivery->update([
                'status' => ElectronicDocumentMailDelivery::STATUS_SKIPPED,
                'message' => 'Documento sin estado aceptado',
            ]);

            return;
        }

        try {
            $company = $document->company;
            $files = $this->availableFiles($document);

            Mail::to($delivery->recipient)->send(
                new ComprobanteElectronicoAceptadoMail($document, $company, $files)
            );

            $delivery->update([
                'status' => ElectronicDocumentMailDelivery::STATUS_SENT,
                'sent_at' => now(),
                'last_error' => null,
                'attachments' => array_column($files, 'name'),
                'message' => 'Enviado',
            ]);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => ElectronicDocumentMailDelivery::STATUS_FAILED,
                'last_error' => $this->sanitize($exception),
                'message' => 'Fallo de entrega; el comprobante sigue aceptado',
            ]);
        }
    }

    /**
     * Solo archivos reales: PDF generado por MVS, XML custodiado y
     * respuesta de Hacienda custodiada. Lo que falte se omite sin
     * bloquear; lo adjuntado queda registrado en la auditoría.
     *
     * @return array<int, array{name: string, mime: string, content: string}>
     */
    private function availableFiles(ElectronicDocument $document): array
    {
        $files = [];
        $base = $this->baseName($document);

        if ($document->sale_id !== null) {
            try {
                $sale = Sale::query()
                    ->where('company_id', $document->company_id)
                    ->find($document->sale_id);

                if ($sale !== null && $document->company !== null) {
                    $pdf = app(SaleReceiptService::class)->pdf($sale, $document->company);
                    $files[] = [
                        'name' => $base.'.pdf',
                        'mime' => 'application/pdf',
                        'content' => $pdf->output(),
                    ];
                }
            } catch (Throwable) {
                // PDF opcional: su ausencia no bloquea el correo.
            }
        }

        $custody = FiscalDocumentCustody::query()
            ->where('electronic_document_id', $document->id)
            ->first();

        $xmls = FiscalCustodyArtifacts::xmls($custody);

        foreach ($xmls as $suffix => $xml) {
            $name = self::XML_ATTACHMENT_NAMES[$suffix] ?? null;

            if ($name === null) {
                continue;
            }

            $files[] = [
                'name' => $base.$name,
                'mime' => 'application/xml',
                'content' => $xml,
            ];
        }

        if ($custody !== null && is_array($custody->response) && $custody->response !== []) {
            $files[] = [
                'name' => $base.'-respuesta-hacienda.json',
                'mime' => 'application/json',
                'content' => json_encode($custody->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}',
            ];
        }

        return $files;
    }

    private function baseName(ElectronicDocument $document): string
    {
        $reference = $document->consecutivo ?: ($document->clave ?: 'documento-'.$document->id);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $reference) ?? '';
        $safe = trim(str_replace('..', '_', $safe), '._-');

        return $safe !== '' ? $safe : 'documento-'.$document->id;
    }

    private function sanitize(Throwable $exception): string
    {
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z0-9]{2,}/iu', '[correo oculto]', $exception->getMessage());
        $message = preg_replace('#(https?://)[^\s/@:]+:[^\s/@]+@#iu', '$1[credenciales ocultas]@', (string) $message);
        $message = preg_replace('/\b(password|passwd|token|secret|api[_-]?key|p12|pin)\s*[=:]\s*[^\s,;]+/iu', '$1=[oculto]', (string) $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);

        return Str::limit(class_basename($exception).': '.$message, 500, '');
    }
}
