<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The one and only place that renders the coin graphic. Player-facing
 * code must always go through <x-coin-asset> rather than a raw ₹
 * symbol or a hand-rolled coin SVG — see the ChecksNoRawCurrencyTest
 * test, which enforces that across resources/views (excluding this
 * component's own markup) and app/Http/Resources.
 *
 * public/images/coin.png is currently a generated placeholder (a plain
 * gold circle) standing in for the real, user-supplied artwork.
 * Swapping that one file for the real PNG needs no code change here.
 */
class CoinAsset extends Component
{
    public function __construct(public int $size = 20) {}

    public function render(): View
    {
        return view('components.coin-asset');
    }
}
