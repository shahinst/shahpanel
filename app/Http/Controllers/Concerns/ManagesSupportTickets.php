<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\TicketStatus;
use App\Models\SupportDepartment;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

trait ManagesSupportTickets
{
    public function indexTickets(Request $request, SupportTicketService $ticketService): View
    {
        if (! Schema::hasTable('support_tickets')) {
            return view('shared.tickets.migration-required', [
                'panel' => $this->supportPanel(),
            ]);
        }

        return view('shared.tickets.index', [
            'panel' => $this->supportPanel(),
            'tickets' => $ticketService->ticketsFor($request->user()),
        ]);
    }

    public function createTicket(Request $request, SupportTicketService $ticketService): View
    {
        return view('shared.tickets.create', [
            'panel' => $this->supportPanel(),
            'departments' => $ticketService->departmentsFor($request->user()),
        ]);
    }

    public function storeTicket(Request $request, SupportTicketService $ticketService): RedirectResponse
    {
        // exists سراسری بود و هر شناسه‌ی دپارتمان معتبری را می‌پذیرفت، پس می‌شد تیکت
        // را در صف پشتیبانی نماینده‌ی یک تنانت دیگر ثبت کرد و آن نماینده از طریق
        // canAccess حق خواندن و پاسخ روی آن می‌گرفت. همان مجموعه‌ای که فرم ساخت را
        // می‌سازد، اینجا هم مرز اعتبارسنجی است.
        $allowedDepartmentIds = $ticketService->departmentsFor($request->user())
            ->pluck('id')
            ->all();

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'department_id' => ['nullable', 'integer', Rule::in($allowedDepartmentIds)],
        ]);

        $ticketService->create($request->user(), $validated);

        return redirect()
            ->route($this->supportPanel().'.tickets.index')
            ->with('success', __('tickets.created'));
    }

    public function showTicket(Request $request, SupportTicket $ticket, SupportTicketService $ticketService): View
    {
        if (! $ticketService->canAccess($request->user(), $ticket)) {
            abort(403);
        }

        $ticket->load(['requester', 'assignee', 'department', 'messages.user', 'sourceTicket']);

        return view('shared.tickets.show', [
            'panel' => $this->supportPanel(),
            'ticket' => $ticket,
            'statuses' => TicketStatus::cases(),
        ]);
    }

    public function replyTicket(Request $request, SupportTicket $ticket, SupportTicketService $ticketService): RedirectResponse
    {
        if (! $ticketService->canAccess($request->user(), $ticket)) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $ticketService->addMessage($ticket, $request->user(), $validated['body']);

        return back()->with('success', __('tickets.replied'));
    }

    public function updateTicketStatus(Request $request, SupportTicket $ticket, SupportTicketService $ticketService): RedirectResponse
    {
        if (! $ticketService->canAccess($request->user(), $ticket)) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
        ]);

        $ticketService->updateStatus($ticket, TicketStatus::from($validated['status']), $request->user());

        return back()->with('success', __('tickets.status_updated'));
    }

    public function escalateTicket(Request $request, SupportTicket $ticket, SupportTicketService $ticketService): RedirectResponse
    {
        if (! $ticketService->canAccess($request->user(), $ticket)) {
            abort(403);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $ticketService->escalateToAdmin($ticket, $request->user(), $validated['note']);

        return redirect()
            ->route($this->supportPanel().'.tickets.index')
            ->with('success', __('tickets.escalated'));
    }

    public function indexDepartments(Request $request, SupportTicketService $ticketService): View
    {
        $departments = SupportDepartment::query()
            ->where('owner_user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return view('shared.tickets.departments.index', [
            'panel' => $this->supportPanel(),
            'departments' => $departments,
        ]);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        SupportDepartment::query()->create([
            'owner_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', __('app.saved'));
    }

    public function updateDepartment(Request $request, SupportDepartment $department): RedirectResponse
    {
        $this->assertOwnsDepartment($department, $request->user());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $department->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', __('app.saved'));
    }

    public function destroyDepartment(Request $request, SupportDepartment $department): RedirectResponse
    {
        $this->assertOwnsDepartment($department, $request->user());

        $department->delete();

        return back()->with('success', __('tickets.department_deleted'));
    }

    protected function assertOwnsDepartment(SupportDepartment $department, \App\Models\User $user): void
    {
        if ((int) $department->owner_user_id !== (int) $user->id) {
            abort(403);
        }
    }

    abstract protected function supportPanel(): string;
}
