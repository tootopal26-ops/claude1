<?php

namespace App\Http\Middleware;

use App\Domain\Sections\SectionUnlockService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a priced section's data behind its unlock on the server, so a lock is
 * never only a picture in the app. A section that is free (never priced, or paid
 * unlocking switched off) and one this player already bought pass straight through.
 *
 * Usage: ->middleware('section.unlocked:leaderboard')
 */
class EnsureSectionUnlocked
{
    public function __construct(private readonly SectionUnlockService $sections) {}

    public function handle(Request $request, Closure $next, string $sectionKey): Response
    {
        $user = $request->user();

        if ($user === null || $this->sections->isAccessible($user, $sectionKey)) {
            return $next($request);
        }

        $status = $this->sections->statusFor($user, $sectionKey);

        return response()->json([
            'message' => 'Unlock '.($status['title'] ?? 'this section').' to use it.',
            'error' => 'section_locked',
            'section' => $status,
        ], 403);
    }
}
