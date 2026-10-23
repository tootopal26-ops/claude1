import type { IconName } from '../theme/icons';
import type { SectionStatus } from '../types';

/**
 * Where each registered section lives in the app. The keys are the backend's
 * registry (config/minimax.php > unlockable_sections); a section the app does
 * not know here is shown as a card that cannot be opened, never invented.
 */
const SECTION_ROUTES: Record<string, string> = {
  leaderboard: '/leaderboard',
};

const KNOWN_ICONS: IconName[] = ['leaderboard', 'tournaments', 'wallet', 'notifications', 'user', 'image'];

export function routeForSection(key: string): string | null {
  return SECTION_ROUTES[key] ?? null;
}

export function iconForSection(section: Pick<SectionStatus, 'icon'>): IconName {
  return (KNOWN_ICONS as string[]).includes(section.icon) ? (section.icon as IconName) : 'tournaments';
}
