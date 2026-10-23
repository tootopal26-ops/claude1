import { useRef } from 'react';

/**
 * The last non-null value. A sheet keeps showing what it was opened with while it plays its
 * closing animation, even though the caller has already cleared the value.
 */
export function useRetained<T>(value: T | null): T | null {
  const last = useRef<T | null>(value);
  if (value !== null) last.current = value;

  return value ?? last.current;
}
