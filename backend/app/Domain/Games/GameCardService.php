<?php

namespace App\Domain\Games;

use App\Domain\Media\ManagedImageService;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\StaffUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything an admin can do to a Tournament Games card. Each change is one
 * database transaction plus an audit entry; image files are written before the
 * record points at them and old files are removed only after the record has
 * been saved, so a failure never leaves a card without its picture or a file
 * that nothing references.
 */
class GameCardService
{
    public function __construct(private readonly ManagedImageService $images) {}

    /**
     * @param  array{name: string, description: ?string, is_active: bool, display_order: int, focal_x: int, focal_y: int}  $data
     *
     * @throws ValidationException
     */
    public function save(?Game $game, array $data, ?UploadedFile $image, StaffUser $actor): Game
    {
        $isNew = $game === null;
        $game ??= new Game;
        $stored = null;
        $replaced = [];

        try {
            DB::transaction(function () use ($game, $data, $image, $actor, &$stored, &$replaced) {
                $game->fill([
                    'name' => $data['name'],
                    'description' => $data['description'] ?: null,
                    'is_active' => $data['is_active'],
                    'display_order' => $data['display_order'],
                    'focal_x' => $data['focal_x'],
                    'focal_y' => $data['focal_y'],
                ])->save();

                if ($image !== null) {
                    $stored = $this->images->store($image, 'game_cards', $data['focal_x'], $data['focal_y'], $actor, $game);
                    $replaced = [$game->image_asset_id, $game->thumbnail_asset_id];

                    $game->image_asset_id = $stored['image']->id;
                    $game->thumbnail_asset_id = $stored['thumbnail']->id;
                    $game->save();
                }
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->images->discard($actor, $stored['image'], $stored['thumbnail']);
            }

            throw $e;
        }

        $this->images->discard($actor, ...array_filter($replaced));

        AuditLog::record($isNew ? 'game_card.created' : 'game_card.updated', $actor, $game, [
            'name' => $game->name,
            'is_active' => $game->is_active,
            'display_order' => $game->display_order,
            'image_replaced' => $image !== null,
        ]);

        return $game;
    }

    /**
     * @throws ValidationException when tournaments still use the game
     */
    public function delete(Game $game, StaffUser $actor): void
    {
        $tournaments = $game->tournaments()->count();

        if ($tournaments > 0) {
            throw ValidationException::withMessages([
                'delete' => "\"{$game->name}\" has {$tournaments} tournament(s) and can't be deleted. Disable the card instead to hide it from the app.",
            ]);
        }

        $assets = array_filter([$game->image_asset_id, $game->thumbnail_asset_id]);
        $snapshot = ['name' => $game->name, 'id' => $game->id];

        DB::transaction(fn () => $game->delete());

        $this->images->discard($actor, ...$assets);

        AuditLog::record('game_card.deleted', $actor, null, $snapshot);
    }

    public function setActive(Game $game, bool $active, StaffUser $actor): void
    {
        $game->update(['is_active' => $active]);

        AuditLog::record($active ? 'game_card.enabled' : 'game_card.disabled', $actor, $game, ['name' => $game->name]);
    }

    /**
     * Swaps a card with its neighbour and renumbers the whole list 1..n, so
     * display_order is always a clean, gap-free sequence after any move.
     */
    public function move(Game $game, int $direction, StaffUser $actor): void
    {
        DB::transaction(function () use ($game, $direction, $actor) {
            $ordered = Game::query()->orderBy('display_order')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $ordered->search(fn (Game $candidate) => $candidate->id === $game->id);
            $target = $index === false ? false : $index + $direction;

            if ($index === false || $target < 0 || $target >= $ordered->count()) {
                return;
            }

            $list = $ordered->all();
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];

            foreach (array_values($list) as $position => $item) {
                if ($item->display_order !== $position + 1) {
                    $item->update(['display_order' => $position + 1]);
                }
            }

            AuditLog::record('game_card.reordered', $actor, $game, ['direction' => $direction > 0 ? 'down' : 'up']);
        });
    }
}
