<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Services\TicketWorkflow;

class TicketObserver
{
    public function created(Ticket $ticket): void
    {
        app(TicketWorkflow::class)->recordCreated($ticket);

        // Hanya triager (admin + tim IT level >= 4), bukan seluruh Level 5–8 lintas departemen
        $recipients = User::with(['role', 'department'])->get()
            ->filter(fn (User $user) => TicketPolicy::isTriager($user));

        foreach ($recipients as $user) {
            if (! $user->wantsNotification('ticket_created')) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'type' => 'ticket_created',
                'message' => 'Tiket baru masuk: ['.$ticket->ticket_number.'] '.$ticket->title,
                'is_read' => false,
            ]);
        }

        // Tim IT site (semua level) bisa langsung mengambil; tiket yang butuh persetujuan diumumkan setelah diverifikasi
        if (! $ticket->awaitingApproval()) {
            app(TicketWorkflow::class)->announceToQueue($ticket,
                'Tiket baru di antrian'.($ticket->jobSite ? ' '.$ticket->jobSite->name : '').': ['.$ticket->ticket_number.'] '.$ticket->title);
        }
    }
}
