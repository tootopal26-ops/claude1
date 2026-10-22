<?php

namespace App\Http\Requests\Api;

use App\Rules\DobNotInExclusionWindow;
use App\Rules\EligibleJurisdiction;
use App\Rules\MeetsMinimumAge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:32', 'alpha_dash', 'unique:users,username'],
            'mobile' => ['required', 'string', 'max:20', 'unique:users,mobile'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'date_of_birth' => ['required', 'date', 'before:today', new MeetsMinimumAge, new DobNotInExclusionWindow],
            'state' => ['required', 'string', 'max:100', new EligibleJurisdiction],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'accept_terms' => ['required', 'accepted'],
        ];
    }
}
