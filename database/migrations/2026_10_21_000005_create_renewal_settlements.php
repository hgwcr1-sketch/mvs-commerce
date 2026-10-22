<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('cobros_renewal_settlements', function(Blueprint $t):void { $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('source_credit_id')->constrained('cobros_credits'); $t->unsignedBigInteger('renewal_id')->unique(); $t->decimal('pending_principal',19,4); $t->decimal('pending_interest',19,4); $t->decimal('pending_total',19,4); $t->decimal('transferred_late_fee',19,4)->default(0); $t->dateTime('settled_at'); $t->timestamps(); $t->unique(['company_id','source_credit_id','renewal_id']); }); }
    public function down(): void { Schema::dropIfExists('cobros_renewal_settlements'); }
};
