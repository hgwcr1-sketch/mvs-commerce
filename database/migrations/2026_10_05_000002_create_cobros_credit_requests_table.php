<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobros_credit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('cobros_route_id');
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('status', 20)->default('pendiente');
            $table->string('identification_type', 20)->nullable();
            $table->string('identification', 50);
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('address', 250)->nullable();
            $table->decimal('amount_requested', 19, 4);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            // Sin unique en identification: las solicitudes cerradas del histórico
            // conviven; la validación de duplicado ABIERTO vive en el servicio.
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'identification']);
            $table->foreign(['company_id', 'cobros_route_id'])
                ->references(['company_id', 'id'])->on('cobros_routes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_credit_requests');
    }
};
