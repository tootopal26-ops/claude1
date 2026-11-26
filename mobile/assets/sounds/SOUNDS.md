# Mini Max sound library

The app has **one** sound: the tap click. Every other sound effect (rewards, coins, success /
error / warning chimes, notifications, unlocks, sheet open/close, selection and toggle ticks)
was removed on 2026-11-26. Haptic feedback is separate and unchanged.

| File | Used for |
|---|---|
| `click.mp3` | Every tap: buttons, cards and rows, bottom tabs, segmented controls, switches, option rows in Settings, and the "Preview sound" button |

## Source and licence — ⚠ to be confirmed by the project owner

`click.mp3` is **not** original work and is **not** CC0. It is a third-party sound that was
supplied by the project owner, first as `futuristic-ui-digital-click-davies-aguirre-1-00-00.zip`
and then as a trimmed copy of it, `click_trimmed.mp3` (the leading silence removed). The
trimmed copy is what is committed here, byte for byte. It is a modified version of the
original, so any licence terms for the original apply to it as well.

- Title (ID3 tag): *Futuristic UI - digital click*
- Artist (ID3 tag): *Davies Aguirre*
- Licence: **not recorded.** Nothing in the file or the zip states one. Before this app is
  distributed, whoever supplied the sound must confirm that its licence allows shipping it
  inside an app (and whether credit to the artist is required), then record the licence and the
  place it was obtained here, and add any required credit to the app.

## File details

0.26 s, MP3 (192 kbps), 44.1 kHz stereo, 14 KB, peak −12.6 dB. There is no silence before the
click: with the file's gapless header applied (it records 576 samples of encoder delay and 971
of end padding, which Android's player and ffmpeg both honour) the first audible sample is at
about 5 ms and the peak at about 8 ms, so the click lands essentially with the tap. The
audible part is about 115 ms; the rest is a quiet tail. The 14 KB includes an embedded
cover-art image.

## How it is played

`src/sound/SoundContext.tsx` is the only place sound is played. `playClick`, `playSelection`
and `playToggle` play `click.mp3` (plus their haptic). The other helpers (`playSuccess`,
`playReward`, `playCoin`, ...) are haptic-only so existing call sites keep their feel without a
sound. Playback preloads the file once, replays it from the start, drops a repeat within 55 ms,
mixes with other audio instead of interrupting it, and respects Settings → Sound & Haptics
(on/off and volume).

## Previously

The app used 12 short sounds synthesised by `scripts/generate-sounds.py` (CC0). Those files and
the script were removed; they remain in git history.
