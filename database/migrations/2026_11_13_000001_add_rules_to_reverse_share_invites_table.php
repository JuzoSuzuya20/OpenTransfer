<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The rules the member sets on a file request: a password the guest gives before dropping
// anything (hashed), and how long the files received stay online. Both empty on the requests
// that exist: they open with their link alone and keep the default lifetime of a share.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reverse_share_invites', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->unsignedSmallInteger('keep_files_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reverse_share_invites', function (Blueprint $table) {
            $table->dropColumn(['password', 'keep_files_days']);
        });
    }
};
