<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->text('submitted_url');
            $table->text('normalized_url')->nullable();

            $table->string('domain')->nullable()->index();

            $table->string('status')->default('queued')->index();

            $table->string('verdict')->nullable(); // safe, suspicious, phishing
            $table->decimal('confidence', 5, 2)->nullable(); // example: 94.25

            $table->text('error_message')->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
