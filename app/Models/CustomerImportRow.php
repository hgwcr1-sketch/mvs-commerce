<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'processed_at' => 'datetime'];
    }

    public function previewData(): array
    {
        return ($this->data ?? []) + [
            'row_number' => $this->source_row,
            'kind' => $this->kind,
            'reason' => $this->reason,
            'duplicate_of_row' => $this->duplicate_of_row,
            'valid' => ! in_array($this->kind, ['conflict', 'error'], true),
            'skipped' => ! in_array($this->kind, ['new', 'created'], true),
        ];
    }
}
