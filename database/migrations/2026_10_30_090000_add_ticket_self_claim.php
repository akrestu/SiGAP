<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiket "ambil sendiri": teknisi tim IT site mengambil tiket dari antrian tanpa menunggu triager,
 * status cuti teknisi (diisi sendiri di profil), dan penanda eskalasi tiket yang belum diambil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('away_from')->nullable()->after('notification_preferences');
            $table->date('away_until')->nullable()->after('away_from');
            $table->string('away_note', 255)->nullable()->after('away_until');
        });

        Schema::table('job_sites', function (Blueprint $table) {
            // false = mode dispatcher (HO/RO): hanya triager yang menugaskan
            $table->boolean('ticket_self_claim')->default(true)->after('is_active');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('response_breached');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('escalated_at'));
        Schema::table('job_sites', fn (Blueprint $table) => $table->dropColumn('ticket_self_claim'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['away_from', 'away_until', 'away_note']));
    }
};
