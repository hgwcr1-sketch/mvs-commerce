<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPurchaseSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'default_product_category_id',
        'default_unit_id',
        'supplier_assignment_required',
    ];

    protected $casts = [
        'supplier_assignment_required' => 'boolean',
    ];

    /**
     * Empresa propietaria de la configuración.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Categoría usada cuando el origen no aporta una.
     */
    public function defaultProductCategory()
    {
        return $this->belongsTo(
            ProductCategory::class,
            'default_product_category_id',
        );
    }

    /**
     * Unidad usada cuando el origen no aporta una.
     */
    public function defaultUnit()
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }
}
