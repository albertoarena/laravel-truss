<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fixture schema exercising the full range the introspection layer must handle:
 * simple tables, single and composite foreign keys, single and composite
 * indexes, a composite-primary-key pivot, a self-referential foreign key,
 * a primary-key-less table, and nullable/defaulted columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->boolean('published')->default(false);
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'published']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('country_code');
            $table->string('region_code');
            $table->unique(['country_code', 'region_code']);
        });

        Schema::create('region_stats', function (Blueprint $table) {
            $table->id();
            $table->string('country_code');
            $table->string('region_code');
            $table->unsignedBigInteger('population')->default(0);
            $table->foreign(['country_code', 'region_code'])
                ->references(['country_code', 'region_code'])
                ->on('regions');
        });

        Schema::create('logs', function (Blueprint $table) {
            $table->string('body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
        Schema::dropIfExists('region_stats');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
    }
};
