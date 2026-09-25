<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cabys extends Model
{
    protected $table = 'cabys';

    protected $fillable = [
        'code',
        'description',
        'category1_code',
        'category1_description',
        'category2_code',
        'category2_description',
        'category3_code',
        'category3_description',
        'category4_code',
        'category4_description',
        'category5_code',
        'category5_description',
        'category6_code',
        'category6_description',
        'category7_code',
        'category7_description',
        'category8_code',
        'category8_description',
        'category9_code',
        'category9_description',
        'tax_rate',
        'note1',
        'note2',
        'is_active',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}