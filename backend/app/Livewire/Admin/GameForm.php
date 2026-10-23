<?php

namespace App\Livewire\Admin;

use App\Domain\Games\GameCardService;
use App\Domain\Media\ImageVariantProcessor;
use App\Models\Game;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class GameForm extends Component
{
    use WithFileUploads;

    public ?Game $game = null;

    public string $name = '';

    public string $description = '';

    public bool $isActive = true;

    public int $displayOrder = 1;

    public int $focalX = 50;

    public int $focalY = 50;

    public ?TemporaryUploadedFile $image = null;

    public function mount(?Game $game = null): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('games.manage'), 403);

        $this->game = $game;

        if ($game !== null) {
            $this->name = $game->name;
            $this->description = (string) $game->description;
            $this->isActive = $game->is_active;
            $this->displayOrder = max(1, $game->display_order);
            $this->focalX = $game->focal_x;
            $this->focalY = $game->focal_y;
        } else {
            $this->displayOrder = (int) Game::max('display_order') + 1;
        }
    }

    /**
     * Give feedback the moment a file is chosen; the same rules run again,
     * authoritatively, when the form is saved.
     */
    public function updatedImage(ImageVariantProcessor $processor): void
    {
        try {
            $this->validateOnly('image', $this->imageRules(), attributes: ['image' => 'image']);
            $processor->inspect($this->image, $this->focalX, $this->focalY);
        } catch (ValidationException $e) {
            $this->image = null;

            throw $e;
        }
    }

    public function save(GameCardService $cards)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('games.manage'), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'isActive' => ['boolean'],
            'displayOrder' => ['required', 'integer', 'min:1', 'max:9999'],
            'focalX' => ['required', 'integer', 'between:0,100'],
            'focalY' => ['required', 'integer', 'between:0,100'],
            ...$this->imageRules(),
        ], attributes: [
            'name' => 'title',
            'displayOrder' => 'display order',
            'isActive' => 'status',
        ]);

        $saved = $cards->save($this->game, [
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'is_active' => $this->isActive,
            'display_order' => $this->displayOrder,
            'focal_x' => $this->focalX,
            'focal_y' => $this->focalY,
        ], $this->image, $staff);

        session()->flash('status', "\"{$saved->name}\" saved.");

        return $this->redirectRoute(Portal::routeName('tournament-games.index'), navigate: false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function imageRules(): array
    {
        return [
            'image' => [$this->game?->image_asset_id ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function render()
    {
        $previewUrl = null;

        if ($this->image) {
            try {
                $previewUrl = $this->image->temporaryUrl();
            } catch (\Throwable) {
                $previewUrl = null;
            }
        }

        return view('livewire.admin.game-form', [
            'previewUrl' => $previewUrl,
            'currentUrl' => $this->game?->thumbnailAsset?->publicUrl(),
        ]);
    }
}
