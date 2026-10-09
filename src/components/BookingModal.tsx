import React, { useRef, useState } from 'react';
import confetti from 'canvas-confetti';
import { toPng } from 'html-to-image';
import {
  X,
  Copy,
  Check,
  Upload,
  Smartphone,
  ArrowRight,
  ArrowLeft,
  CheckCircle2,
  Printer,
  QrCode,
  AlertCircle,
  Package,
  MessageCircle,
  Download,
  MapPin,
  Ticket,
} from 'lucide-react';
import { Product, Reservation, PaymentMethod, Language, MerchantSettings } from '../types';
import { translations } from '../translations';
import {
  formatCurrency,
  formatDate,
  generateReservationCode,
  addCustomerSavedCode,
  whatsappLink,
} from '../utils/storage';
import { playNewReservationChime } from '../utils/audio';
import { canBookNow, getBookingWindow } from '../utils/bookingWindow';
import { CountdownTimer } from './CountdownTimer';

interface BookingModalProps {
  product: Product | null;
  isOpen: boolean;
  onClose: () => void;
  onReservationCreated: (reservation: Reservation) => void;
  onNavigateToTrack: (code: string) => void;
  language: Language;
  currency: string;
  merchantSettings: MerchantSettings;
  existingReservations: Reservation[];
}

// "+20 106 111 3915" and "01061113915" compare equal
const normalizePhone = (phone: string) => {
  const digits = phone.replace(/[^0-9]/g, '');
  return digits.startsWith('20') && digits.length === 12 ? `0${digits.slice(2)}` : digits;
};

