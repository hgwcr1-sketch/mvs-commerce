<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerImportRun extends Model
{
    protected $guarded = ['id'];

    public const TERMINAL = ['completed', 'completed_with_issues'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'purged_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(CustomerImportRow::class, 'run_id')->where('company_id', $this->company_id);
    }
}
