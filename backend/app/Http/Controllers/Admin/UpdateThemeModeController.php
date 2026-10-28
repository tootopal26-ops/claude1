<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Portal;
use App\Support\ThemeMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Saves the signed-in account's Day / Night mode for the portal it is in.
 * The toggle has already switched the page; this only remembers the choice.
 */
class UpdateThemeModeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(ThemeMode::options())],
        ]);

        ThemeMode::save(Auth::guard('staff')->user(), Portal::fromRequest($request), $validated['mode']);

        return response()->noContent();
    }
}
