/** "Sat, 24 Oct · 7:30 pm" in the device's time zone. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';

  const day = date.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
  const time = date.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' });

  return `${day} · ${time}`;
}

/** "24 Oct 2026" */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';

  return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** "in 2h 10m", "in 3 days", or null once the moment has passed. */
export function timeUntil(iso: string | null | undefined, now: number = Date.now()): string | null {
  if (!iso) return null;
  const diff = new Date(iso).getTime() - now;
  if (!Number.isFinite(diff) || diff <= 0) return null;

  const minutes = Math.floor(diff / 60000);
  if (minutes < 1) return 'in under a minute';
  if (minutes < 60) return `in ${minutes}m`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `in ${hours}h ${minutes % 60}m`;
  const days = Math.floor(hours / 24);

  return days === 1 ? 'in 1 day' : `in ${days} days`;
}
