<?php

namespace App\Livewire\Admin;

use App\Domain\Notifications\NotificationService;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class NotificationCompose extends Component
{
    public string $title = '';

    public string $body = '';

    public string $audienceType = 'all';

    /** @var array<int, int> */
    public array $selectedUserIds = [];

    public string $userSearch = '';

    public ?int $tournamentId = null;

    public string $idempotencyKey = '';

    public bool $sending = false;

    public bool $sent = false;

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();
    }

    /**
     * @return Collection<int, User>
     */
    public function getSearchResultsProperty()
    {
        if ($this->userSearch === '') {
            return collect();
        }

        return User::where('username', 'like', "%{$this->userSearch}%")
            ->whereNotIn('id', $this->selectedUserIds)
            ->orderBy('username')
            ->limit(20)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function getSelectedUsersProperty()
    {
        if ($this->selectedUserIds === []) {
            return collect();
        }

        return User::whereIn('id', $this->selectedUserIds)->orderBy('username')->get();
    }

    public function addUser(int $userId): void
    {
        if (! in_array($userId, $this->selectedUserIds, true)) {
            $this->selectedUserIds[] = $userId;
        }

        $this->userSearch = '';
    }

    public function removeUser(int $userId): void
    {
        $this->selectedUserIds = array_values(array_filter(
            $this->selectedUserIds,
            fn (int $id) => $id !== $userId,
        ));
    }

    public function send(): void
    {
        if ($this->sending || $this->sent) {
            return;
        }

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'audienceType' => ['required', 'in:all,selected_users,tournament_participants'],
            'selectedUserIds' => $this->audienceType === 'selected_users'
                ? ['required', 'array', 'min:1']
                : ['array'],
            'tournamentId' => $this->audienceType === 'tournament_participants'
                ? ['required', 'exists:tournaments,id']
                : ['nullable'],
        ]);

        $this->sending = true;

        $recipients = match ($this->audienceType) {
            'all' => User::where('is_banned', false)->get(),
            'selected_users' => User::whereIn('id', $this->selectedUserIds)->get(),
            'tournament_participants' => User::whereIn(
                'id',
                TournamentEntry::where('tournament_id', $this->tournamentId)
                    ->where('status', 'confirmed')
                    ->pluck('user_id'),
            )->get(),
        };

        app(NotificationService::class)->notify(
            recipients: $recipients,
            type: 'admin_broadcast',
            title: $this->title,
            body: $this->body,
            audienceType: $this->audienceType,
            audienceMeta: array_filter([
                'selected_user_ids' => $this->audienceType === 'selected_users' ? $this->selectedUserIds : null,
                'tournament_id' => $this->audienceType === 'tournament_participants' ? $this->tournamentId : null,
            ], fn ($value) => $value !== null),
            createdByStaff: Auth::guard('staff')->user(),
            idempotencyKey: $this->idempotencyKey,
        );

        $this->sending = false;
        $this->sent = true;

        session()->flash('status', 'Notification sent to '.$recipients->count().' player(s).');

        $this->redirectRoute('admin.notifications.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.notification-compose', [
            'tournaments' => Tournament::orderByDesc('scheduled_start_at')->limit(50)->get(),
        ]);
    }
}
