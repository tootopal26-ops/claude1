import * as Haptics from 'expo-haptics';
import { createAudioPlayer, setAudioModeAsync, type AudioPlayer } from 'expo-audio';

import { ALL_SOUNDS, SOUND_SOURCES, type SoundName } from './sounds';
import type { HapticKind, SoundEngine } from './engine';

export type { HapticKind, SoundEngine } from './engine';

/**
 * The device sound/haptics engine (Android & iOS), used in place of engine.ts. Short sounds are
 * loaded once into reusable expo-audio players and replayed from the start; haptics go through
 * expo-haptics. The audio session is configured to MIX with other audio and to respect the iOS
 * silent switch, so Mini Max never ducks or interrupts the player's music, a video or a call,
 * and never forces sound on a silenced phone. Everything is best-effort: a failure to load,
 * play or vibrate is swallowed so a missing codec or an audio-focus loss can never crash a tap.
 */

const IMPACT: Record<Exclude<HapticKind, 'selection' | 'success' | 'warning' | 'error'>, Haptics.ImpactFeedbackStyle> = {
  light: Haptics.ImpactFeedbackStyle.Light,
  medium: Haptics.ImpactFeedbackStyle.Medium,
};

const NOTIFY: Record<'success' | 'warning' | 'error', Haptics.NotificationFeedbackType> = {
  success: Haptics.NotificationFeedbackType.Success,
  warning: Haptics.NotificationFeedbackType.Warning,
  error: Haptics.NotificationFeedbackType.Error,
};

export function createSoundEngine(): SoundEngine {
  const players = new Map<SoundName, AudioPlayer>();
  let modeConfigured = false;

  const configureMode = () => {
    if (modeConfigured) return;
    modeConfigured = true;
    // Mix with others, respect the silent switch, never play in the background.
    void setAudioModeAsync({
      playsInSilentMode: false,
      interruptionMode: 'mixWithOthers',
      shouldPlayInBackground: false,
    }).catch(() => {});
  };

  const player = (name: SoundName): AudioPlayer | null => {
    const existing = players.get(name);
    if (existing) return existing;
    try {
      const created = createAudioPlayer(SOUND_SOURCES[name]);
      players.set(name, created);
      return created;
    } catch {
      return null;
    }
  };

  return {
    soundAvailable: true,
    hapticsAvailable: true,
    preload() {
      configureMode();
      for (const name of ALL_SOUNDS) player(name);
    },
    play(name, volume) {
      configureMode();
      const p = player(name);
      if (!p) return;
      try {
        p.volume = Math.max(0, Math.min(1, volume));
        // Restart from the beginning so rapid, debounced replays always sound.
        void p.seekTo(0).catch(() => {});
        p.play();
      } catch {
        /* audio focus lost or player not ready — stay silent */
      }
    },
    haptic(kind: HapticKind) {
      try {
        if (kind === 'selection') {
          void Haptics.selectionAsync().catch(() => {});
        } else if (kind === 'success' || kind === 'warning' || kind === 'error') {
          void Haptics.notificationAsync(NOTIFY[kind]).catch(() => {});
        } else {
          void Haptics.impactAsync(IMPACT[kind]).catch(() => {});
        }
      } catch {
        /* haptics unsupported on this device — ignore */
      }
    },
    release() {
      for (const p of players.values()) {
        try {
          p.remove();
        } catch {
          /* already gone */
        }
      }
      players.clear();
    },
  };
}
