<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UnlockSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The price the player confirmed on screen. It is never what gets charged —
     * the stored price is — it only lets the server refuse if the price moved
     * between the confirmation and the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'expected_price' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_price.required' => 'Confirm the price to unlock this section.',
        ];
    }
}
