<?php

namespace App\Http\Requests\Api;

use App\Models\Tournament;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class JoinTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Tournament $tournament */
        $tournament = $this->route('tournament');
        $requiredCount = $tournament->requiredPlayerCount();

        return [
            'players' => ['required', 'array', "size:{$requiredCount}"],
            'players.*.in_game_name' => ['required', 'string', 'max:20'],
            'players.*.uid' => ['nullable', 'string', 'max:12'],
        ];
    }
}
