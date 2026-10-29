# Mini Max sound library — manifest & licence

Every sound in this folder is **original work created for Mini Max**, synthesised from scratch
with numpy and encoded to AAC by `mobile/scripts/generate-sounds.py`. Nothing here is sampled,
recorded from an instrument, or downloaded from a library — each file is pure additive synthesis
(sine / triangle partials shaped by short envelopes). There are therefore **no third-party
assets and no external licences to honour**.

**Licence:** these sounds are released by the project under **CC0 1.0 (public-domain-equivalent)**.
They may be bundled in the app and shipped without attribution.

The design language is a premium phone UI: soft, precise, glassy clicks for everyday taps and
warm, restrained chimes for rewards — deliberately not arcade-loud. Everyday UI sounds are kept
to 30–180 ms and mixed quietly; reward / unlock sounds stay under 500 ms. Each file is 48 kHz
mono AAC at 96 kbps with a 2 ms fade on both edges (no clicks/pops) and is peak-limited below
0 dBFS (no clipping).

| File | Semantic use | Length | Peak | Character |
|---|---|---:|---:|---|
| `click.m4a` | Button press (`playClick`) | 60 ms | −16 dB | Softest in the set: a tiny filtered-noise tick + 2.2 kHz blip |
| `selection.m4a` | Bottom-nav / tab / list selection (`playSelection`) | 90 ms | — | Delicate glassy two-partial bell blip |
| `transition.m4a` | Modal / sheet / screen open (`playTransition`) | 140 ms | — | Soft upward pitch-glide "pop" |
| `close.m4a` | Modal / sheet close (`playClose`) | 120 ms | — | Quieter downward reverse pop |
| `toggle.m4a` | Switch / checkbox (`playToggle`) | 70 ms | — | Precise two-stage tactile tick |
| `success.m4a` | Confirmed success (`playSuccess`) | 340 ms | −5 dB | Warm rising two-note chime (G5→C6) |
| `error.m4a` | Failed / rejected (`playError`) | 200 ms | — | Soft descending two-note (A4→F4), never harsh |
| `warning.m4a` | Informational / unavailable (`playWarning`) | 170 ms | — | Neutral mid tone with a gentle wobble |
| `reward.m4a` | Reward claimed (`playReward`) | 460 ms | −7 dB | Bright rising triad arpeggio (C6-E6-G6) with shimmer |
| `coin.m4a` | Coins credited (`playCoin`) | 220 ms | −9 dB | Two quick high metallic pings (coin sparkle) |
| `unlock.m4a` | Lock release / ticket unlocked (`playUnlock`) | 300 ms | — | Soft low "thunk" then a rising confirm ping |
| `notification.m4a` | In-app notification (`playNotification`) | 250 ms | — | Gentle unobtrusive two-note soft bell (A5→D6) |

Total bundled size: ~41 KB across all 12 files (negligible APK impact).

## Regenerating

```
python3 -I mobile/scripts/generate-sounds.py mobile/assets/sounds
```

Requires `numpy` and `ffmpeg`. The script is deterministic (fixed RNG seed for the noise
transients), so regenerating produces byte-stable output.

The engine that plays these (`src/sound/`) loads each file once, replays it from the start,
debounces rapid repeats, mixes with other audio rather than interrupting it, and respects the
user's sound/volume/haptics settings (Settings → Sound & Haptics).
