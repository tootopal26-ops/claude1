#!/usr/bin/env python3
"""
Generates Mini Max's original UI / reward sound library and encodes it to AAC (.m4a).

Every sound here is synthesised from scratch with numpy (sine / triangle partials shaped by
short envelopes) — nothing is sampled, recorded or downloaded, so the whole library is the
project's own original work, free to bundle and ship (treated as CC0 / public-domain-equivalent;
see mobile/assets/sounds/SOUNDS.md). The aesthetic target is a premium phone UI: soft, precise,
glassy clicks for everyday taps and warm, restrained chimes for rewards — never arcade-loud.

Design rules baked in:
  * Everyday UI sounds stay short (30-180 ms) and quiet; reward/unlock sounds stay under 500 ms.
  * Each sound is peak-limited then given a deliberate, perceptual gain so clicks sit well below
    chimes and nothing clips.
  * A few ms of fade in/out on every sound so there is no click/pop at the edges.

Usage: python3 -I generate-sounds.py <mobile/assets/sounds dir>
"""
import os
import subprocess
import sys
import wave

import numpy as np

SR = 48000


def t(ms: float) -> np.ndarray:
    return np.linspace(0, ms / 1000.0, max(1, int(SR * ms / 1000.0)), endpoint=False)


def sine(freq, ms, phase=0.0):
    return np.sin(2 * np.pi * freq * t(ms) + phase)


def triangle(freq, ms):
    x = t(ms)
    return 2 / np.pi * np.arcsin(np.sin(2 * np.pi * freq * x))


def env(sig, attack_ms=3.0, release_ms=None, hold=0.0):
    """Attack-hold-release amplitude envelope with a short exponential tail."""
    n = len(sig)
    release_ms = release_ms if release_ms is not None else (n / SR * 1000.0) * 0.7
    a = max(1, int(SR * attack_ms / 1000.0))
    r = max(1, int(SR * release_ms / 1000.0))
    e = np.ones(n)
    e[:a] = np.linspace(0, 1, a)
    if r < n:
        e[n - r:] = np.exp(-np.linspace(0, 5, r))
    return sig * e


def pad(sig, ms):
    """Pad with silence to a fixed length so every asset ends cleanly."""
    total = int(SR * ms / 1000.0)
    if len(sig) >= total:
        return sig[:total]
    return np.concatenate([sig, np.zeros(total - len(sig))])


def noise_click(ms, cutoff=0.5):
    """A soft filtered-noise transient for the body of a click."""
    x = np.random.default_rng(7).standard_normal(len(t(ms)))
    # one-pole low-pass to take the harshness off white noise
    y = np.zeros_like(x)
    for i in range(1, len(x)):
        y[i] = y[i - 1] + cutoff * (x[i] - y[i - 1])
    return y


def mix(*parts):
    n = max(len(p) for p in parts)
    out = np.zeros(n)
    for p in parts:
        out[: len(p)] += p
    return out


def finalize(sig, gain_db):
    peak = np.max(np.abs(sig)) or 1.0
    sig = sig / peak  # normalise to full scale first
    sig = sig * (10 ** (gain_db / 20.0))  # then the deliberate, consistent per-sound level
    # a 2 ms fade at both ends guarantees no edge click
    f = max(1, int(SR * 2 / 1000.0))
    sig[:f] *= np.linspace(0, 1, f)
    sig[-f:] *= np.linspace(1, 0, f)
    return np.clip(sig, -1.0, 1.0)


