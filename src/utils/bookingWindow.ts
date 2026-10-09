import { useEffect, useState } from 'react';
import { Product } from '../types';

export type BookingWindowState = 'none' | 'not_started' | 'open' | 'ended';

export interface BookingWindow {
  state: BookingWindowState;
  /** The moment the countdown is counting toward (start or end), if any */
  target?: Date;
}

const parse = (iso?: string) => {
  if (!iso) return undefined;
  const d = new Date(iso);
  return isNaN(d.getTime()) ? undefined : d;
};

export function getBookingWindow(product: Product, now: number = Date.now()): BookingWindow {
  const start = parse(product.bookingStartsAt);
  const end = parse(product.bookingEndsAt);
  if (start && now < start.getTime()) return { state: 'not_started', target: start };
  if (end && now >= end.getTime()) return { state: 'ended', target: end };
  if (end) return { state: 'open', target: end };
  return { state: 'none' };
}

/** Booking is possible right now (time window + stock + status) */
export function canBookNow(product: Product, now: number = Date.now()): boolean {
  const w = getBookingWindow(product, now);
  const remaining = product.reservationQuota - product.reservedCount;
  return (
    product.status !== 'closed' &&
    product.status !== 'coming_soon' &&
    remaining > 0 &&
    w.state !== 'not_started' &&
    w.state !== 'ended'
  );
}

export function splitDuration(ms: number) {
  const total = Math.max(0, Math.floor(ms / 1000));
  return {
    days: Math.floor(total / 86400),
    hours: Math.floor((total % 86400) / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}

/** Re-renders every `intervalMs` so countdowns tick */
export function useNow(intervalMs = 1000): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), intervalMs);
    return () => clearInterval(id);
  }, [intervalMs]);
  return now;
}

/** ISO <-> value for <input type="datetime-local"> in the viewer's local time */
export function isoToLocalInput(iso?: string): string {
  const d = parse(iso);
  if (!d) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function localInputToIso(value: string): string | undefined {
  if (!value) return undefined;
  const d = new Date(value);
  return isNaN(d.getTime()) ? undefined : d.toISOString();
}
