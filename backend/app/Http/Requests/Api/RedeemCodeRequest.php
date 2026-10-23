<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RedeemCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the code. A reward amount (or anything else) in the body is ignored:
     * the reward comes from the stored campaign.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter your 5-character code.',
        ];
    }
}
