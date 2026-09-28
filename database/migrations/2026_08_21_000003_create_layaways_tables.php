<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('layaway_validity_days')->default(30);
            $table->unsignedSmallInteger('layaway_alert_days')->default(5);
        });
        Schema::create('layaways', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->restrictOnDelete(); $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete(); $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->foreignId('delivered_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->string('number', 50); $table->string('status', 20)->default('active'); $table->char('currency_code', 3)->default('CRC');
            $table->decimal('total', 19, 4); $table->decimal('paid_total', 19, 4)->default(0); $table->decimal('balance_due', 19, 4); $table->date('expires_at');
            $table->timestamp('paid_at')->nullable(); $table->timestamp('delivered_at')->nullable(); $table->timestamp('cancelled_at')->nullable(); $table->timestamp('expired_at')->nullable(); $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete(); $table->string('cancel_reason')->nullable(); $table->text('notes')->nullable(); $table->timestamps();
            $table->unique(['company_id','number']); $table->index(['company_id','branch_id','status','expires_at']);
        });
        Schema::create('layaway_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('layaway_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained()->restrictOnDelete(); $table->string('description'); $table->decimal('quantity',19,4); $table->decimal('unit_price',19,4); $table->decimal('tax_rate',19,4)->default(0); $table->decimal('subtotal',19,4); $table->decimal('tax_total',19,4); $table->decimal('total',19,4); $table->timestamps();
        });
        Schema::create('layaway_payments', function (Blueprint $table) {
            $table->id(); $table->foreignId('layaway_id')->constrained()->cascadeOnDelete(); $table->foreignId('company_id')->constrained()->restrictOnDelete(); $table->foreignId('branch_id')->constrained()->restrictOnDelete(); $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete(); $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount',19,4); $table->boolean('affects_cash_snapshot')->default(false); $table->decimal('cash_effect_amount',19,4)->default(0); $table->string('reference',150)->nullable(); $table->text('notes')->nullable(); $table->timestamp('paid_at'); $table->timestamps(); $table->index(['cash_session_id','payment_method_id']);
        });
        Schema::create('layaway_alerts', function (Blueprint $table) {
            $table->id(); $table->foreignId('layaway_id')->constrained()->cascadeOnDelete(); $table->foreignId('company_id')->constrained()->cascadeOnDelete(); $table->string('type',30); $table->timestamp('notified_at'); $table->timestamps(); $table->unique(['layaway_id','type']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('layaway_alerts'); Schema::dropIfExists('layaway_payments'); Schema::dropIfExists('layaway_items'); Schema::dropIfExists('layaways');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['layaway_validity_days','layaway_alert_days']));
    }
};
