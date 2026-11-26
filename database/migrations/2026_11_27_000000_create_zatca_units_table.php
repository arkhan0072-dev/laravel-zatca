<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per EGS unit onboarded with ZATCA. Keys and secrets are stored encrypted (APP_KEY).
        Schema::create('zatca_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit')->unique();
            $table->string('environment', 16); // sandbox, simulation or production
            $table->string('status', 16); // compliance, active
            $table->text('private_key');
            $table->text('compliance_certificate');
            $table->text('compliance_secret');
            $table->string('compliance_request_id');
            $table->text('certificate')->nullable(); // production CSID
            $table->text('secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_units');
    }
};
