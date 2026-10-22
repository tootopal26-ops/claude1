<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The Mini Max wordmark. public/images/logo.png is the user-supplied
 * brand artwork, cut out onto a transparent background.
 */
class LogoAsset extends Component
{
    public function __construct(public int $height = 32) {}

    public function render(): View
    {
        return view('components.logo-asset');
    }
}
