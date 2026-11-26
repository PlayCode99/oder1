<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour a branch's job sheets and receipts are headed in. It used to live
 * in one browser's local storage, so every other machine printed every branch
 * in the default blue. Empty means that default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('header_color', 7)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('header_color');
        });
    }
};
