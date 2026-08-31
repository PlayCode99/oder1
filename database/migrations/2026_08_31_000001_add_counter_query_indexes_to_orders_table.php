<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter page sorts orders by order_date DESC and paginates, and both the
 * counter and the production boards filter on order_date / due_date ranges.
 * Neither column was indexed, so every one of those queries was a full scan —
 * fine on a few hundred orders, painful once the table grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('order_date', 'orders_order_date_index');
            $table->index('due_date', 'orders_due_date_index');
            $table->index(['order_status', 'order_date'], 'orders_status_order_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_order_date_index');
            $table->dropIndex('orders_due_date_index');
            $table->dropIndex('orders_status_order_date_index');
        });
    }
};
