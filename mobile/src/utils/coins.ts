/**
 * A coin amount for display: thousands separated, and no ".00" on whole
 * numbers ("1,250", but "12.50" stays "12.50"). Takes the API's decimal strings
 * as well as numbers; anything unreadable shows a dash rather than 0.
 */
export function formatCoins(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';

  const amount = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(amount)) return '—';

  const [whole, fraction] = Math.abs(amount).toFixed(2).split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  return `${amount < 0 ? '-' : ''}${grouped}${fraction === '00' ? '' : `.${fraction}`}`;
}
