import React, { useState, useEffect } from 'react';
import { 
  Search, 
  CheckCircle2, 
  Clock, 
  Truck, 
  Package, 
  AlertCircle, 
  Printer, 
  Share2, 
  Copy, 
  Check, 
  ArrowRight, 
  ArrowLeft,
  Calendar,
  ShieldCheck,
  User,
  Phone,
  MapPin,
  FileText,
  Link,
  Layers,
  Sparkles,
  ExternalLink,
  RotateCcw,
  ShoppingBag
} from 'lucide-react';
import { Reservation, Language, MerchantSettings, ReservationStatus } from '../types';
import { translations } from '../translations';
import { formatCurrency, formatDate, loadCustomerSavedCodes, addCustomerSavedCode, whatsappLink } from '../utils/storage';
import { trackBookings, fetchBookingsByCodes } from '../utils/api';

interface OrderTrackingViewProps {
  initialCode?: string;
  language: Language;
  currency: string;
  merchantSettings: MerchantSettings;
  onNavigateToStore: () => void;
}

export const OrderTrackingView: React.FC<OrderTrackingViewProps> = ({
  initialCode = '',
  language,
  currency,
  merchantSettings,
  onNavigateToStore,
}) => {
  const t = translations[language];

  // Active Customer Portal Tab: 'search' | 'my_bookings'
  const [activePortalTab, setActivePortalTab] = useState<'search' | 'my_bookings'>('search');

  const [searchInput, setSearchInput] = useState(initialCode);
  const [activeReservation, setActiveReservation] = useState<Reservation | null>(null);
  const [searched, setSearched] = useState(false);
  const [codeCopied, setCodeCopied] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [savedCodes, setSavedCodes] = useState<string[]>([]);
  // Bookings this device made / looked up before ("حجوزاتي"), loaded from the server
  const [savedReservationsList, setSavedReservationsList] = useState<Reservation[]>([]);
  const [results, setResults] = useState<Reservation[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [searchError, setSearchError] = useState('');

  const rememberCodes = (list: Reservation[]) => {
    list.forEach(r => addCustomerSavedCode(r.code));
    setSavedCodes(loadCustomerSavedCodes());
  };

  const runSearch = async (raw: string) => {
    const q = raw.trim();
    if (!q) return;
    setIsSearching(true);
    setSearchError('');
    try {
      const list = await trackBookings(q);
      setResults(list);
      setActiveReservation(list[0] || null);
      setSearched(true);
      rememberCodes(list);
    } catch (e: any) {
      setSearchError(e?.message || 'حصل خطأ، جرّب تاني');
    } finally {
      setIsSearching(false);
    }
  };

  // Load this device's saved bookings from the server
  useEffect(() => {
    const codes = loadCustomerSavedCodes();
    setSavedCodes(codes);
    fetchBookingsByCodes(codes).then(setSavedReservationsList).catch(() => {});
  }, []);

  // Refresh the saved list whenever a new code is remembered
  useEffect(() => {
    if (savedCodes.length) fetchBookingsByCodes(savedCodes).then(setSavedReservationsList).catch(() => {});
  }, [savedCodes.join(',')]);

  // Open a booking passed in the link (?track=RSV-XXXX) or from the booking receipt
  useEffect(() => {
    let codeToLoad = initialCode;
    const urlParams = new URLSearchParams(window.location.search);
    const urlTrack = urlParams.get('track') || urlParams.get('code');
    if (urlTrack) codeToLoad = urlTrack;
    if (codeToLoad) {
      setSearchInput(codeToLoad);
      runSearch(codeToLoad);
    }
  }, [initialCode]);

  // Update URL query string when active reservation changes (for direct link sharing)
  useEffect(() => {
    if (typeof window !== 'undefined' && activeReservation) {
      const url = new URL(window.location.href);
      url.searchParams.set('track', activeReservation.code);
      window.history.replaceState({}, '', url.toString());
    }
  }, [activeReservation]);

  const handleSearch = (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    runSearch(searchInput);
  };

  const handleSelectSavedCode = (code: string) => {
    setSearchInput(code);
    const found = savedReservationsList.find(r => r.code.toUpperCase() === code.toUpperCase());
    if (found) {
      setActiveReservation(found);
      setResults([found]);
      setSearched(true);
      setActivePortalTab('search');
    }
  };

  const handleCopyDirectLink = () => {
    if (typeof window !== 'undefined' && activeReservation) {
      const url = new URL(window.location.href);
      url.searchParams.set('track', activeReservation.code);
      navigator.clipboard.writeText(url.toString());
      setLinkCopied(true);
      setTimeout(() => setLinkCopied(false), 2500);
    }
  };

  const getStepProgressIndex = (status: ReservationStatus): number => {
    switch (status) {
      case 'pending': return 1;
      case 'confirmed': return 2;
      case 'in_preparation': return 3;
      case 'ready_for_delivery': return 4;
      case 'completed': return 5;
      case 'cancelled': return 0;
      default: return 1;
    }
  };

  const stages = [
    { id: 1, title: t.stage1, desc: t.stage1Desc, icon: FileText },
    { id: 2, title: t.stage2, desc: t.stage2Desc, icon: ShieldCheck },
    { id: 3, title: t.stage3, desc: t.stage3Desc, icon: Package },
    { id: 4, title: t.stage4, desc: t.stage4Desc, icon: Truck },
    { id: 5, title: t.stage5, desc: t.stage5Desc, icon: CheckCircle2 },
  ];

  const currentStep = activeReservation ? getStepProgressIndex(activeReservation.reservationStatus) : 1;

  // Status Presentation Helper
  const getStatusPresentation = (status: ReservationStatus) => {
    switch (status) {
      case 'pending':
        return {
          title: language === 'ar' ? 'المقدم قيد المراجعة' : 'Deposit Under Review',
          description: language === 'ar' 
            ? 'تم تسجيل حجزك واستلام إثبات تحويل المقدم، والمتجر بيراجع التحويل لتأكيد الحجز.' 
            : 'Booking registered with deposit proof. The store is verifying your transfer.',
          badgeClass: 'bg-brand-100 text-brand-900 border-brand-300',
          dotClass: 'bg-brand-500',
        };
      case 'confirmed':
        return {
          title: language === 'ar' ? 'المقدم وصل والحجز مؤكد' : 'Deposit Verified — Confirmed',
          description: language === 'ar'
            ? 'تم التأكد من تحويل المقدم، والقطعة محجوزة لك. الباقي بيتدفع في الفرع وقت الاستلام.'
            : 'Deposit verified and your piece is reserved. Pay the rest at the branch on pickup.',
          badgeClass: 'bg-emerald-100 text-emerald-900 border-emerald-300',
          dotClass: 'bg-emerald-600',
        };
      case 'in_preparation':
        return {
          title: language === 'ar' ? 'قيد المعالجة والتجهيز في المستودع' : 'In Processing & Assembly',
          description: language === 'ar'
            ? 'المنتج قيد التجهيز والفحص، وهنبلغك أول ما يبقى جاهز للاستلام من الفرع.'
            : 'Your item is currently undergoing quality inspection and dispatch preparation.',
          badgeClass: 'bg-blue-100 text-blue-900 border-blue-300',
          dotClass: 'bg-blue-600',
        };
      case 'ready_for_delivery':
        return {
          title: language === 'ar' ? 'جاهز للاستلام من الفرع' : 'Ready for Branch Pickup',
          description: language === 'ar'
            ? 'طلبك جاهز! تعالى الفرع ومعاك كوبون الحجز وادفع الباقي واستلم.'
            : 'Your order is ready! Come to the branch with your coupon, pay the rest, and pick it up.',
          badgeClass: 'bg-indigo-100 text-indigo-900 border-indigo-300',
          dotClass: 'bg-indigo-600',
        };
      case 'completed':
        return {
          title: language === 'ar' ? 'تم الاستلام من الفرع' : 'Picked Up',
          description: language === 'ar'
            ? 'تم استلام المنتج من الفرع وسداد الباقي. نشكرك لثقتك بنا!'
            : 'Picked up at the branch and remaining amount paid. Thank you!',
          badgeClass: 'bg-brand-700 text-brand-300 border-brand-800',
          dotClass: 'bg-brand-400',
        };
      case 'cancelled':
        return {
          title: language === 'ar' ? 'تم إلغاء الحجز' : 'Booking Cancelled',
          description: language === 'ar'
            ? 'تم إلغاء هذا الحجز. يرجى التواصل مع المتجر على واتساب للاستفسار عن المقدم.'
            : 'This booking has been cancelled. Please contact support for any inquiries.',
          badgeClass: 'bg-red-100 text-red-900 border-red-300',
          dotClass: 'bg-red-600',
        };
      default:
        return {
          title: language === 'ar' ? 'قيد المعالجة' : 'In Processing',
          description: '',
          badgeClass: 'bg-stone-100 text-stone-900 border-stone-300',
          dotClass: 'bg-stone-500',
        };
    }
  };

  const statusInfo = activeReservation ? getStatusPresentation(activeReservation.reservationStatus) : null;

  const getWhatsAppMessageUrl = (res: Reservation) => {
    const textAr = `مرحباً، أستفسر عن حالة حجز الطلب كود: *${res.code}*\nالمنتج: ${res.productNameAr}\nالاسم: ${res.customerName}\nرابط التتبع المباشر: ${window.location.origin}/?track=${res.code}`;
    return whatsappLink(merchantSettings.whatsappNumber, textAr);
  };


  return (
    <div className="max-w-4xl mx-auto px-4 py-5 sm:py-12 space-y-4 sm:space-y-6">
      
      {/* Customer Portal Top Banner */}
      <div className="bg-white rounded-3xl border border-stone-200 p-4 sm:p-8 shadow-sm text-center">
        

        <h1 className="text-xl sm:text-3xl font-black text-stone-900 tracking-tight text-balance">
          {t.trackTitle}
        </h1>
        <p className="mt-2 text-xs sm:text-sm text-stone-600 leading-relaxed max-w-xl mx-auto">
          {t.trackSubtitle}
        </p>

        {/* Portal Sub-tabs Switcher */}
        <div className="mt-6 inline-flex items-center p-1 bg-stone-100 rounded-2xl border border-stone-200/80">
          <button
            onClick={() => setActivePortalTab('search')}
            className={`flex items-center gap-2 px-4 py-2 text-xs font-bold rounded-xl transition-all ${
              activePortalTab === 'search'
                ? 'bg-white text-stone-900 shadow-sm'
                : 'text-stone-600 hover:text-stone-900'
            }`}
          >
            <Search className="w-3.5 h-3.5" />
            <span>{t.tabTrackByCode}</span>
          </button>

          <button
            onClick={() => setActivePortalTab('my_bookings')}
            className={`flex items-center gap-2 px-4 py-2 text-xs font-bold rounded-xl transition-all ${
              activePortalTab === 'my_bookings'
                ? 'bg-white text-stone-900 shadow-sm'
                : 'text-stone-600 hover:text-stone-900'
            }`}
          >
            <Layers className="w-3.5 h-3.5" />
            <span>{t.tabMyBookings}</span>
            {savedReservationsList.length > 0 && (
              <span className="bg-brand-400 text-stone-950 font-black text-[10px] px-1.5 py-0.2 rounded-full">
                {savedReservationsList.length}
              </span>
            )}
          </button>
        </div>

        {/* SEARCH FORM TAB */}
        {activePortalTab === 'search' && (
          <div className="mt-6 max-w-md mx-auto">
            <form onSubmit={handleSearch} className="flex items-center gap-2">
              <div className="relative flex-1">
                <Search className={`absolute top-1/2 -translate-y-1/2 w-4 h-4 text-stone-400 ${
                  language === 'ar' ? 'right-3.5' : 'left-3.5'
                }`} />
                <input
                  type="text"
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  placeholder={t.trackInputPlaceholder}
                  className={`w-full py-3 px-4 bg-stone-50 border border-stone-300 rounded-xl text-stone-900 text-xs sm:text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-stone-900 shadow-inner ${
                    language === 'ar' ? 'pr-10' : 'pl-10'
                  }`}
                />
              </div>
              <button
                type="submit"
                disabled={isSearching}
                className="py-3 px-4 sm:px-5 bg-brand-700 hover:bg-brand-800 text-white font-bold text-xs sm:text-sm rounded-xl shadow transition-colors shrink-0 disabled:opacity-60"
              >
                {isSearching ? (language === 'ar' ? 'بدوّر...' : 'Searching...') : t.trackButton}
              </button>
            </form>
            {searchError && (
              <p className="mt-3 text-xs font-bold text-red-600">{searchError}</p>
            )}
            {results.length > 1 && (
              <div className="mt-3 flex flex-wrap items-center justify-center gap-2 text-[11px] text-stone-500">
                <span>{language === 'ar' ? `لقينا ${results.length} حجوزات بالرقم ده:` : `${results.length} bookings found:`}</span>
                {results.map(r => (
                  <button
                    key={r.code}
                    type="button"
                    onClick={() => setActiveReservation(r)}
                    className={`font-mono font-bold px-2 py-0.5 rounded border transition-colors ${activeReservation?.code === r.code ? 'bg-brand-700 text-brand-300 border-stone-900' : 'text-stone-700 bg-stone-100 hover:bg-stone-200 border-stone-300/80'}`}
                  >
                    {r.code}
                  </button>
                ))}
              </div>
            )}

          </div>
        )}

        {/* MY SAVED BOOKINGS TAB (Customer Dashboard) */}
        {activePortalTab === 'my_bookings' && (
          <div className="mt-6 text-right rtl:text-right ltr:text-left">
            {savedReservationsList.length === 0 ? (
              <div className="p-8 text-center bg-stone-50 rounded-2xl border border-stone-200 text-xs text-stone-500">
                {t.noSavedBookings}
              </div>
            ) : (
              <div className="space-y-3">
                <div className="text-xs font-bold text-stone-700 px-1">
                  {language === 'ar' ? 'الحجوزات المرتبطة بجهازك الحالي:' : 'Bookings associated with this device:'}
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  {savedReservationsList.map((r) => {
                    const statusData = getStatusPresentation(r.reservationStatus);
                    const isSelected = activeReservation?.code === r.code;
                    return (
                      <div
                        key={r.code}
                        onClick={() => handleSelectSavedCode(r.code)}
                        className={`p-4 rounded-2xl border text-xs cursor-pointer transition-all ${
                          isSelected 
                            ? 'bg-brand-50/60 border-brand-400 ring-2 ring-brand-200' 
                            : 'bg-stone-50/70 border-stone-200 hover:bg-stone-100/70'
                        }`}
                      >
                        <div className="flex items-center justify-between mb-2">
                          <span className="font-mono font-bold text-stone-900 bg-white px-2 py-0.5 rounded border border-stone-200">
                            {r.code}
                          </span>
                          <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold border ${statusData.badgeClass}`}>
                            {statusData.title}
                          </span>
                        </div>

                        <div className="flex items-center gap-2.5">
                          <img
                            src={r.productImage}
                            alt={r.productNameAr}
                            className="w-10 h-10 rounded-xl object-cover border border-stone-200 shrink-0"
                          />
                          <div className="flex-1 min-w-0">
                            <div className="font-bold text-stone-900 truncate">
                              {language === 'ar' ? r.productNameAr : r.productNameEn}
                            </div>
                            <div className="text-[11px] text-stone-500">
                              {language === 'ar' ? 'مقدم: ' : 'Deposit: '}<strong className="text-emerald-700">{formatCurrency(r.depositAmount, currency, language)}</strong>
                            </div>
                          </div>
                        </div>

                        <div className="mt-2.5 pt-2 border-t border-stone-200/60 flex items-center justify-between text-[11px] text-stone-500">
                          <span>{formatDate(r.createdAt, language)}</span>
                          <span className="text-stone-900 font-bold hover:underline">
                            عرض التفاصيل الكاملة &rarr;
                          </span>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        )}

      </div>

      {/* NOT FOUND ALERT */}
      {searched && !activeReservation && (
        <div className="p-8 bg-white rounded-3xl border border-stone-200 text-center shadow-sm max-w-md mx-auto space-y-3">
          <div className="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto">
            <AlertCircle className="w-6 h-6" />
          </div>
          <h3 className="text-base font-bold text-stone-900">{t.trackingNotFound}</h3>
          <p className="text-xs text-stone-500">
            {language === 'ar' 
              ? 'تأكد من إدخال رمز الحجز كما هو (مثال: RSV-849201) أو ابحث برقم الهاتف المستخدم أثناء الحجز.' 
              : 'Make sure your booking code matches (e.g. RSV-849201) or search by phone number.'}
          </p>
        </div>
      )}

      {/* ORDER DETAILS & LIVE TRACKING EXPERIENCE */}
      {activeReservation && (
        <div className="space-y-6" id="printable-voucher">
          
          {/* 1. CURRENT STATUS HERO BANNER (Requested by User: بما في ذلك حالته الحالية) */}
          {statusInfo && (
            <div className={`p-5 sm:p-6 rounded-3xl border shadow-sm ${statusInfo.badgeClass}`}>
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className={`w-3.5 h-3.5 rounded-full ${statusInfo.dotClass} animate-pulse shrink-0`} />
                  <div>
                    <span className="text-[11px] font-bold uppercase tracking-wider block opacity-80">
                      {t.currentStatusLabel}
                    </span>
                    <h2 className="text-lg sm:text-xl font-black tracking-tight mt-0.5">
                      {statusInfo.title}
                    </h2>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {/* Copy Direct Link Button */}
                  <button
                    onClick={handleCopyDirectLink}
                    className="px-3.5 py-1.5 rounded-xl bg-white/90 hover:bg-white text-stone-900 text-xs font-bold flex items-center gap-1.5 shadow-sm border border-stone-200 transition-colors"
                    title={t.directLinkNotice}
                  >
                    {linkCopied ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Link className="w-3.5 h-3.5 text-stone-600" />}
                    <span>{linkCopied ? t.linkCopied : t.copyDirectLink}</span>
                  </button>
                </div>
              </div>

              <p className="mt-2 text-xs leading-relaxed opacity-90 max-w-2xl">
                {statusInfo.description}
              </p>
            </div>
          )}

          {/* 2. MAIN ORDER CARD */}
          <div className="bg-white rounded-3xl border border-stone-200 p-4 sm:p-8 shadow-sm space-y-5 sm:space-y-6">
            
            {/* Header: Code & Quick Copy */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-stone-100 pb-5">
              <div>
                <span className="text-[11px] text-stone-500 uppercase tracking-wider block font-semibold">
                  {language === 'ar' ? 'رمز الحجز الخاص بك' : 'Your Official Booking Code'}
                </span>
                <div className="flex items-center gap-3 mt-1">
                  <h2 className="text-[clamp(1.4rem,7vw,1.875rem)] font-mono font-black text-stone-900 tracking-wider">
                    {activeReservation.code}
                  </h2>
                  <button
                    onClick={() => {
                      navigator.clipboard.writeText(activeReservation.code);
                      setCodeCopied(true);
                      setTimeout(() => setCodeCopied(false), 2000);
                    }}
                    className="p-1.5 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-600 text-xs transition-colors"
                    title={t.copyCode}
                  >
                    {codeCopied ? <Check className="w-4 h-4 text-emerald-600" /> : <Copy className="w-4 h-4" />}
                  </button>
                </div>
                <div className="text-xs text-stone-500 mt-1">
                  {language === 'ar' ? 'تاريخ تسجيل الحجز:' : 'Registered on:'} {formatDate(activeReservation.createdAt, language)}
                </div>
              </div>

              {/* Booking Confirmation Badge */}
              <div className="flex flex-col items-start sm:items-end gap-1.5">
                {activeReservation.reservationStatus !== 'cancelled' && (
                <div className={`px-3.5 py-1.5 rounded-full text-xs font-bold flex items-center gap-1.5 shadow-sm ${
                  activeReservation.paymentStatus !== 'pending_verification'
                    ? 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                    : 'bg-brand-100 text-brand-900 border border-brand-200'
                }`}>
                  <ShieldCheck className="w-4 h-4" />
                  <span>
                    {activeReservation.paymentStatus !== 'pending_verification' ? t.bookingConfirmedBadge : t.bookingPendingBadge}
                  </span>
                </div>
                )}

                <div className="text-xs text-stone-600">
                  {t.estimatedDelivery}: <strong className="text-stone-900">{language === 'ar' ? activeReservation.estimatedDeliveryAr : activeReservation.estimatedDeliveryEn}</strong>
                </div>
              </div>
            </div>

            {/* Product Summary Row */}
            <div className="py-2 flex items-center justify-between gap-4 flex-wrap">
              <div className="flex items-center gap-4">
                <img
                  src={activeReservation.productImage || 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=200&q=80'}
                  alt="Product"
                  className="w-16 h-16 rounded-2xl object-cover border border-stone-200 shadow-inner"
                />
                <div>
                  <h3 className="font-extrabold text-base text-stone-900">
                    {language === 'ar' ? activeReservation.productNameAr : activeReservation.productNameEn}
                  </h3>
                  <div className="text-xs text-stone-500 mt-0.5">
                    {language === 'ar' ? 'الكمية المحجوزة:' : 'Reserved Quantity:'} <strong className="text-stone-800">{language === 'ar' ? 'قطعة واحدة' : '1 piece'}</strong>
                  </div>
                </div>
              </div>

              {/* Financial Box */}
              <div className="bg-stone-50 p-4 rounded-2xl border border-stone-200/80 text-xs space-y-1.5 w-full sm:w-auto sm:min-w-[220px]">
                <div className="flex justify-between text-stone-900 font-bold">
                  <span>{t.totalPrice}:</span>
                  <span>{formatCurrency(activeReservation.totalPrice, currency, language)}</span>
                </div>
                <div className="h-px bg-stone-200" />
                <div className="flex justify-between">
                  <span className="text-stone-500">{language === 'ar' ? 'المقدم المدفوع' : 'Deposit paid'} ({activeReservation.paymentMethod === 'instapay' ? (language === 'ar' ? 'إنستاباي' : 'InstaPay') : (language === 'ar' ? 'فودافون كاش' : 'Vodafone Cash')}):</span>
                  <strong className="text-emerald-700 font-extrabold">{formatCurrency(activeReservation.depositAmount, currency, language)}</strong>
                </div>
                <div className="flex justify-between">
                  <span className="text-stone-500">{t.remainingAtBranch}:</span>
                  <strong className="text-stone-900 font-extrabold">{formatCurrency(activeReservation.remainingAmount, currency, language)}</strong>
                </div>
              </div>
            </div>

            {/* 3. INTERACTIVE TIMELINE (المسار الزمني لمراحل الطلب) */}
            <div className="pt-4 border-t border-stone-100">
              <h4 className="text-xs font-bold text-stone-800 mb-4 uppercase tracking-wider">
                {t.orderTimeline}
              </h4>

              <div className="grid grid-cols-1 md:grid-cols-5 gap-2 md:gap-3 relative">
                {stages.map((stage) => {
                  const isPassed = currentStep >= stage.id;
                  const isCurrent = currentStep === stage.id;
                  const Icon = stage.icon;

                  return (
                    <div
                      key={stage.id}
                      className={`p-3.5 rounded-2xl border transition-all ${
                        isCurrent
                          ? 'bg-brand-50/80 border-brand-300 ring-2 ring-brand-200 shadow-sm'
                          : isPassed
                          ? 'bg-emerald-50/60 border-emerald-200 text-emerald-900'
                          : 'bg-stone-50/50 border-stone-200 text-stone-400 opacity-60'
                      }`}
                    >
                      <div className="flex items-center gap-2 mb-1.5">
                        <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold ${
                          isPassed ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-600'
                        }`}>
                          {isPassed ? <Check className="w-3.5 h-3.5" /> : stage.id}
                        </div>
                        <span className={`text-xs font-extrabold line-clamp-1 ${isPassed ? 'text-stone-900' : 'text-stone-500'}`}>
                          {stage.title}
                        </span>
                      </div>
                      <p className="text-[11px] text-stone-500 leading-tight">
                        {stage.desc}
                      </p>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* 4. RELEVANT DETAILS: CUSTOMER, PAYMENT & MERCHANT LOG */}
            <div className="pt-4 border-t border-stone-100 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
              
              {/* Recipient Details */}
              <div className="p-4 rounded-2xl bg-stone-50 border border-stone-200 space-y-2">
                <h5 className="font-bold text-stone-900 flex items-center gap-1.5 mb-2">
                  <User className="w-3.5 h-3.5 text-stone-500" />
                  <span>{t.customerInfo}</span>
                </h5>
                <div className="flex justify-between text-stone-600">
                  <span>الاسم:</span>
                  <strong className="text-stone-900">{activeReservation.customerName}</strong>
                </div>
                <div className="flex justify-between text-stone-600">
                  <span>الهاتف:</span>
                  <strong className="text-stone-900 dir-ltr">{activeReservation.customerPhone}</strong>
                </div>
                <div className="flex justify-between text-stone-600">
                  <span>الاستلام:</span>
                  <strong className="text-stone-900">{(language === 'ar' ? merchantSettings.branchAddressAr : merchantSettings.branchAddressEn) || 'من الفرع'}</strong>
                </div>
                <div className="flex justify-between text-stone-600">
                  <span>إثبات التحويل:</span>
                  <strong className="text-stone-900">{(activeReservation.hasProofImage || activeReservation.paymentProofUrl) ? 'صورة مرفوعة' : activeReservation.proofSentOnWhatsApp ? 'اتبعت على واتساب' : (activeReservation.paymentReferenceNumber || '—')}</strong>
                </div>
                {activeReservation.customerNotes && (
                  <div className="pt-1 text-[11px] text-stone-500 border-t border-stone-200">
                    <span>ملاحظات خاصة: {activeReservation.customerNotes}</span>
                  </div>
                )}
              </div>

              {/* Status Updates & Merchant Notes Log */}
              <div className="p-4 rounded-2xl bg-stone-50 border border-stone-200 space-y-2">
                <h5 className="font-bold text-stone-900 flex items-center gap-1.5 mb-2">
                  <Clock className="w-3.5 h-3.5 text-stone-500" />
                  <span>{language === 'ar' ? 'سجل تحديثات الحجز وملاحظات المتجر' : 'Order Updates & Notes'}</span>
                </h5>

                <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
                  {activeReservation.history?.map((h, i) => (
                    <div key={i} className="text-[11px] border-r-2 border-emerald-500 pr-2 rtl:border-r-2 rtl:border-l-0 ltr:border-l-2 ltr:border-r-0 ltr:pl-2">
                      <div className="text-stone-400 text-[10px]">{formatDate(h.timestamp, language)}</div>
                      <div className="text-stone-700 font-medium">
                        {language === 'ar' ? h.noteAr : h.noteEn}
                      </div>
                    </div>
                  ))}

                  {activeReservation.merchantNotes && (
                    <div className="mt-2 p-2.5 rounded-xl bg-brand-50 border border-brand-200 text-[11px] text-brand-900">
                      <strong>ملاحظة من المتجر:</strong> {activeReservation.merchantNotes}
                    </div>
                  )}
                </div>
              </div>

            </div>

            {/* Direct Link Banner */}
            <div className="p-3.5 rounded-2xl bg-stone-100/70 border border-stone-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
              <div className="flex items-center gap-2 text-stone-700">
                <Link className="w-4 h-4 text-stone-500 shrink-0" />
                <span>{t.directLinkNotice}</span>
              </div>
              <button
                onClick={handleCopyDirectLink}
                className="px-3.5 py-1.5 rounded-xl bg-brand-700 hover:bg-brand-800 text-white font-bold text-xs flex items-center gap-1.5 transition-colors shrink-0"
              >
                {linkCopied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{linkCopied ? t.linkCopied : t.copyDirectLink}</span>
              </button>
            </div>

            {/* Bottom Actions */}
            <div className="pt-4 border-t border-stone-100 flex flex-wrap items-center justify-between gap-3">
              <button
                onClick={() => window.print()}
                className="px-4 py-2.5 rounded-xl border border-stone-200 hover:bg-stone-50 text-stone-700 text-xs font-semibold flex items-center gap-2 transition-colors"
              >
                <Printer className="w-4 h-4" />
                <span>{t.printVoucher}</span>
              </button>

              <div className="flex items-center gap-2">
                <a
                  href={getWhatsAppMessageUrl(activeReservation)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold flex items-center gap-2 shadow transition-colors"
                >
                  <Share2 className="w-4 h-4" />
                  <span>{t.contactMerchantOnWhatsApp}</span>
                </a>

                <button
                  onClick={onNavigateToStore}
                  className="px-4 py-2.5 rounded-xl bg-brand-700 hover:bg-brand-800 text-white text-xs font-bold transition-colors"
                >
                  {t.navBrowse}
                </button>
              </div>
            </div>

          </div>

        </div>
      )}

    </div>
  );
};
