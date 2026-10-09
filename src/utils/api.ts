import { Product, Reservation, MerchantSettings, NotificationItem, PaymentMethod } from '../types';

// All data lives on the server (PHP + SQLite on Hostinger). This file is the only place that talks to it.
const API = '/api/index.php';
const TOKEN_KEY = 'houria_admin_token';

export class ApiError extends Error {
  constructor(message: string, public status: number, public code?: string) {
    super(message);
  }
}

export function getAdminToken(): string {
  try { return sessionStorage.getItem(TOKEN_KEY) || localStorage.getItem(TOKEN_KEY) || ''; } catch { return ''; }
}

function setAdminToken(token: string) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else { localStorage.removeItem(TOKEN_KEY); sessionStorage.removeItem(TOKEN_KEY); }
  } catch { /* storage blocked */ }
}

async function call<T>(action: string, opts: { body?: unknown; query?: Record<string, string>; admin?: boolean } = {}): Promise<T> {
  const params = new URLSearchParams({ action, ...(opts.query || {}) });
  const headers: Record<string, string> = {};
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.admin) headers['Authorization'] = `Bearer ${getAdminToken()}`;

  let res: Response;
  try {
    res = await fetch(`${API}?${params}`, {
      method: opts.body !== undefined ? 'POST' : 'GET',
      headers,
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
      cache: 'no-store',
    });
  } catch {
    throw new ApiError('مفيش اتصال بالإنترنت أو السيرفر مش بيرد. جرّب تاني.', 0, 'network');
  }
  let data: any = null;
  try { data = await res.json(); } catch { /* non-JSON */ }
  if (!res.ok || !data || data.ok === false) {
    if (res.status === 401 && opts.admin) setAdminToken('');
    throw new ApiError(data?.message || 'حصل خطأ في السيرفر، جرّب تاني.', res.status, data?.error);
  }
  return data as T;
}

// ───────── Public ─────────

export const fetchStore = () =>
  call<{ products: Product[]; settings: MerchantSettings }>('bootstrap');

export interface BookingPayload {
  productId: string;
  code: string;
  customerName: string;
  customerPhone: string;
  customerNotes?: string;
  paymentMethod: PaymentMethod;
  paymentReferenceNumber?: string;
  proofImage?: string;          // data: URL (compressed on the device first)
  proofSentOnWhatsApp?: boolean;
}

export const createBooking = (payload: BookingPayload) =>
  call<{ reservation: Reservation }>('book', { body: payload }).then(r => r.reservation);

export const trackBookings = (q: string) =>
  call<{ reservations: Reservation[] }>('track', { query: { q } }).then(r => r.reservations);

export const fetchBookingsByCodes = (codes: string[]) =>
  codes.length === 0
    ? Promise.resolve([] as Reservation[])
    : call<{ reservations: Reservation[] }>('track', { query: { codes: codes.join(',') } }).then(r => r.reservations);

// ───────── Admin ─────────

export async function adminLogin(username: string, password: string): Promise<void> {
  const r = await call<{ token: string }>('login', { body: { username, password } });
  setAdminToken(r.token);
}

export async function adminLogout(): Promise<void> {
  try { await call('logout', { body: {}, admin: true }); } catch { /* ignore */ }
  setAdminToken('');
}

export const fetchAdminData = () =>
  call<{ products: Product[]; reservations: Reservation[]; notifications: NotificationItem[]; settings: MerchantSettings }>('admin_data', { admin: true });

export const saveProduct = (product: Product) =>
  call<{ product: Product }>('product_save', { body: { product }, admin: true }).then(r => r.product);

export const deleteProduct = (id: string) => call('product_delete', { body: { id }, admin: true });

export const updateReservation = (id: string, patch: Partial<Pick<Reservation, 'reservationStatus' | 'paymentStatus' | 'merchantNotes' | 'history'>>) =>
  call('reservation_update', { body: { id, patch }, admin: true });

export const deleteReservations = (ids: string[]) => call('reservation_delete', { body: { ids }, admin: true });

export const saveSettings = (settings: MerchantSettings) =>
  call<{ settings: MerchantSettings }>('settings_save', { body: { settings }, admin: true }).then(r => r.settings);

export const saveCredentials = (username: string, current: string, next: string) =>
  call<{ username: string }>('credentials_save', { body: { username, current, new: next }, admin: true });

export const markNotificationsRead = (ids: string[] | 'all') =>
  call('notifications_read', { body: ids === 'all' ? { all: true } : { ids }, admin: true });

// ───────── Images ─────────

/** Shrink a photo on the device before uploading (keeps uploads fast on mobile data). */
export function compressImage(file: File, maxSide = 1600, quality = 0.82): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('read failed'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('not an image'));
      img.onload = () => {
        const scale = Math.min(1, maxSide / Math.max(img.width, img.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        const ctx = canvas.getContext('2d');
        if (!ctx) return resolve(reader.result as string);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        resolve(canvas.toDataURL('image/jpeg', quality));
      };
      img.src = reader.result as string;
    };
    reader.readAsDataURL(file);
  });
}
