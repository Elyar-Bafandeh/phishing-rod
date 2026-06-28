<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('urlscan_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('urlscan_scan_id')->nullable()->index();
            $table->text('urlscan_result_url')->nullable();
            $table->string('urlscan_visibility')->default('unlisted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('result_fetched_at')->nullable();
            $table->timestamp('dom_fetched_at')->nullable();
            $table->json('raw_submission_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('urlscan_submissions');
    }
};
