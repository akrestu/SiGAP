<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflow;

class TicketPolicy
{
    /**
     * Triager: admin, atau anggota departemen layanan tiket (Admin → Departemen) dengan level >= triage_min_level.
     */
    public static function isTriager(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return (bool) $user->department?->handles_tickets
            && $user->getRoleLevel() >= config('tickets.triage_min_level');
    }

    /** Anggota tim layanan tiket (semua level) atau triager: bisa melihat antrian site-nya & mengambil tiket */
    public static function isHandler(User $user): bool
    {
        return self::isTriager($user) || (bool) $user->department?->handles_tickets;
    }

    /** Tiket termasuk site yang dicakup user. Tiket tanpa site / user tanpa site = tidak dibatasi site. */
    public static function coversTicketSite(User $user, Ticket $ticket): bool
    {
        return $ticket->job_site_id === null || ! $user->hasJobSites() || $user->coversJobSite($ticket->job_site_id);
    }

    /** Tim IT site tiket (bukan hanya yang ditugaskan) */
    private static function isSiteTeam(User $user, Ticket $ticket): bool
    {
        return self::isHandler($user) && self::coversTicketSite($user, $ticket);
    }

    /**
     * Rincian syarat triager untuk layar admin (diagnosis "kenapa menu Tiket Masuk tidak muncul").
     * Mengikuti aturan isTriager() di atas.
     *
     * @return array{ok: bool, checks: list<array{label: string, value: string, ok: bool}>}
     */
    public static function triageDiagnosis(User $user): array
    {
        $level = $user->getRoleLevel();
        $min = (int) config('tickets.triage_min_level');
        $department = $user->department;

        if ($user->isAdmin()) {
            return ['ok' => true, 'checks' => [['label' => 'Role', 'value' => 'Admin (akses penuh)', 'ok' => true]]];
        }

        $checks = [
            ['label' => 'Role terbaca', 'value' => ($user->role?->name ?? '-').' · slug "'.($user->role?->slug ?? '-').'" · level '.$level, 'ok' => $level >= $min],
            ['label' => 'Departemen', 'value' => $department ? $department->name.' (kode '.$department->code.', id '.$department->id.')' : 'Belum diisi', 'ok' => (bool) $department],
            ['label' => 'Tim layanan tiket', 'value' => $department?->handles_tickets ? 'Aktif' : 'Tidak aktif di departemen ini', 'ok' => (bool) $department?->handles_tickets],
        ];

        return ['ok' => self::isTriager($user), 'checks' => $checks];
    }

    public function viewAny(User $user): bool
    {
        return self::isHandler($user);
    }

    public function viewDashboard(User $user): bool
    {
        return self::isTriager($user);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return self::isTriager($user)
            || $ticket->assigned_to === $user->id
            || $ticket->requester_user_id === $user->id
            || self::isSiteTeam($user, $ticket);
    }

    public function assign(User $user): bool
    {
        return self::isTriager($user);
    }

    /**
     * Ambil sendiri dari antrian: tim IT site tiket (semua level), bila site tidak memakai mode dispatcher.
     * Tiket yang penanganannya sedang cuti boleh diambil alih.
     */
    public function claim(User $user, Ticket $ticket): bool
    {
        return self::isSiteTeam($user, $ticket)
            && $ticket->assigned_to !== $user->id
            && ($ticket->allowsSelfClaim() || self::isTriager($user))
            && TicketWorkflow::canBeClaimed($ticket);
    }

    public function updateStatus(User $user, Ticket $ticket): bool
    {
        // Pemohon tidak boleh mengubah status sendiri (konfirmasi selesai ditangani terpisah di fase lifecycle)
        return self::isTriager($user) || $ticket->assigned_to === $user->id;
    }

    public function addComment(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /** Tim IT (triager / handler) boleh melihat & menulis catatan internal */
    public function viewInternal(User $user, Ticket $ticket): bool
    {
        return self::isTriager($user) || $ticket->assigned_to === $user->id || self::isSiteTeam($user, $ticket);
    }

    /** Pemohon: konfirmasi selesai, buka kembali, atau batalkan tiketnya sendiri */
    public function respond(User $user, Ticket $ticket): bool
    {
        return $ticket->requester_user_id !== null && $ticket->requester_user_id === $user->id;
    }

    /** Verifikasi bukti persetujuan atasan: tim IT level 4 ke atas / admin */
    public function verifyApproval(User $user, Ticket $ticket): bool
    {
        return self::isTriager($user);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }
}
