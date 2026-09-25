<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('prints_label')->default(true)->change();
        });

        DB::table('products')
            ->where(function ($query) {
                $query->where('prints_label', false)->orWhereNull('prints_label');
            })
            ->update(['prints_label' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('prints_label')->default(false)->change();
        });
    }
};
