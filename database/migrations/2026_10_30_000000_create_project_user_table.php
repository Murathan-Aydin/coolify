<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer');
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->boolean('restricted_to_projects')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('restricted_to_projects');
        });
        Schema::dropIfExists('project_user');
    }
};
