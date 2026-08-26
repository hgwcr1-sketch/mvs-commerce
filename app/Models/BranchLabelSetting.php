<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchLabelSetting extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'print_destinations', 'default_template',
        'default_size', 'custom_heading',
    ];

    protected $casts = ['print_destinations' => 'array'];
}
