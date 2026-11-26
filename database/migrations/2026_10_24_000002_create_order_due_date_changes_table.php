<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a bill's delivery date is moved: from when, to when, why, and by
 * whom. The bill itself carries only the current date; this is how the counter
 * and the floor can still tell that a sheet printed earlier shows an old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_due_date_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 500);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_due_date_changes');
    }
};
