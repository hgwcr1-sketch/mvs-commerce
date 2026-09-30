<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicDocument extends Model
{
    protected $table = 'electronic_documents';

    protected $fillable = [
        'company_id',
        'sale_id',
        'source_type',
        'source_id',
        'original_document_id',
        'attempt_number',
        'provider',
        'document_type',
        'environment',
        'idempotency_key',
        'provider_document_id',
        'clave',
        'consecutivo',
        'status',
        'last_error_code',
        'last_error_message',
        'provider_request_id',
    ];

    protected $casts = [
        'sale_id' => 'integer',
        'company_id' => 'integer',
        'original_document_id' => 'integer',
        'attempt_number' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function originalDocument()
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    public function adjustments()
    {
        return $this->hasMany(self::class, 'original_document_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForSale($query, int $saleId)
    {
        return $query->where('sale_id', $saleId);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeForDocumentType($query, string $documentType)
    {
        return $query->where('document_type', $documentType);
    }

    public function scopeForEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }

    public function scopeForStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'queued', 'signing', 'sent', 'polling']);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['accepted', 'rejected'], true);
    }

    public function isPending(): bool
    {
        return !$this->isFinal();
    }
}