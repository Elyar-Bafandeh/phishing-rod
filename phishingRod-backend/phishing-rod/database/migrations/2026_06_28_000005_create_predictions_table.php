<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_name')->nullable();
            $table->string('model_version')->nullable();
            $table->string('label')->nullable(); // safe, suspicious, phishing
            $table->decimal('confidence', 5, 2)->nullable();
            $table->decimal('safe_probability', 5, 2)->nullable();
            $table->decimal('phishing_probability', 5, 2)->nullable();
            $table->json('raw_probabilities')->nullable();
            $table->json('explanation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
