<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_map_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmx_id')->unique();
            $table->string('tmx_section', 10)->default('TMNF');
            $table->enum('status', ['pending', 'added'])->default('pending');
            $table->string('discord_author', 100);
            $table->string('approved_by', 100)->nullable();
            $table->string('message_id', 32)->nullable();   // the suggesting message (reactions are read from it)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_map_suggestions');
    }
};
