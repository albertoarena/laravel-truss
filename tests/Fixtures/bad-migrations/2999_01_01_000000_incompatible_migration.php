<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A migration that cannot replay on SQLite: fulltext indexes are unsupported by
 * the SQLite grammar and throw at build time. The fallback must skip and record
 * this rather than aborting the whole snapshot. Named far in the future so it
 * runs after the good fixtures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bad_table', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->fullText('content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bad_table');
    }
};
