<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Consecutivo interno de MVS Commerce.
            $table->string('number');

            // Documento/factura entregado por el proveedor.
            $table->string('supplier_invoice_number')->nullable();

            $table->date('purchase_date');

            // cash = contado / credit = crédito
            $table->string('payment_type')->default('cash');

            // Fecha de vencimiento cuando la compra es a crédito.
            $table->date('due_date')->nullable();

            // Totales monetarios de la compra.
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            // posted = compra aplicada / cancelled = anulada
            $table->string('status')->default('posted');

            $table->text('notes')->nullable();

            // Auditoría de anulación.
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            /*
             * El consecutivo interno solo puede repetirse
             * entre empresas diferentes.
             */
            $table->unique(
                ['company_id', 'number'],
                'purchases_company_number_unique'
            );

            /*
             * Índices principales para consultas por
             * empresa, sucursal, proveedor y fecha.
             */
            $table->index(
                ['company_id', 'branch_id', 'purchase_date'],
                'purchases_company_branch_date_index'
            );

            $table->index(
                ['company_id', 'supplier_id'],
                'purchases_company_supplier_index'
            );

            $table->index(
                ['company_id', 'status'],
                'purchases_company_status_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};