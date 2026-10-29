import * as SecureStore from 'expo-secure-store';
import { createContext, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import { createSoundEngine, type HapticKind, type SoundEngine } from './engine';
import { SOUND_DEBOUNCE_MS, SOUND_TRIM, type SoundName } from './sounds';

/**
 * The one sound + haptics manager for the whole app. Every interaction sound and haptic goes
 * through here, so playback logic lives in one place rather than scattered across components.
 *
 * - UI sound effects and haptics are each remembered separately (SecureStore) and can each be
 *   turned off; when sound is off, nothing plays anywhere, and the change takes effect at once.
 * - The semantic helpers (playClick, playSuccess, ...) each fire a sound AND, where it fits, a
 *   matching haptic — but each half is gated by its own setting, so haptics can stay on with
 *   sound off and vice versa.
 * - A per-sound debounce drops a repeat of the same sound inside a short window, so a burst of
 *   rapid taps never stacks into an overlap.
 * - The engine seam (engine.native.ts / engine.ts) means this file never knows or cares whether
 *   it is running on a device or the web.
 */

const ENABLED_KEY = 'minimax_sound_enabled';
const VOLUME_KEY = 'minimax_sound_volume';
const HAPTICS_KEY = 'minimax_haptics_enabled';

const DEFAULT_ENABLED = true;
const DEFAULT_VOLUME = 0.7;
const DEFAULT_HAPTICS = true;

type SoundContextValue = {
  soundEnabled: boolean;
  volume: number;
  hapticsEnabled: boolean;
  /** Whether this platform can actually produce sound / haptics (for the settings UI). */
  soundSupported: boolean;
  hapticsSupported: boolean;
  setSoundEnabled: (on: boolean) => void;
  setVolume: (value: number) => void;
  setHapticsEnabled: (on: boolean) => void;

  // Everyday UI
  playClick: () => void;
  playSelection: () => void;
  playTransition: () => void;
  playClose: () => void;
  playToggle: () => void;
  // Outcomes (use only once the real result is known — never to imply success early)
  playSuccess: () => void;
  playError: () => void;
  playWarning: () => void;
  // Rewards
  playReward: () => void;
  playCoin: () => void;
  playUnlock: () => void;
  playNotification: () => void;

  /** Low-level: play a specific sound / fire a specific haptic (both still respect the settings). */
  play: (name: SoundName) => void;
  haptic: (kind: HapticKind) => void;
  /** Play a short representative sample so the user can hear the style from Settings. */
  preview: () => void;
};

const SoundContext = createContext<SoundContextValue | undefined>(undefined);

function clampVolume(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_VOLUME;
  return Math.max(0, Math.min(1, value));
}

export function SoundProvider({ children }: { children: ReactNode }) {
  const engineRef = useRef<SoundEngine | null>(null);
  if (engineRef.current === null) engineRef.current = createSoundEngine();
  const engine = engineRef.current;

  const [soundEnabled, setSoundEnabledState] = useState(DEFAULT_ENABLED);
  const [volume, setVolumeState] = useState(DEFAULT_VOLUME);
  const [hapticsEnabled, setHapticsEnabledState] = useState(DEFAULT_HAPTICS);

  // Live copies for the imperative play path, so a play never uses a stale closure.
  const enabledRef = useRef(soundEnabled);
  const volumeRef = useRef(volume);
  const hapticsRef = useRef(hapticsEnabled);
  enabledRef.current = soundEnabled;
  volumeRef.current = volume;
  hapticsRef.current = hapticsEnabled;

  const lastPlayed = useRef<Partial<Record<SoundName, number>>>({});

  useEffect(() => {
    engine.preload();
    (async () => {
      try {
        const [savedEnabled, savedVolume, savedHaptics] = await Promise.all([
          SecureStore.getItemAsync(ENABLED_KEY),
          SecureStore.getItemAsync(VOLUME_KEY),
          SecureStore.getItemAsync(HAPTICS_KEY),
        ]);
        if (savedEnabled === '0' || savedEnabled === '1') setSoundEnabledState(savedEnabled === '1');
        if (savedVolume !== null) setVolumeState(clampVolume(Number(savedVolume)));
        if (savedHaptics === '0' || savedHaptics === '1') setHapticsEnabledState(savedHaptics === '1');
      } catch {
        /* keep defaults when storage is unavailable */
      }
    })();
    return () => engine.release();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const value = useMemo<SoundContextValue>(() => {
    const playSound = (name: SoundName) => {
      if (!enabledRef.current || !engine.soundAvailable) return;
      const now = Date.now();
      const last = lastPlayed.current[name] ?? 0;
      if (now - last < SOUND_DEBOUNCE_MS[name]) return; // drop an overlapping repeat
      lastPlayed.current[name] = now;
      engine.play(name, volumeRef.current * SOUND_TRIM[name]);
    };

    const doHaptic = (kind: HapticKind) => {
      if (!hapticsRef.current || !engine.hapticsAvailable) return;
      engine.haptic(kind);
    };

    const both = (name: SoundName, kind: HapticKind | null) => () => {
      playSound(name);
      if (kind) doHaptic(kind);
    };

    return {
      soundEnabled,
      volume,
      hapticsEnabled,
      soundSupported: engine.soundAvailable,
      hapticsSupported: engine.hapticsAvailable,

      setSoundEnabled(on) {
        setSoundEnabledState(on);
        void SecureStore.setItemAsync(ENABLED_KEY, on ? '1' : '0').catch(() => {});
        if (on) playSound('selection'); // a tiny confirmation that sound is back on
      },
      setVolume(next) {
        const v = clampVolume(next);
        setVolumeState(v);
        void SecureStore.setItemAsync(VOLUME_KEY, String(v)).catch(() => {});
      },
      setHapticsEnabled(on) {
        setHapticsEnabledState(on);
        void SecureStore.setItemAsync(HAPTICS_KEY, on ? '1' : '0').catch(() => {});
        if (on) doHaptic('selection');
      },

      playClick: both('click', 'light'),
      playSelection: both('selection', 'selection'),
      playTransition: both('transition', null),
      playClose: both('close', null),
      playToggle: both('toggle', 'light'),
      playSuccess: both('success', 'success'),
      playError: both('error', 'error'),
      playWarning: both('warning', 'warning'),
      playReward: both('reward', 'success'),
      playCoin: both('coin', null),
      playUnlock: both('unlock', 'medium'),
      playNotification: both('notification', null),

      play: playSound,
      haptic: doHaptic,
      preview() {
        // A short taste of the style: a glassy tick, then the warm confirmation chime.
        playSound('selection');
        doHaptic('selection');
        setTimeout(() => playSound('success'), 160);
      },
    };
  }, [engine, soundEnabled, volume, hapticsEnabled]);

  return <SoundContext.Provider value={value}>{children}</SoundContext.Provider>;
}

export function useSound(): SoundContextValue {
  const context = useContext(SoundContext);
  if (!context) {
    throw new Error('useSound must be used within a SoundProvider');
  }
  return context;
}

const noop = () => {};

/**
 * A no-op sound context, so the low-level press primitives (Button, PressableScale) can ask for
 * feedback without crashing if they are ever rendered outside the provider (for example an
 * isolated unit test). Inside the real app the provider is always present.
 */
const NOOP_SOUND: SoundContextValue = {
  soundEnabled: false,
  volume: DEFAULT_VOLUME,
  hapticsEnabled: false,
  soundSupported: false,
  hapticsSupported: false,
  setSoundEnabled: noop,
  setVolume: noop,
  setHapticsEnabled: noop,
  playClick: noop,
  playSelection: noop,
  playTransition: noop,
  playClose: noop,
  playToggle: noop,
  playSuccess: noop,
  playError: noop,
  playWarning: noop,
  playReward: noop,
  playCoin: noop,
  playUnlock: noop,
  playNotification: noop,
  play: noop,
  haptic: noop,
  preview: noop,
};

/** Like useSound, but returns a safe no-op instead of throwing when no provider is present. */
export function useSoundOptional(): SoundContextValue {
  return useContext(SoundContext) ?? NOOP_SOUND;
}
