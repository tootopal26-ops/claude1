import { Image } from 'react-native';

import { ALL_SOUNDS, SOUND_SOURCES, type SoundName } from './sounds';

/**
 * The sound/haptics seam — this is the web (and test) implementation, used anywhere the
 * native audio module can't run. It plays through the browser's Audio element when one is
 * available (so the web preview and the QA harness get real feedback) and otherwise no-ops.
 * Haptics are a no-op on the web; the real device engine is engine.native.ts, which Metro
 * picks automatically for Android and iOS. Both expose the same SoundEngine shape, so the
 * provider never branches on platform.
 */

export type HapticKind = 'light' | 'medium' | 'selection' | 'success' | 'warning' | 'error';

export type SoundEngine = {
  /** True when short UI sounds can actually be played on this platform. */
  readonly soundAvailable: boolean;
  /** True when the device can produce haptic feedback. */
  readonly hapticsAvailable: boolean;
  /** Load the short sounds ahead of first use. Safe to call more than once. */
  preload(): void;
  /** Play one sound at an absolute volume (0..1). Never throws. */
  play(name: SoundName, volume: number): void;
  /** Fire one haptic. Never throws. No-op where haptics are unavailable. */
  haptic(kind: HapticKind): void;
  /** Release every loaded sound. */
  release(): void;
};

function assetUri(name: SoundName): string | null {
  const source: unknown = SOUND_SOURCES[name];
  // On web a required asset can already be a URL string; otherwise resolve it the RN way.
  if (typeof source === 'string') return source;
  try {
    const resolved = Image.resolveAssetSource(source as number);
    return resolved?.uri ?? null;
  } catch {
    return null;
  }
}

export function createSoundEngine(): SoundEngine {
  const hasAudio = typeof globalThis !== 'undefined' && typeof (globalThis as { Audio?: unknown }).Audio === 'function';
  const elements = new Map<SoundName, HTMLAudioElement>();

  const element = (name: SoundName): HTMLAudioElement | null => {
    if (!hasAudio) return null;
    const existing = elements.get(name);
    if (existing) return existing;
    const uri = assetUri(name);
    if (!uri) return null;
    try {
      const AudioCtor = (globalThis as unknown as { Audio: new (src?: string) => HTMLAudioElement }).Audio;
      const el = new AudioCtor(uri);
      el.preload = 'auto';
      elements.set(name, el);
      return el;
    } catch {
      return null;
    }
  };

  // So the QA harness can observe that a sound actually fired (and which one) without playing audio.
  const announce = (name: SoundName) => {
    try {
      (globalThis as { dispatchEvent?: (e: unknown) => void }).dispatchEvent?.(new (globalThis as { CustomEvent: new (t: string, o: unknown) => unknown }).CustomEvent('mm-sound', { detail: { name } }));
    } catch {
      /* no CustomEvent outside a DOM */
    }
  };

  return {
    soundAvailable: hasAudio,
    hapticsAvailable: false,
    preload() {
      if (!hasAudio) return;
      for (const name of ALL_SOUNDS) element(name);
    },
    play(name, volume) {
      const el = element(name);
      if (!el) return;
      announce(name); // an element exists and we intend to play it
      // Each step is best-effort: a not-yet-loaded element can throw on currentTime, and
      // autoplay can reject, but neither should stop the others or surface an error.
      try {
        el.volume = Math.max(0, Math.min(1, volume));
      } catch {
        /* volume not settable yet */
      }
      try {
        el.currentTime = 0;
      } catch {
        /* media not seekable yet */
      }
      try {
        const result = el.play();
        if (result && typeof (result as Promise<void>).catch === 'function') {
          (result as Promise<void>).catch(() => {});
        }
      } catch {
        /* autoplay blocked — the UI still worked, just silent */
      }
    },
    haptic() {
      /* no haptics on the web */
    },
    release() {
      elements.clear();
    },
  };
}
