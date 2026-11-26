<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_units', function (Blueprint $table) {
            // The certificate request's details, reused when the certificate is renewed.
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zatca_units', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
