/**
 * Mini Max mobile numbers are Indian numbers. The sign-in/sign-up field shows a fixed "+91"
 * and the player types the 10 digits after it. The API is sent only those 10 digits (never
 * the +91): that is how every account is stored and matched (login compares the string
 * exactly), so adding a prefix here would stop existing accounts from signing in.
 */
export const COUNTRY_CODE = '+91';
export const MOBILE_DIGITS = 10;

/**
 * Turns whatever was typed, pasted or autofilled into at most 10 digits. A pasted
 * "+91 82730 93895", "918273093895" or "08273093895" becomes "8273093895" instead of being
 * cut off after the first ten characters.
 */
export function normalizeMobileInput(text: string): string {
  let digits = text.replace(/\D/g, '');

  if (digits.length > MOBILE_DIGITS) {
    if (digits.length === MOBILE_DIGITS + 2 && digits.startsWith('91')) {
      digits = digits.slice(2);
    } else if (digits.length === MOBILE_DIGITS + 1 && digits.startsWith('0')) {
      digits = digits.slice(1);
    }
  }

  return digits.slice(0, MOBILE_DIGITS);
}

/** Exactly 10 digits. */
export function isValidMobile(digits: string): boolean {
  return /^\d{10}$/.test(digits);
}

/**
 * "+91 8273093895" for display. Accounts hold the bare 10 digits; a number stored in some
 * other shape (for example already carrying +91) is shown as it is rather than guessed at.
 */
export function formatMobile(stored: string): string {
  const value = stored.trim();
  if (isValidMobile(value)) return `${COUNTRY_CODE} ${value}`;

  const prefixed = /^\+91\s?(\d{10})$/.exec(value);

  return prefixed ? `${COUNTRY_CODE} ${prefixed[1]}` : value;
}