export const BookingModal: React.FC<BookingModalProps> = ({
  product,
  isOpen,
  onClose,
  onReservationCreated,
  onNavigateToTrack,
  language,
  currency,
  merchantSettings,
  existingReservations,
}) => {
  // Hooks first (the modal is mounted only while open, see App.tsx)
  const [step, setStep] = useState<1 | 2 | 3>(1);
  // Code is generated up-front so the WhatsApp proof message can include it
  const [bookingCode] = useState(() => generateReservationCode());

  const [customerName, setCustomerName] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [customerNotes, setCustomerNotes] = useState('');

  const [selectedMethod, setSelectedMethod] = useState<PaymentMethod>('vodafone_cash');
  const [referenceNumber, setReferenceNumber] = useState('');
  const [proofImage, setProofImage] = useState<string | null>(null);
  const [proofSentOnWhatsApp, setProofSentOnWhatsApp] = useState(false);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  const [createdReservation, setCreatedReservation] = useState<Reservation | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [codeCopied, setCodeCopied] = useState(false);
  const [isSavingImage, setIsSavingImage] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const voucherRef = useRef<HTMLDivElement>(null);

  if (!isOpen || !product) return null;

  const t = translations[language];
  const ar = language === 'ar';
  const ArrowIcon = ar ? ArrowLeft : ArrowRight;
  const productName = ar ? product.nameAr : product.nameEn;

  // One piece per booking; deposit is part of the price
  const deposit = Math.min(product.depositAmount, product.price);
  const remaining = Math.max(0, product.price - deposit);
  const vfNumbers = merchantSettings.vodafoneCashNumbers.filter(Boolean);
  const branchAddress = ar ? merchantSettings.branchAddressAr : merchantSettings.branchAddressEn || merchantSettings.branchAddressAr;
  const methodLabel = (m: PaymentMethod) => (m === 'instapay' ? (ar ? 'إنستاباي' : 'InstaPay') : (ar ? 'فودافون كاش' : 'Vodafone Cash'));

  const handleCopy = (text: string, key: string) => {
    try { navigator.clipboard.writeText(text); } catch { /* clipboard blocked */ }
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onloadend = () => {
      setProofImage(reader.result as string);
      setErrorMessage('');
    };
    reader.readAsDataURL(file);
  };

  // Booking window / stock may change while the form is open (timer runs out)
  const bookingClosedMessage = () => {
    if (canBookNow(product)) return '';
    const w = getBookingWindow(product);
    if (w.state === 'ended') return ar ? 'للأسف وقت الحجز على المنتج ده خلص.' : 'Sorry, booking time for this product is over.';
    if (w.state === 'not_started') return ar ? 'الحجز على المنتج ده لسه مبدأش.' : 'Booking for this product has not opened yet.';
    return ar ? 'للأسف الكمية المتاحة للحجز خلصت.' : 'Sorry, this product is fully booked.';
  };

  const findDuplicateBooking = () => {
    const phone = normalizePhone(customerPhone);
    return existingReservations.find(
      r => r.productId === product.id && r.reservationStatus !== 'cancelled' && normalizePhone(r.customerPhone) === phone
    );
  };

  const validateStep1 = () => {
    const closed = bookingClosedMessage();
    if (closed) {
      setErrorMessage(closed);
      return false;
    }
    if (!customerName.trim()) {
      setErrorMessage(ar ? 'يرجى إدخال الاسم بالكامل' : 'Please enter your full name');
      return false;
    }
    if (normalizePhone(customerPhone).length < 10) {
      setErrorMessage(ar ? 'يرجى إدخال رقم موبايل صحيح' : 'Please enter a valid mobile number');
      return false;
    }
    const dup = findDuplicateBooking();
    if (dup) {
      setErrorMessage(
        ar
          ? `الرقم ده عنده حجز بالفعل لنفس المنتج (كود ${dup.code}). مسموح بقطعة واحدة بس لكل عميل.`
          : `This number already has a booking for this product (code ${dup.code}). One piece per customer.`
      );
      return false;
    }
    setErrorMessage('');
    return true;
  };

  // Message the customer sends with the transfer screenshot
  const proofWhatsAppUrl = whatsappLink(
    merchantSettings.whatsappNumber,
    `مرحباً، دي صورة تحويل مقدم الحجز 👇\n` +
      `كود الحجز: *${bookingCode}*\n` +
      `المنتج: ${product.nameAr} (قطعة واحدة)\n` +
      `الاسم: ${customerName.trim()}\n` +
      `الموبايل: ${customerPhone.trim()}\n` +
      `المقدم: ${formatCurrency(deposit, merchantSettings.currencyAr, 'ar')} — ${selectedMethod === 'instapay' ? 'إنستاباي' : 'فودافون كاش'}\n` +
      (referenceNumber.trim() ? `رقم العملية: ${referenceNumber.trim()}\n` : '') +
      `(هبعت صورة التحويل في الرسالة الجاية)`
  );

  const handleSubmitReservation = () => {
    const closed = bookingClosedMessage();
    if (closed) {
      setErrorMessage(closed);
      return;
    }
    if (!proofImage && !proofSentOnWhatsApp && !referenceNumber.trim()) {
      setErrorMessage(
        ar
          ? 'ارفع صورة التحويل أو ابعتها على واتساب الأول، أو اكتب رقم العملية.'
          : 'Upload the transfer screenshot, send it on WhatsApp, or enter the reference number first.'
      );
      return;
    }
    const dup = findDuplicateBooking();
    if (dup) {
      setErrorMessage(ar ? `الرقم ده عنده حجز بالفعل لنفس المنتج (كود ${dup.code}).` : `Already booked (code ${dup.code}).`);
      return;
    }

    setIsSubmitting(true);
    setErrorMessage('');

    setTimeout(() => {
      const nowIso = new Date().toISOString();
      const proofNoteAr = proofImage ? 'تم رفع صورة التحويل' : proofSentOnWhatsApp ? 'صورة التحويل اتبعتت على واتساب' : 'تم إدخال رقم العملية';
      const proofNoteEn = proofImage ? 'screenshot uploaded' : proofSentOnWhatsApp ? 'screenshot sent on WhatsApp' : 'reference number entered';

      const newReservation: Reservation = {
        id: `rsv-${Date.now()}`,
        code: bookingCode,
        productId: product.id,
        productNameAr: product.nameAr,
        productNameEn: product.nameEn,
        productImage: product.images[0] || '',
        totalPrice: product.price,
        depositAmount: deposit,
        remainingAmount: remaining,
        quantity: 1,
        customerName: customerName.trim(),
        customerPhone: customerPhone.trim(),
        customerNotes: customerNotes.trim() || undefined,
        paymentMethod: selectedMethod,
        paymentReferenceNumber: referenceNumber.trim() || undefined,
        paymentProofUrl: proofImage || undefined,
        proofSentOnWhatsApp: proofSentOnWhatsApp || undefined,
        paymentStatus: 'pending_verification',
        reservationStatus: 'pending',
        createdAt: nowIso,
        estimatedDeliveryAr: product.estimatedArrivalAr,
        estimatedDeliveryEn: product.estimatedArrivalEn,
        history: [
          {
            status: 'pending',
            timestamp: nowIso,
            noteAr: `تم تسجيل الحجز (قطعة واحدة) ودفع مقدم ${formatCurrency(deposit, merchantSettings.currencyAr, 'ar')} ${selectedMethod === 'instapay' ? 'إنستاباي' : 'فودافون كاش'} — ${proofNoteAr}. قيد المراجعة.`,
            noteEn: `Booking registered (1 piece), deposit paid via ${selectedMethod === 'instapay' ? 'InstaPay' : 'Vodafone Cash'} — ${proofNoteEn}. Under review.`,
          },
        ],
      };

      addCustomerSavedCode(bookingCode);
      if (merchantSettings.soundAlertEnabled) playNewReservationChime();
      try {
        confetti({ particleCount: 80, spread: 70, origin: { y: 0.6 }, colors: ['#10b981', '#f59e0b', '#0f172a'] });
      } catch { /* ignore */ }

      setCreatedReservation(newReservation);
      onReservationCreated(newReservation);
      setIsSubmitting(false);
      setStep(3);
    }, 600);
  };

  const handleSaveVoucherImage = async () => {
    if (!voucherRef.current) return;
    setIsSavingImage(true);
    try {
      const dataUrl = await toPng(voucherRef.current, { pixelRatio: 2, backgroundColor: '#ffffff', cacheBust: true });
      const link = document.createElement('a');
      link.download = `${bookingCode}.png`;
      link.href = dataUrl;
      link.click();
    } catch {
      window.print();
    } finally {
      setIsSavingImage(false);
    }
  };

  const receiptWhatsAppUrl = (res: Reservation) =>
    whatsappLink(
      merchantSettings.whatsappNumber,
      `مرحباً، ده وصل حجزي:\n` +
        `كود الحجز: *${res.code}*\n` +
        `المنتج: ${res.productNameAr} (قطعة واحدة)\n` +
        `الاسم: ${res.customerName}\n` +
        `الموبايل: ${res.customerPhone}\n` +
        `المقدم المدفوع: ${formatCurrency(res.depositAmount, merchantSettings.currencyAr, 'ar')} (${res.paymentMethod === 'instapay' ? 'إنستاباي' : 'فودافون كاش'})\n` +
        `الباقي عند الاستلام من الفرع: ${formatCurrency(res.remainingAmount, merchantSettings.currencyAr, 'ar')}`
    );

  return (
    <div className="sheet-overlay">
      <div
        className="sheet-panel sm:max-w-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between gap-3 pt-6 pb-3 px-4 sm:p-5 border-b border-stone-100 bg-stone-50/70">
          <div className="min-w-0">
            <h2 className="text-lg sm:text-xl font-black text-stone-900 flex items-center gap-2">
              <span>{step === 3 ? (ar ? 'وصل الحجز' : 'Booking Receipt') : t.bookingTitle}</span>
              {step < 3 && (
                <span className="hidden sm:inline text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">
                  {step === 1 ? t.step1Customer : t.step2Payment}
                </span>
              )}
            </h2>
            <p className="text-xs text-stone-500 line-clamp-1">{productName}</p>
          </div>
          <button onClick={onClose} aria-label={ar ? 'إغلاق' : 'Close'} className="p-2.5 shrink-0 rounded-xl text-stone-500 bg-stone-100 sm:bg-transparent hover:text-stone-700 hover:bg-stone-200/50 transition-colors">
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Steps */}
        {step < 3 && (
          <div className="bg-stone-100/70 px-4 py-2.5 border-b border-stone-200/60 flex items-center justify-center gap-4 text-xs">
            <div className="flex items-center gap-1.5 font-bold text-stone-900">
              <span className="w-5 h-5 rounded-full flex items-center justify-center text-[11px] bg-stone-900 text-white">1</span>
              <span>{t.step1Customer}</span>
            </div>
            <div className="h-px w-10 sm:w-16 bg-stone-300" />
            <div className={`flex items-center gap-1.5 font-bold ${step >= 2 ? 'text-stone-900' : 'text-stone-400'}`}>
              <span className={`w-5 h-5 rounded-full flex items-center justify-center text-[11px] ${step >= 2 ? 'bg-stone-900 text-white' : 'bg-stone-300 text-stone-600'}`}>2</span>
              <span>{t.step2Payment}</span>
            </div>
          </div>
        )}

        <div className="overflow-y-auto overscroll-contain p-4 sm:p-6 space-y-4 sm:space-y-5">
          {errorMessage && (
            <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs flex items-center gap-2">
              <AlertCircle className="w-4 h-4 shrink-0" />
              <span>{errorMessage}</span>
            </div>
          )}

          {/* STEP 1: CUSTOMER DETAILS */}
          {step === 1 && (
            <div className="space-y-4">
              <div className="p-3.5 rounded-2xl bg-amber-50/70 border border-amber-200/80 space-y-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <img src={product.images[0]} alt="" className="w-12 h-12 rounded-xl object-cover border border-amber-200 shrink-0" />
                    <h4 className="text-xs sm:text-sm font-bold text-stone-900 line-clamp-2">{productName}</h4>
                  </div>
                  <div className="shrink-0 flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-stone-900 text-amber-300 text-[11px] font-extrabold">
                    <Package className="w-3.5 h-3.5" />
                    <span>{ar ? 'قطعة واحدة' : '1 piece'}</span>
                  </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-1.5 sm:gap-2 sm:text-center">
                  <div className="p-2 rounded-xl bg-white border border-amber-200/80 flex sm:block items-center justify-between gap-2 px-3 sm:px-2">
                    <span className="block text-[10px] text-stone-500">{t.totalPrice}</span>
                    <strong className="text-xs sm:text-sm text-stone-900">{formatCurrency(product.price, currency, language)}</strong>
                  </div>
                  <div className="p-2 rounded-xl bg-emerald-50 border border-emerald-300 flex sm:block items-center justify-between gap-2 px-3 sm:px-2">
                    <span className="block text-[10px] text-emerald-800 font-bold">{ar ? 'تدفع دلوقتي (مقدم)' : 'Pay now (deposit)'}</span>
                    <strong className="text-sm sm:text-base font-black text-emerald-800">{formatCurrency(deposit, currency, language)}</strong>
                  </div>
                  <div className="p-2 rounded-xl bg-white border border-amber-200/80 flex sm:block items-center justify-between gap-2 px-3 sm:px-2">
                    <span className="block text-[10px] text-stone-500">{ar ? 'الباقي في الفرع' : 'Rest at branch'}</span>
                    <strong className="text-xs sm:text-sm text-stone-900">{formatCurrency(remaining, currency, language)}</strong>
                  </div>
                </div>
              </div>

              {getBookingWindow(product).state === 'open' && <CountdownTimer product={product} language={language} />}

              <div className="space-y-3">
                <div>
                  <label className="block text-xs font-bold text-stone-700 mb-1">
                    {t.fullName} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    value={customerName}
                    onChange={(e) => setCustomerName(e.target.value)}
                    placeholder={t.fullNamePlaceholder}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 text-sm"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-stone-700 mb-1">
                    {t.phoneNumber} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="tel"
                    inputMode="tel"
                    value={customerPhone}
                    onChange={(e) => setCustomerPhone(e.target.value)}
                    placeholder="01xxxxxxxxx"
                    className="w-full px-3.5 py-2.5 rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 text-sm dir-ltr text-left"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-stone-700 mb-1">{t.notes}</label>
                  <textarea
                    rows={2}
                    value={customerNotes}
                    onChange={(e) => setCustomerNotes(e.target.value)}
                    placeholder={t.notesPlaceholder}
                    className="w-full px-3.5 py-2 rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 text-xs"
                  />
                </div>
              </div>
            </div>
          )}

          {/* STEP 2: PAY DEPOSIT + PROOF */}
          {step === 2 && (
            <div className="space-y-4">
              <div className="p-4 rounded-2xl bg-emerald-50 border border-emerald-200">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-xs font-bold text-emerald-900">{t.mustPayDeposit}</span>
                  <span className="text-2xl font-black text-emerald-800">{formatCurrency(deposit, currency, language)}</span>
                </div>
                <p className="text-[11px] text-emerald-700 mt-1">
                  {ar ? merchantSettings.depositInstructionsAr : merchantSettings.depositInstructionsEn}
                </p>
              </div>

              <div className="space-y-2.5">
                <label className="block text-xs font-bold text-stone-800">{t.paymentMethodsAvailable}</label>

                {/* Vodafone Cash */}
                <div
                  onClick={() => setSelectedMethod('vodafone_cash')}
                  className={`p-3.5 rounded-2xl border cursor-pointer transition-all ${
                    selectedMethod === 'vodafone_cash' ? 'border-red-500 bg-red-50/40 ring-2 ring-red-200' : 'border-stone-200 bg-white hover:bg-stone-50'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="w-8 h-8 rounded-lg bg-red-600 text-white flex items-center justify-center">
                        <Smartphone className="w-4 h-4" />
                      </div>
                      <div>
                        <div className="font-bold text-sm text-stone-900">{t.vfCashTitle}</div>
                        <div className="text-[11px] text-stone-500">
                          {t.vfCashDesc}{vfNumbers.length > 1 ? (ar ? ' — أي رقم من دول' : ' — any of these') : ''}
                        </div>
                      </div>
                    </div>
                    <input type="radio" name="paymentMethod" checked={selectedMethod === 'vodafone_cash'} onChange={() => setSelectedMethod('vodafone_cash')} className="accent-red-600" />
                  </div>
                  {selectedMethod === 'vodafone_cash' && (
                    <div className="mt-3 space-y-2">
                      {vfNumbers.map((num, i) => (
                        <div key={num + i} className="flex items-center justify-between bg-white p-2.5 rounded-xl border border-red-200">
                          <span className="font-mono font-bold text-base text-stone-900 tracking-wider dir-ltr">{num}</span>
                          <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); handleCopy(num, `vf-${i}`); }}
                            className="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-bold hover:bg-red-700 flex items-center gap-1.5 shadow-sm"
                          >
                            {copiedKey === `vf-${i}` ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                            <span>{copiedKey === `vf-${i}` ? t.copied : t.copyNumber}</span>
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>

                {/* InstaPay */}
                <div
                  onClick={() => setSelectedMethod('instapay')}
                  className={`p-3.5 rounded-2xl border cursor-pointer transition-all ${
                    selectedMethod === 'instapay' ? 'border-indigo-500 bg-indigo-50/40 ring-2 ring-indigo-200' : 'border-stone-200 bg-white hover:bg-stone-50'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold text-[10px]">IPA</div>
                      <div>
                        <div className="font-bold text-sm text-stone-900">{t.instapayTitle}</div>
                        <div className="text-[11px] text-stone-500">{t.instapayDesc}</div>
                      </div>
                    </div>
                    <input type="radio" name="paymentMethod" checked={selectedMethod === 'instapay'} onChange={() => setSelectedMethod('instapay')} className="accent-indigo-600" />
                  </div>
                  {selectedMethod === 'instapay' && (
                    <div className="mt-3 flex items-center justify-between bg-white p-2.5 rounded-xl border border-indigo-200">
                      <span className="font-mono font-bold text-base text-stone-900 tracking-wider dir-ltr">{merchantSettings.instapayAddress}</span>
                      <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); handleCopy(merchantSettings.instapayAddress, 'insta'); }}
                        className="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700 flex items-center gap-1.5 shadow-sm"
                      >
                        {copiedKey === 'insta' ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                        <span>{copiedKey === 'insta' ? t.copied : t.copyNumber}</span>
                      </button>
                    </div>
                  )}
                </div>
              </div>

              {/* Proof: upload OR WhatsApp */}
              <div className="p-3.5 rounded-2xl bg-stone-50 border border-stone-200 space-y-3">
                <div>
                  <h4 className="text-sm font-bold text-stone-900">{t.proofTitle}</h4>
                  <p className="text-[11px] text-stone-600 mt-0.5">{t.proofDesc}</p>
                </div>

                {proofImage ? (
                  <div className="relative rounded-2xl overflow-hidden border border-stone-300 max-h-48 bg-stone-900 flex items-center justify-center">
                    <img src={proofImage} alt="Payment proof" className="max-h-48 object-contain" />
                    <button
                      type="button"
                      onClick={() => setProofImage(null)}
                      className="absolute top-2 right-2 bg-stone-900/80 text-white text-xs px-2.5 py-1 rounded-lg hover:bg-stone-900"
                    >
                      {t.changeImage}
                    </button>
                  </div>
                ) : (
                  <label className="flex flex-col items-center justify-center p-5 border-2 border-dashed border-stone-300 rounded-2xl hover:border-amber-500 cursor-pointer bg-white transition-colors">
                    <Upload className="w-7 h-7 text-stone-400 mb-1.5" />
                    <span className="text-xs font-semibold text-stone-700">{t.uploadDragDrop}</span>
                    <input type="file" accept="image/*" onChange={handleFileUpload} className="hidden" />
                  </label>
                )}

                <div className="flex items-center gap-2 text-[11px] text-stone-400">
                  <div className="h-px flex-1 bg-stone-200" />
                  <span>{ar ? 'أو' : 'or'}</span>
                  <div className="h-px flex-1 bg-stone-200" />
                </div>

                <a
                  href={proofWhatsAppUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  onClick={() => { setProofSentOnWhatsApp(true); setErrorMessage(''); }}
                  className="w-full py-3 rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] text-white text-sm font-extrabold flex items-center justify-center gap-2 shadow transition-colors"
                >
                  <MessageCircle className="w-5 h-5" />
                  <span>{t.sendProofWhatsApp}</span>
                  <span className="font-mono text-xs opacity-90 dir-ltr">{merchantSettings.whatsappNumber}</span>
                </a>
                {proofSentOnWhatsApp && (
                  <p className="text-[11px] text-emerald-700 font-bold flex items-center gap-1">
                    <Check className="w-3.5 h-3.5" />
                    {ar ? 'تمام — بعد ما تبعت الصورة على واتساب ارجع هنا ودوس على الزرار تحت.' : 'Great — after sending the screenshot, come back and tap the button below.'}
                  </p>
                )}

                <div>
                  <label className="block text-[11px] font-bold text-stone-600 mb-1">{t.refNumberLabel}</label>
                  <input
                    type="text"
                    value={referenceNumber}
                    onChange={(e) => setReferenceNumber(e.target.value)}
                    placeholder={t.refPlaceholder}
                    className="w-full px-3 py-2 rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 text-xs font-mono"
                  />
                </div>
              </div>
            </div>
          )}

          {/* STEP 3: CODE + RECEIPT / COUPON */}
          {step === 3 && createdReservation && (
            <div className="space-y-4">
              <div className="text-center">
                <div className="w-14 h-14 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto">
                  <CheckCircle2 className="w-9 h-9" />
                </div>
                <h3 className="text-xl font-black text-stone-900 mt-2">{t.congratsTitle}</h3>
                <p className="text-xs text-stone-600 mt-1 max-w-md mx-auto">{t.congratsDesc}</p>
              </div>

              {/* The receipt (printed / saved as image) */}
              <div id="printable-voucher" ref={voucherRef} dir={ar ? 'rtl' : 'ltr'} className="bg-white max-w-md mx-auto rounded-3xl border-2 border-dashed border-stone-300 overflow-hidden">
                <div className="bg-stone-900 text-white px-5 py-4 text-center">
                  <div className="flex items-center justify-center gap-1.5 text-amber-300 text-xs font-bold">
                    <Ticket className="w-4 h-4" />
                    <span>{ar ? 'وصل حجز — كوبون استلام' : 'Booking Receipt — Pickup Coupon'}</span>
                  </div>
                  <div className="text-sm font-bold text-stone-200 mt-0.5">{ar ? merchantSettings.storeNameAr : merchantSettings.storeNameEn}</div>
                  <div className="text-[10px] text-stone-400 mt-2">{t.yourBookingCode}</div>
                  <div className="font-mono font-black tracking-widest select-all dir-ltr text-[clamp(1.6rem,8.5vw,2.25rem)] leading-tight">{createdReservation.code}</div>
                  <div className="text-[10px] text-stone-400 mt-1">{formatDate(createdReservation.createdAt, language)}</div>
                </div>

                <div className="px-5 py-4 text-xs text-stone-700 space-y-2">
                  <div className="flex justify-between gap-3">
                    <span className="text-stone-500">{ar ? 'الاسم' : 'Name'}</span>
                    <strong className="text-stone-900">{createdReservation.customerName}</strong>
                  </div>
                  <div className="flex justify-between gap-3">
                    <span className="text-stone-500">{ar ? 'الموبايل' : 'Mobile'}</span>
                    <strong className="text-stone-900 dir-ltr">{createdReservation.customerPhone}</strong>
                  </div>
                  <div className="flex justify-between gap-3">
                    <span className="text-stone-500 shrink-0">{ar ? 'المنتج' : 'Product'}</span>
                    <strong className="text-stone-900 text-end">{ar ? createdReservation.productNameAr : createdReservation.productNameEn}</strong>
                  </div>
                  <div className="flex justify-between gap-3">
                    <span className="text-stone-500">{ar ? 'الكمية' : 'Quantity'}</span>
                    <strong className="text-stone-900">{ar ? 'قطعة واحدة' : '1 piece'}</strong>
                  </div>

                  <div className="border-t border-dashed border-stone-300 pt-2 space-y-2">
                    <div className="flex justify-between gap-3">
                      <span className="text-stone-500">{t.totalPrice}</span>
                      <strong className="text-stone-900">{formatCurrency(createdReservation.totalPrice, currency, language)}</strong>
                    </div>
                    <div className="flex items-center justify-between gap-3 p-2 rounded-lg bg-emerald-50 border border-emerald-200">
                      <span className="text-emerald-900 font-bold">
                        {ar ? 'المبلغ المدفوع (مقدم)' : 'Amount paid (deposit)'}
                        <span className="block text-[10px] font-medium text-emerald-700">{methodLabel(createdReservation.paymentMethod)}</span>
                      </span>
                      <strong className="text-base font-black text-emerald-800">{formatCurrency(createdReservation.depositAmount, currency, language)}</strong>
                    </div>
                    <div className="flex items-center justify-between gap-3 p-2 rounded-lg bg-amber-50 border border-amber-200">
                      <span className="text-amber-950 font-bold">{t.remainingAtBranch}</span>
                      <strong className="text-base font-black text-stone-900">{formatCurrency(createdReservation.remainingAmount, currency, language)}</strong>
                    </div>
                  </div>

                  <div className="flex justify-between gap-3 pt-1">
                    <span className="text-stone-500">{t.estimatedDelivery}</span>
                    <strong className="text-stone-900">{ar ? createdReservation.estimatedDeliveryAr : createdReservation.estimatedDeliveryEn}</strong>
                  </div>
                  {merchantSettings.branches?.some(b => b.name.trim() || b.address.trim()) ? (
                    <div className="pt-1 space-y-1">
                      <span className="text-stone-500 flex items-center gap-1"><MapPin className="w-3 h-3" />{ar ? 'الاستلام من أي فرع من فروعنا:' : 'Pick up at any branch:'}</span>
                      {merchantSettings.branches.filter(b => b.name.trim() || b.address.trim()).map((b, i) => (
                        <div key={i} className="text-[11px] leading-snug">
                          <strong className="text-stone-900">{b.name}</strong>
                          {b.address && <span className="text-stone-600"> — {b.address}</span>}
                        </div>
                      ))}
                      {merchantSettings.branchPhones?.length > 0 && (
                        <div className="text-[11px] text-stone-600 dir-ltr text-end font-mono">{merchantSettings.branchPhones.join(' - ')}</div>
                      )}
                    </div>
                  ) : branchAddress && (
                    <div className="flex justify-between gap-3">
                      <span className="text-stone-500 flex items-center gap-1 shrink-0"><MapPin className="w-3 h-3" />{ar ? 'الفرع' : 'Branch'}</span>
                      <strong className="text-stone-900 text-end">{branchAddress}</strong>
                    </div>
                  )}
                  <div className="text-[10px] text-stone-500 text-center">
                    {ar ? 'حالة المقدم: قيد المراجعة — هيتأكد بعد مطابقة التحويل' : 'Deposit status: under review'}
                  </div>
                </div>

                <div className="mx-4 mb-4 p-3 rounded-2xl bg-stone-900 text-amber-300 text-center text-xs sm:text-sm font-extrabold leading-relaxed">
                  ⚠️ {t.saveCodeNotice}
                </div>
              </div>

              {/* Actions */}
              <div className="max-w-md mx-auto grid grid-cols-2 gap-2">
                <button
                  onClick={() => {
                    try { navigator.clipboard.writeText(createdReservation.code); } catch { /* ignore */ }
                    setCodeCopied(true);
                    setTimeout(() => setCodeCopied(false), 2000);
                  }}
                  className="py-2.5 rounded-xl bg-amber-400 hover:bg-amber-300 text-stone-950 font-extrabold text-xs flex items-center justify-center gap-1.5"
                >
                  {codeCopied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                  <span>{codeCopied ? t.codeCopied : t.copyCode}</span>
                </button>
                <button
                  onClick={handleSaveVoucherImage}
                  disabled={isSavingImage}
                  className="py-2.5 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 font-bold text-xs flex items-center justify-center gap-1.5 disabled:opacity-60"
                >
                  <Download className="w-4 h-4" />
                  <span>{t.saveVoucherImage}</span>
                </button>
                <a
                  href={receiptWhatsAppUrl(createdReservation)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="col-span-2 py-3 rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] text-white text-sm font-extrabold flex items-center justify-center gap-2 shadow"
                >
                  <MessageCircle className="w-5 h-5" />
                  <span>{t.whatsappShare}</span>
                </a>
                <button
                  onClick={() => window.print()}
                  className="py-2.5 rounded-xl border border-stone-200 hover:bg-stone-50 text-stone-700 text-xs font-semibold flex items-center justify-center gap-1.5"
                >
                  <Printer className="w-4 h-4" />
                  <span>{t.printVoucher}</span>
                </button>
                <button
                  onClick={() => { onClose(); onNavigateToTrack(createdReservation.code); }}
                  className="py-2.5 rounded-xl border border-stone-200 hover:bg-stone-50 text-stone-700 text-xs font-semibold flex items-center justify-center gap-1.5"
                >
                  <QrCode className="w-4 h-4" />
                  <span>{t.trackDirectly}</span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        {step < 3 && (
          <div className="sheet-footer p-3 sm:p-5 border-t border-stone-200 bg-white flex items-center justify-between gap-2 sm:gap-3">
            <button
              type="button"
              onClick={() => { setErrorMessage(''); if (step === 2) setStep(1); else onClose(); }}
              className="px-4 py-3 sm:py-2.5 rounded-xl border border-stone-200 text-stone-700 hover:bg-stone-50 text-xs font-semibold transition-colors shrink-0"
            >
              {step === 2 ? t.back : t.cancel}
            </button>

            {step === 1 ? (
              <button
                type="button"
                onClick={() => validateStep1() && setStep(2)}
                className="flex-1 sm:flex-none justify-center px-6 py-3 sm:py-2.5 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 text-sm font-bold flex items-center gap-2 shadow"
              >
                <span>{t.nextToPayment}</span>
                <ArrowIcon className="w-4 h-4" />
              </button>
            ) : (
              <button
                type="button"
                disabled={isSubmitting}
                onClick={handleSubmitReservation}
                className="flex-1 sm:flex-none justify-center px-4 sm:px-5 py-3 sm:py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold flex items-center gap-2 shadow disabled:opacity-50"
              >
                <span>{isSubmitting ? t.processingBooking : t.confirmAndLock}</span>
                {!isSubmitting && <CheckCircle2 className="w-4 h-4" />}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
};
