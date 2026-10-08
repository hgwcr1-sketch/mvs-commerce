<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 9B.1 - trazabilidad mínima: cuánto pagó/aplicó cada recibo a cada cuota. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_credit_installment_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('credit_id')->index();
            $table->unsignedBigInteger('installment_id')->index();
            $table->unsignedBigInteger('account_receivable_id')->index();
            $table->unsignedBigInteger('cobros_receipt_id')->unique();
            $table->decimal('amount', 12, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_credit_installment_applications');
    }
};
