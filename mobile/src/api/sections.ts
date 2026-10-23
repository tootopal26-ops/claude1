import { apiRequest } from './client';
import type { SectionStatus, SectionUnlockResult } from '../types';

/** Every unlockable section with its current price and whether this player has it. */
export function fetchSections() {
  return apiRequest<{ data: SectionStatus[] }>('/sections');
}

/**
 * Buys a section. `expectedPrice` is the price the player confirmed on screen; the
 * server charges its own stored price and refuses (409) if it no longer matches.
 */
export function unlockSection(key: string, expectedPrice: number) {
  return apiRequest<SectionUnlockResult>(`/sections/${encodeURIComponent(key)}/unlock`, {
    method: 'POST',
    body: { expected_price: expectedPrice },
  });
}
