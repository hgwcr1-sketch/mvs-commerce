<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalDocumentCustody extends Model
{
    protected $table = 'fiscal_document_custody';

    protected $fillable = [
        'electronic_document_id',
        'payload',
        'response',
        'xml_signed',
        'hacienda_response_xml',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
        ];
    }

    public function document()
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }
}
