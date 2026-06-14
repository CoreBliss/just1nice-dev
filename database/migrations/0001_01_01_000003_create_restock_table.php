<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->date('restock_date');
            $table->string('note', 256)->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('restock_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restock_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('purchase_price');
            $table->unsignedBigInteger('total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_order_items');
        Schema::dropIfExists('restock_orders');
    }
};
