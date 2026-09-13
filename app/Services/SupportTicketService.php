<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\SupportDepartment;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SupportTicketService
{
    public function canAccess(User $user, SupportTicket $ticket): bool
    {
        if ((int) $ticket->requester_user_id === (int) $user->id) {
            return true;
        }

        if ((int) $ticket->assignee_user_id === (int) $user->id) {
            return true;
        }

        if ($user->role === UserRole::Agent) {
            return User::query()
                ->where('id', $ticket->requester_user_id)
                ->where('parent_id', $user->id)
                ->where('role', UserRole::Seller->value)
                ->exists();
        }

        if ($user->role === UserRole::Admin) {
            $ticket->loadMissing('requester');

            return $ticket->requester?->role === UserRole::Agent;
        }

        return false;
    }

    public function ticketsFor(User $user): Collection
    {
        return SupportTicket::query()
            ->with(['requester', 'assignee', 'department'])
            ->where(function ($query) use ($user): void {
                $query->where('requester_user_id', $user->id)
                    ->orWhere('assignee_user_id', $user->id);

                if ($user->role === UserRole::Admin) {
                    $query->orWhereHas('requester', fn ($q) => $q->where('role', UserRole::Agent->value));
                }

                if ($user->role === UserRole::Agent) {
                    $sellerIds = User::query()
                        ->where('parent_id', $user->id)
                        ->where('role', UserRole::Seller->value)
                        ->pluck('id');

                    $query->orWhereIn('requester_user_id', $sellerIds);
                }
            })
            ->latest('last_activity_at')
            ->latest('id')
            ->get();
    }

    public function departmentsFor(User $requester): Collection
    {
        $ownerId = match ($requester->role) {
            UserRole::Seller => $requester->parent_id,
            default => $requester->id,
        };

        if ($ownerId === null) {
            return collect();
        }

        return SupportDepartment::query()
            ->where('owner_user_id', $ownerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function create(User $requester, array $data): SupportTicket
    {
        $assignee = $this->defaultAssignee($requester, $data['department_id'] ?? null);

        if ($assignee === null) {
            throw new InvalidArgumentException(__('tickets.no_assignee'));
        }

        $ticket = SupportTicket::query()->create([
            'ticket_number' => $this->generateNumber(),
            'subject' => $data['subject'],
            'status' => TicketStatus::Open,
            'requester_user_id' => $requester->id,
            'assignee_user_id' => $assignee->id,
            'department_id' => $data['department_id'] ?? null,
            'last_activity_at' => now(),
        ]);

        $this->addMessage($ticket, $requester, (string) $data['body'], false);

        return $ticket->fresh(['requester', 'assignee', 'department', 'messages.user']);
    }

    public function escalateToAdmin(SupportTicket $source, User $agent, string $note): SupportTicket
    {
        if ($agent->role !== UserRole::Agent) {
            throw new InvalidArgumentException(__('tickets.cannot_escalate'));
        }

        $admin = User::query()->role(UserRole::Admin)->orderBy('id')->first();

        if ($admin === null) {
            throw new InvalidArgumentException(__('tickets.no_admin'));
        }

        $ticket = SupportTicket::query()->create([
            'ticket_number' => $this->generateNumber(),
            'subject' => '[ارجاع] '.$source->subject,
            'status' => TicketStatus::Open,
            'requester_user_id' => $agent->id,
            'assignee_user_id' => $admin->id,
            'department_id' => $source->department_id,
            'source_ticket_id' => $source->id,
            'last_activity_at' => now(),
        ]);

        $this->addMessage($ticket, $agent, $note, false);
        $source->update(['status' => TicketStatus::InProgress, 'last_activity_at' => now()]);

        return $ticket->fresh(['requester', 'assignee', 'department', 'messages.user']);
    }

    public function addMessage(SupportTicket $ticket, User $user, string $body, bool $internal = false): SupportTicketMessage
    {
        $message = SupportTicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $body,
            'is_internal' => $internal,
        ]);

        if ($ticket->status === TicketStatus::Open) {
            $ticket->status = TicketStatus::InProgress;
        }

        $ticket->update(['last_activity_at' => now()]);

        return $message;
    }

    public function updateStatus(SupportTicket $ticket, TicketStatus $status, User $actor): SupportTicket
    {
        if ($status === TicketStatus::Open && $ticket->isClosed() && $actor->role !== UserRole::Admin) {
            throw new InvalidArgumentException(__('tickets.cannot_reopen'));
        }

        $payload = [
            'status' => $status,
            'last_activity_at' => now(),
        ];

        if ($status === TicketStatus::Resolved) {
            $payload['resolved_at'] = now();
        }

        if ($status === TicketStatus::Closed) {
            $payload['closed_at'] = now();
        }

        if ($status === TicketStatus::Open && $ticket->isClosed()) {
            $payload['reopened_at'] = now();
            $payload['closed_at'] = null;
            $payload['resolved_at'] = null;
        }

        $ticket->update($payload);

        return $ticket->fresh();
    }

    public function autoCloseStaleTickets(): int
    {
        $days = max(1, (int) Setting::getValue('ticket_auto_close_days', '7'));
        $cutoff = now()->subDays($days);
        $count = 0;

        SupportTicket::query()
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value, TicketStatus::Resolved->value])
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_activity_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->where('status', TicketStatus::Resolved->value)
                            ->where('resolved_at', '<=', $cutoff);
                    });
            })
            ->chunkById(100, function ($tickets) use (&$count): void {
                foreach ($tickets as $ticket) {
                    $ticket->update([
                        'status' => TicketStatus::Closed,
                        'closed_at' => now(),
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    protected function defaultAssignee(User $requester, ?int $departmentId): ?User
    {
        if ($departmentId) {
            $department = SupportDepartment::query()->find($departmentId);

            if ($department) {
                return $department->owner;
            }
        }

        return match ($requester->role) {
            UserRole::Seller => $requester->parent,
            UserRole::Agent => User::query()->role(UserRole::Admin)->orderBy('id')->first(),
            UserRole::Admin => $requester,
            default => null,
        };
    }

    protected function generateNumber(): string
    {
        do {
            $number = 'TKT-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (SupportTicket::query()->where('ticket_number', $number)->exists());

        return $number;
    }
}
