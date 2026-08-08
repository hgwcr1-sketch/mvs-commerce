<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cabys extends Model
{
    protected $table = 'cabys';

    protected $fillable = [
        'code',
        'description',
        'category1',
        'category2',
        'category3',
        'category4',
        'category5',
        'category6',
        'category7',
        'category8',
        'category9',
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