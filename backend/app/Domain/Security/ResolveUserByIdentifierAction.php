<?php

namespace App\Domain\Security;

use App\Models\User;
use App\Support\LoginIdentifier;

/**
 * The one place that turns a sign-in identifier into a User — email,
 * mobile number, or username, in that order (see LoginIdentifier for how
 * it's classified). Used by login itself and by the repeated-failed-login
 * risk alert, so both agree on who an identifier refers to.
 */
class ResolveUserByIdentifierAction
{
    public function execute(string $identifier): ?User
    {
        if (trim($identifier) === '') {
            return null;
        }

        ['type' => $type, 'value' => $value] = LoginIdentifier::classify($identifier);

        return match ($type) {
            'email' => User::whereRaw('lower(email) = ?', [$value])->first(),
            'mobile' => User::where('mobile', $value)->first(),
            'username' => User::whereRaw('lower(username) = ?', [$value])->first(),
        };
    }
}
