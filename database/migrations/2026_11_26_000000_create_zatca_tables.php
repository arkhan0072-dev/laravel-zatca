<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per EGS unit: the head of its invoice chain, locked while an invoice is added.
        Schema::create('zatca_chains', function (Blueprint $table) {
            $table->string('unit')->primary();
            $table->unsignedBigInteger('counter');
            $table->string('last_hash', 100);
            $table->timestamps();
        });

        Schema::create('zatca_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('unit');
            $table->unsignedBigInteger('counter'); // ICV
            $table->uuid('uuid')->unique();
            $table->string('previous_hash', 100); // PIH
            $table->string('hash', 100);
            $table->longText('xml');
            $table->text('qr')->nullable(); // base64 TLV, printed on the invoice
            $table->string('status', 16)->default('pending'); // pending, reported, cleared, rejected
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->json('zatca_response')->nullable();
            $table->longText('cleared_xml')->nullable(); // ZATCA's stamped copy of a cleared invoice
            $table->timestamps();
            $table->unique(['unit', 'counter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_invoices');
        Schema::dropIfExists('zatca_chains');
    }
};
