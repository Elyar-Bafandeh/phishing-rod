<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scan_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index(); // result_json, dom_html, screenshot, other
            $table->text('storage_path')->nullable();
            $table->text('external_url')->nullable();
            $table->string('sha256')->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('content_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_artifacts');
    }
};
