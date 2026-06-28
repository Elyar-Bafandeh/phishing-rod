<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('model_type')->nullable(); // xgboost, random_forest, neural_network, etc.
            $table->string('feature_schema_version')->nullable();
            $table->json('metrics')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_versions');
    }
};