# name -> (samples, perceptual gain in dB relative to full scale)
def build():
    out = {}

    # A. Button press — short, soft digital click (~45 ms). Quietest sound in the set.
    click = mix(env(noise_click(18, 0.35) * 0.6, 1, 14), env(sine(2200, 22), 1, 18) * 0.5)
    out['click'] = (pad(click, 60), -15)

    # Bottom-nav selection — a delicate glassy blip (two bell partials).
    sel = mix(env(sine(1760, 70), 2, 60), env(sine(3520, 70), 2, 45) * 0.35)
    out['selection'] = (pad(sel, 90), -13)

    # Modal / screen open — a soft refined upward "pop" (gentle pitch glide).
    gl = t(120)
    up = np.sin(2 * np.pi * (620 + 260 * gl / gl[-1]) * gl)
    out['transition'] = (pad(env(up, 6, 90), 140), -12)

    # Modal close — quieter reverse pop (downward glide).
    down = np.sin(2 * np.pi * (880 - 260 * gl / gl[-1]) * gl)
    out['close'] = (pad(env(down, 4, 80) * 0.8, 120), -15)

    # Toggle — a precise two-stage tactile tick.
    tog = mix(env(triangle(1200, 14), 1, 10) * 0.8, np.concatenate([np.zeros(int(SR * 0.012)), env(triangle(1700, 16), 1, 12) * 0.6]))
    out['toggle'] = (pad(tog, 70), -14)

    # Success — a warm two-note rising confirmation chime (G5 -> C6).
    s1 = env(mix(sine(784, 150), sine(1568, 150) * 0.25), 4, 120)
    s2 = np.concatenate([np.zeros(int(SR * 0.09)), env(mix(sine(1046, 220), sine(2093, 220) * 0.25), 4, 190)])
    out['success'] = (pad(mix(s1, s2), 340), -8)

    # Error — soft, low-intensity descending two-note (A4 -> F4), muted, never harsh.
    e1 = env(sine(440, 120), 4, 100)
    e2 = np.concatenate([np.zeros(int(SR * 0.08)), env(sine(349, 150), 4, 130)])
    out['error'] = (pad(mix(e1, e2) * 0.9, 200), -11)

    # Warning — a single neutral, informative mid tone with a gentle wobble.
    w = t(150)
    warn = np.sin(2 * np.pi * 587 * w + 0.25 * np.sin(2 * np.pi * 7 * w))
    out['warning'] = (pad(env(warn, 4, 120), 170), -11)

    # Reward — a bright rising triad arpeggio with a little shimmer (C6-E6-G6).
    notes = [523.25, 659.25, 783.99]
    reward = np.zeros(int(SR * 0.42))
    for i, f in enumerate(notes):
        start = int(SR * 0.075 * i)
        tone = env(mix(sine(f * 2, 260), sine(f * 4, 260) * 0.2), 3, 230)
        reward[start:start + len(tone)] += tone[: len(reward) - start]
    out['reward'] = (pad(reward, 460), -7)

    # Coin — two quick high metallic pings (a tasteful coin sparkle).
    c1 = env(mix(sine(1568, 90), sine(3136, 90) * 0.4), 1, 80)
    c2 = np.concatenate([np.zeros(int(SR * 0.055)), env(mix(sine(2093, 110), sine(4186, 110) * 0.4), 1, 95)])
    out['coin'] = (pad(mix(c1, c2), 220), -9)

    # Unlock — a soft low "thunk" then a rising confirm ping (lock release).
    thunk = env(mix(sine(190, 70), triangle(380, 40) * 0.3), 1, 60)
    ping = np.concatenate([np.zeros(int(SR * 0.09)), env(mix(sine(1046, 200), sine(1568, 200) * 0.3), 3, 180)])
    out['unlock'] = (pad(mix(thunk, ping), 300), -9)

    # Notification — a gentle, unobtrusive two-note soft bell (A5 -> D6).
    n1 = env(sine(880, 140), 4, 120)
    n2 = np.concatenate([np.zeros(int(SR * 0.085)), env(sine(1174, 190), 4, 165)])
    out['notification'] = (pad(mix(n1, n2) * 0.85, 250), -10)

    return out


def write_wav(path, sig):
    data = (np.clip(sig, -1, 1) * 32767).astype('<i2')
    with wave.open(path, 'w') as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(SR)
        wav.writeframes(data.tobytes())


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    out_dir = sys.argv[1]
    os.makedirs(out_dir, exist_ok=True)
    tmp = os.path.join(out_dir, '_tmp.wav')
    for name, (sig, gain) in build().items():
        write_wav(tmp, finalize(sig, gain))
        m4a = os.path.join(out_dir, f'{name}.m4a')
        subprocess.run(
            ['ffmpeg', '-y', '-loglevel', 'error', '-i', tmp, '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', m4a],
            check=True,
        )
        ms = round(len(sig) / SR * 1000)
        print(f'{name:12s} {ms:4d} ms  {os.path.getsize(m4a):5d} B  {gain:+d} dB')
    os.remove(tmp)


if __name__ == '__main__':
    main()
