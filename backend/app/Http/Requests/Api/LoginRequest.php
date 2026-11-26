<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts `identifier` (username, mobile number or email — the app does
 * no classification of its own) and, for app builds older than the
 * identifier-based sign-in, the legacy `mobile` field. Exactly one of
 * the two is required; a legacy build's validation error still comes
 * back keyed `mobile` (see errorKey()), never a new key it doesn't
 * understand.
 */
class LoginRequest extends FormRequest
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
        return [
            'identifier' => ['required_without:mobile', 'string'],
            'mobile' => ['required_without:identifier', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * The identifier exactly as typed — username, mobile number or
     * email — from whichever field the app sent.
     */
    public function identifierValue(): string
    {
        return (string) ($this->input('identifier') ?? $this->input('mobile'));
    }

    /**
     * Which field a validation error should be keyed under, so a legacy
     * app build (which only understands `mobile`) still gets an error
     * it can show against the field it submitted.
     */
    public function errorKey(): string
    {
        return $this->input('identifier') !== null ? 'identifier' : 'mobile';
    }
}
