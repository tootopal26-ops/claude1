<?php

namespace App\Http\Requests\Api;

use App\Support\MobileNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalises mobile to plain digits and folds email to lowercase
     * BEFORE validation runs, so `unique:users,mobile`/`unique:users,email`
     * check the same form that gets stored, and a mobile number sent as
     * "+91 8273093895" (or any other equivalent format) is validated and
     * stored exactly the same as "8273093895".
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('mobile'))) {
            $merge['mobile'] = MobileNumber::normalize($this->input('mobile'));
        }

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The letter requirement keeps a username from ever being a pure digit
            // string, so sign-in can tell a username apart from a mobile number
            // just by looking at it (see LoginIdentifier).
            'username' => ['required', 'string', 'max:32', 'alpha_dash', 'regex:/[A-Za-z]/', 'unique:users,username'],
            'mobile' => ['required', 'string', 'regex:/^\d{10}$/', 'unique:users,mobile'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'accept_terms' => ['required', 'accepted'],
            // The real validation (does the code exist, is it still eligible) happens in
            // ReferralService::applyReferral — kept loose here so that service can return a
            // specific, correct message instead of a generic format error.
            'referral_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile.regex' => 'Enter a valid 10-digit mobile number.',
            'username.regex' => 'Username must contain at least one letter.',
        ];
    }
}
