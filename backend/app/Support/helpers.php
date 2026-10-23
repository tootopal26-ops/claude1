<?php

use App\Support\Portal;

if (! function_exists('portal_route')) {
    /**
     * URL for a page ("tournaments.index") in the current portal, so one
     * Livewire view serves both /admin/* and /staff/* without hardcoding
     * either prefix.
     *
     * @param  array<string, mixed>|mixed  $parameters
     */
    function portal_route(string $page, mixed $parameters = [], bool $absolute = true): string
    {
        return Portal::route($page, $parameters, $absolute);
    }
}
