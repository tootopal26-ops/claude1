/**
 * The Mini Max sound library: ONE sound — the tap click. Every other sound effect (rewards,
 * coins, success/error chimes, transitions, ...) has been removed on purpose; the app only
 * ever makes a sound when something is clicked. The audio file is the supplied
 * "Futuristic UI - digital click" (see mobile/assets/sounds/SOUNDS.md for its credit). This
 * file is shared by both engine implementations (engine.native.ts on device, engine.ts on
 * web/tests).
 */

export type SoundName = 'click';

/** The bundled asset for each sound. require() resolves to a native module id or, on web, a URL. */
export const SOUND_SOURCES: Record<SoundName, number> = {
  click: require('../../assets/sounds/click.mp3'),
};

/**
 * Smallest gap (ms) between two plays of the same sound. A second request inside the window is
 * dropped, so a burst of rapid taps never stacks into a muddy overlap.
 */
export const SOUND_DEBOUNCE_MS: Record<SoundName, number> = {
  click: 55,
};

/** Per-sound trim relative to the master volume (1 = the master volume as set in Settings). */
export const SOUND_TRIM: Record<SoundName, number> = {
  click: 1,
};

export const ALL_SOUNDS = Object.keys(SOUND_SOURCES) as SoundName[];
