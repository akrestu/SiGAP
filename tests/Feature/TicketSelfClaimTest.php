<?php

namespace Tests\Feature;

use App\Exceptions\TicketWorkflowException;
use App\Models\Department;
use App\Models\JobPlan;
use App\Models\JobSite;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\TicketWorkflow;
use App\Support\WorkingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ambil sendiri dari antrian site, ambil alih saat cuti, mode dispatcher per site, dan eskalasi tiket yang belum diambil.
 * Waktu uji: Senin 2026-10-12 09:00 (jam kerja). SLA Mendesak default: respons 30 menit.
 */
class TicketSelfClaimTest extends TestCase
{
    use RefreshDatabase;

    private TicketWorkflow $workflow;

    private JobSite $siteA;

    private JobSite $siteB;

    private User $lead;

    private User $techA;

    private User $techA2;

    private User $techB;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        WorkingCalendar::flush();
        Carbon::setTestNow('2026-10-12 09:00:00');

        foreach (['admin' => 0, 'level4' => 4, 'level1' => 1] as $slug => $level) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'level' => $level]);
        }
        Department::create(['name' => 'IT Department', 'code' => 'IT']);
        Department::create(['name' => 'HR Department', 'code' => 'HR']);
        TicketCategory::create(['name' => 'IT Support', 'is_active' => true]);
        $this->siteA = JobSite::create(['name' => 'Site A', 'code' => 'SA', 'is_active' => true]);
        $this->siteB = JobSite::create(['name' => 'Site B', 'code' => 'SB', 'is_active' => true]);

        $this->workflow = app(TicketWorkflow::class);
        $this->lead = $this->user('level4', 'IT', $this->siteA);
        $this->techA = $this->user('level1', 'IT', $this->siteA);
        $this->techA2 = $this->user('level1', 'IT', $this->siteA);
        $this->techB = $this->user('level1', 'IT', $this->siteB);
        $this->requester = $this->user('level1', 'HR', $this->siteA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $dept, JobSite $site): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
            'department_id' => Department::where('code', $dept)->value('id'),
            'job_site_id' => $site->id,
        ]);
    }

    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::createWithNumber($overrides + [
            'requester_user_id' => $this->requester->id,
            'requester_name' => $this->requester->name,
            'requester_department' => 'HR Department',
            'job_site_id' => $this->siteA->id,
            'category_id' => TicketCategory::value('id'),
            'title' => 'Printer rusak',
            'description' => 'Tidak bisa print',
            'priority' => 'urgent',
        ])->refresh();
    }

    public function test_new_ticket_is_announced_to_available_site_team_only(): void
    {
        $this->techA2->update(['away_until' => today()->addDays(2)]);

        $ticket = $this->ticket();

        $this->assertTrue(Notification::where('user_id', $this->techA->id)->where('ticket_id', $ticket->id)->where('type', 'ticket_created')->exists());
        $this->assertFalse(Notification::where('user_id', $this->techA2->id)->where('ticket_id', $ticket->id)->exists(), 'teknisi cuti dilewati');
        $this->assertFalse(Notification::where('user_id', $this->techB->id)->where('ticket_id', $ticket->id)->exists(), 'site lain dilewati');
    }

    public function test_site_technician_of_any_level_can_claim_from_queue(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->techA)->get(route('tickets.index'))->assertOk()->assertSee($ticket->title);
        $this->actingAs($this->techA)->post(route('tickets.claim', $ticket))->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame($this->techA->id, $ticket->assigned_to);
        $this->assertSame(Ticket::STATUS_ASSIGNED, $ticket->status);
        $this->assertNotNull($ticket->first_responded_at);
        $this->assertSame($this->techA->id, JobPlan::find($ticket->job_plan_id)->assignee_id);
        $this->assertStringContainsString('Diambil dari antrian', $ticket->histories()->reorder()->latest('id')->value('note'));
        $this->assertTrue(Notification::where('user_id', $this->requester->id)->where('message', 'like', '%sedang ditangani oleh '.$this->techA->name.'%')->exists());
    }

    public function test_technician_of_other_site_cannot_see_or_claim(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->techB)->get(route('tickets.index'))->assertOk()->assertDontSee($ticket->title);
        $this->actingAs($this->techB)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($this->techB)->post(route('tickets.claim', $ticket))->assertForbidden();
        $this->assertNull($ticket->refresh()->assigned_to);
    }

    public function test_non_triager_technician_cannot_open_ticket_dashboard(): void
    {
        $this->actingAs($this->techA)->get(route('tickets.dashboard'))->assertForbidden();
        $this->actingAs($this->lead)->get(route('tickets.dashboard'))->assertOk();
    }

    public function test_second_claim_on_stale_ticket_is_rejected(): void
    {
        $ticket = $this->ticket();
        $stale = Ticket::find($ticket->id);

        $this->workflow->claim($ticket, $this->techA);

        $this->expectException(TicketWorkflowException::class);
        $this->workflow->claim($stale, $this->techA2);
    }

    public function test_ticket_awaiting_supervisor_approval_cannot_be_claimed(): void
    {
        $ticket = $this->ticket(['approval_status' => Ticket::APPROVAL_WAITING_UPLOAD]);

        $this->actingAs($this->techA)->post(route('tickets.claim', $ticket))->assertForbidden();
    }

    public function test_dispatcher_site_disables_self_claim_but_triager_can_still_assign(): void
    {
        $this->siteA->update(['ticket_self_claim' => false]);
        $ticket = $this->ticket();

        $this->assertFalse(Notification::where('user_id', $this->techA->id)->where('ticket_id', $ticket->id)->exists());
        $this->actingAs($this->techA)->post(route('tickets.claim', $ticket))->assertForbidden();

        $this->actingAs($this->lead)->post(route('tickets.assign', $ticket), [
            'assigned_to' => $this->techA->id,
            'due_date' => today()->addDay()->toDateString(),
        ])->assertRedirect();
        $this->assertSame($this->techA->id, $ticket->refresh()->assigned_to);
    }

    public function test_teammate_can_take_over_ticket_of_away_handler(): void
    {
        $ticket = $this->ticket();
        $this->workflow->claim($ticket, $this->techA);
        $ticket->refresh();

        $this->actingAs($this->techA2)->post(route('tickets.claim', $ticket))->assertForbidden();

        $this->techA->update(['away_until' => today()->addDays(3)]);
        $this->actingAs($this->techA2)->post(route('tickets.claim', $ticket->refresh()))->assertRedirect();

        $ticket->refresh();
        $this->assertSame($this->techA2->id, $ticket->assigned_to);
        $this->assertSame($this->techA2->id, $ticket->jobPlan->assignee_id, 'Job Plan aktif ikut dialihkan');
        $this->assertStringContainsString('sedang cuti', $ticket->histories()->reorder()->latest('id')->value('note'));
        $this->assertTrue(Notification::where('user_id', $this->techA->id)->where('message', 'like', '%diambil alih%')->exists());
    }

    public function test_setting_away_in_profile_announces_active_tickets(): void
    {
        $ticket = $this->ticket();
        $this->workflow->claim($ticket, $this->techA);

        $this->actingAs($this->techA)->patch(route('profile.availability'), [
            'away_until' => today()->addDays(5)->toDateString(),
            'away_note' => 'Cuti tahunan',
        ])->assertRedirect(route('profile.edit'));

        $this->assertTrue($this->techA->refresh()->isAway());
        $this->assertSame('Cuti tahunan', $this->techA->away_note);
        $this->assertTrue(Notification::where('user_id', $this->techA2->id)->where('ticket_id', $ticket->id)->where('message', 'like', '%bisa diambil alih%')->exists());

        $this->actingAs($this->techA)->patch(route('profile.availability'), ['clear' => 1])->assertRedirect();
        $this->assertFalse($this->techA->refresh()->isAway());
        $this->assertNull($this->techA->away_until);
    }

    public function test_future_leave_is_not_active_yet(): void
    {
        $this->actingAs($this->techA)->patch(route('profile.availability'), [
            'away_from' => today()->addDays(2)->toDateString(),
            'away_until' => today()->addDays(4)->toDateString(),
        ])->assertRedirect();

        $this->assertFalse($this->techA->refresh()->isAway());
        Carbon::setTestNow(today()->addDays(2)->setTime(9, 0));
        $this->assertTrue($this->techA->isAway());
    }

    public function test_away_form_is_rejected_for_non_ticket_staff(): void
    {
        $this->actingAs($this->requester)->patch(route('profile.availability'), [
            'away_until' => today()->addDay()->toDateString(),
        ])->assertForbidden();
    }

    public function test_unclaimed_ticket_is_escalated_once_after_half_of_response_sla(): void
    {
        $ticket = $this->ticket(); // Mendesak: respons sebelum 09:30

        Carbon::setTestNow('2026-10-12 09:10:00');
        $this->assertSame(0, $this->workflow->escalateUnclaimed());

        Carbon::setTestNow('2026-10-12 09:16:00');
        $this->assertSame(1, $this->workflow->escalateUnclaimed());
        $this->assertNotNull($ticket->refresh()->escalated_at);

        foreach ([$this->lead, $this->techA, $this->techA2] as $user) {
            $this->assertTrue(Notification::where('user_id', $user->id)->where('ticket_id', $ticket->id)->where('message', 'like', 'Eskalasi:%')->exists());
        }
        $this->assertFalse(Notification::where('user_id', $this->techB->id)->where('message', 'like', 'Eskalasi:%')->exists());

        $this->assertSame(0, $this->workflow->escalateUnclaimed(), 'hanya sekali per tiket');
    }

    public function test_claimed_ticket_is_not_escalated(): void
    {
        $ticket = $this->ticket();
        $this->workflow->claim($ticket, $this->techA);

        Carbon::setTestNow('2026-10-12 09:20:00');
        $this->assertSame(0, $this->workflow->escalateUnclaimed());
    }
}
