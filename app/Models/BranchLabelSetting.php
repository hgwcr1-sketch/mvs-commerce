<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchLabelSetting extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'print_destinations', 'default_template',
        'default_size', 'custom_heading',
        'default_print_mode', 'use_custom_size', 'custom_width', 'custom_height',
    ];

    protected $casts = [
        'print_destinations' => 'array',
        'use_custom_size' => 'boolean',
        'custom_width' => 'integer',
        'custom_height' => 'integer',
    ];
}
