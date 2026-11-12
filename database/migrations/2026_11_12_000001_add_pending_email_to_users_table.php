<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The address an account asked to move to, until the code sent there comes back (hashed, with
// its expiry). Added columns only: the accounts and their addresses stay as they are.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable();
            $table->string('pending_email_code')->nullable();
            $table->timestamp('pending_email_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_code', 'pending_email_expires_at']);
        });
    }
};
