import { Reservation } from '../types';

// Only the visitor's own booking codes are kept on the device ("حجوزاتي"); all data lives on the server
const STORAGE_KEYS = {
  CUSTOMER_SAVED_CODES: 'houria_my_booking_codes',
};

export function loadCustomerSavedCodes(): string[] {
  try {
    const data = localStorage.getItem(STORAGE_KEYS.CUSTOMER_SAVED_CODES);
    if (data) return JSON.parse(data);
  } catch (e) {
    console.error('Failed to load customer codes', e);
  }
  // Default to sample codes for immediate test convenience
  return [];
}

export function saveCustomerSavedCodes(codes: string[]) {
  try {
    localStorage.setItem(STORAGE_KEYS.CUSTOMER_SAVED_CODES, JSON.stringify(codes));
  } catch (e) {
    console.error('Failed to save customer codes', e);
  }
}

export function addCustomerSavedCode(code: string) {
  try {
    const existing = loadCustomerSavedCodes();
    if (!existing.includes(code)) {
      const updated = [code, ...existing];
      saveCustomerSavedCodes(updated);
    }
  } catch (e) {
    console.error('Failed to add customer code', e);
  }
}

// wa.me needs international format: 01061113915 -> 201061113915
export function toWhatsAppNumber(phone: string): string {
  const digits = phone.replace(/[^0-9]/g, '');
  if (digits.startsWith('0') && digits.length === 11) return `2${digits}`;
  return digits;
}

export function whatsappLink(phone: string, text?: string): string {
  const base = `https://wa.me/${toWhatsAppNumber(phone)}`;
  return text ? `${base}?text=${encodeURIComponent(text)}` : base;
}

export function generateReservationCode(): string {
  const randomNum = Math.floor(100000 + Math.random() * 900000);
  return `RSV-${randomNum}`;
}

export function formatCurrency(amount: number, currency: string = 'ج.م', lang: 'ar' | 'en' = 'ar'): string {
  const formattedNumber = new Intl.NumberFormat(lang === 'ar' ? 'ar-EG' : 'en-US').format(amount);
  return lang === 'ar' ? `${formattedNumber} ${currency}` : `${currency} ${formattedNumber}`;
}

export function formatDate(isoString: string, lang: 'ar' | 'en' = 'ar'): string {
  try {
    const date = new Date(isoString);
    return date.toLocaleDateString(lang === 'ar' ? 'ar-EG' : 'en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return isoString;
  }
}

export function exportReservationsToCSV(reservations: Reservation[]) {
  const headers = [
    'كود الحجز (Booking Code)',
    'تاريخ الطلب (Date)',
    'اسم العميل (Customer Name)',
    'رقم الهاتف (Phone)',
    'المنتج (Product)',
    'الكمية (Quantity)',
    'السعر الإجمالي (Total Price)',
    'المقدم المدفوع (Deposit)',
    'الباقي في الفرع (Remaining)',
    'طريقة الدفع (Payment Method)',
    'الرقم المرجعي (Ref)',
    'إثبات الدفع (Proof)',
    'حالة الدفع (Payment Status)',
    'حالة الحجز (Status)',
  ];

  const q = (v: string) => `"${(v || '').replace(/"/g, '""')}"`;
  const rows = reservations.map(r => [
    q(r.code),
    q(new Date(r.createdAt).toLocaleString('ar-EG')),
    q(r.customerName),
    q(r.customerPhone),
    q(r.productNameAr),
    1,
    r.totalPrice,
    r.depositAmount,
    r.remainingAmount,
    q(r.paymentMethod === 'instapay' ? 'إنستاباي' : 'فودافون كاش'),
    q(r.paymentReferenceNumber || ''),
    q(r.paymentProofUrl ? 'صورة مرفوعة' : r.proofSentOnWhatsApp ? 'على واتساب' : ''),
    q(r.paymentStatus),
    q(r.reservationStatus),
  ]);

  const csvContent = '\uFEFF' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', `reservations_export_${new Date().toISOString().slice(0, 10)}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
