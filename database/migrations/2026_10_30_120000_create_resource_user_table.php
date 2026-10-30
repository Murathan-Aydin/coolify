<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-resource roles for team members (fork): an application, service or database can give a member
 * another role than the one they have on its project (none, viewer, deployer or manager).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('resourceable');
            $table->string('role');
            $table->timestamps();
            $table->unique(['user_id', 'resourceable_type', 'resourceable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_user');
    }
};
