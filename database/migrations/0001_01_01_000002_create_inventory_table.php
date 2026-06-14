<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unsignedInteger('stock_actual')->default(0);
            $table->unsignedInteger('safety_stock')->default(0);
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->date('last_restock_date')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 25);
            $table->string('detail', 128)->nullable();
            $table->unsignedInteger('lead_time_days')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        Schema::create('supplier_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_price')->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('products');
    }
};
