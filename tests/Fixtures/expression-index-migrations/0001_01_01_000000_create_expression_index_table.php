<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One table with an index that mixes a column and an expression, the shape
 * Laravel's own introspection reports as a plain index on the column alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('email');
            // The doubled parentheses are what MySQL requires for an expression
            // key part, and SQLite and Postgres accept them.
            $table->rawIndex('tenant_id, (lower(email))', 'members_tenant_email_index');
        });
    }
};
