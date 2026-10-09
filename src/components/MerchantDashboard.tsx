import React, { useState } from 'react';
import { 
  ShoppingBag, 
  Search, 
  Filter, 
  Download, 
  Plus, 
  Edit3, 
  Trash2, 
  Check, 
  X, 
  AlertCircle, 
  CheckCircle2, 
  Clock, 
  Truck, 
  CreditCard, 
  Smartphone, 
  Layers, 
  BarChart3, 
  Settings as SettingsIcon, 
  ExternalLink,
  Phone,
  MessageCircle,
  FileText,
  DollarSign,
  TrendingUp,
  Package,
  Eye,
  Save,
  Volume2,
  VolumeX,
  ShieldAlert
} from 'lucide-react';
import { 
  Product, 
  Reservation, 
  MerchantSettings, 
  Language, 
  ReservationStatus,
  PaymentStatus,
} from '../types';
import { translations } from '../translations';
import { formatCurrency, formatDate, exportReservationsToCSV, whatsappLink } from '../utils/storage';
import { NewProductModal } from './NewProductModal';
import { getBookingWindow, splitDuration, useNow } from '../utils/bookingWindow';
import { useEscapeKey } from '../utils/useDismiss';

interface MerchantDashboardProps {
  products: Product[];
  reservations: Reservation[];
  merchantSettings: MerchantSettings;
  language: Language;
  currency: string;
  onUpdateReservations: (reservations: Reservation[]) => void;
  onUpdateProducts: (products: Product[]) => void;
  onUpdateSettings: (settings: MerchantSettings) => void;
  onSaveCredentials: (username: string, current: string, next: string) => Promise<void>;
  onDeleteReservations: (ids: string[]) => Promise<void>;
  selectedReservationCode?: string;
}

export const MerchantDashboard: React.FC<MerchantDashboardProps> = ({
  products,
  reservations,
  merchantSettings,
  language,
  currency,
  onUpdateReservations,
  onUpdateProducts,
  onUpdateSettings,
  onSaveCredentials,
  onDeleteReservations,
  selectedReservationCode,
}) => {
  const t = translations[language];

  // Active top-level tab
  const [activeTab, setActiveTab] = useState<'reservations' | 'inventory' | 'analytics' | 'settings'>('reservations');

  // Reservations Filter & Search
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>(selectedReservationCode || '');
  const [inspectReservation, setInspectReservation] = useState<Reservation | null>(
    selectedReservationCode ? reservations.find(r => r.code === selectedReservationCode) || null : null
  );

  // Merchant Note edit state in inspect drawer
  const [editNotes, setEditNotes] = useState('');

  // Product modal state
  const [isProductModalOpen, setIsProductModalOpen] = useState(false);
  const [productToEdit, setProductToEdit] = useState<Product | null>(null);

  // Settings form local state
  const [settingsForm, setSettingsForm] = useState<MerchantSettings>(merchantSettings);
  const [settingsSavedToast, setSettingsSavedToast] = useState(false);
  useEscapeKey(() => setInspectReservation(null), Boolean(inspectReservation));
  // Login credentials form (separate from the store settings form)
  const [credUsername, setCredUsername] = useState(merchantSettings.adminUsername);
  const [credCurrent, setCredCurrent] = useState('');
  const [credNew, setCredNew] = useState('');
  const [credConfirm, setCredConfirm] = useState('');
  const [credMessage, setCredMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const now = useNow(30000);

  const describeWindow = (prod: Product) => {
    const w = getBookingWindow(prod, now);
    if (w.state === 'none') return { text: 'بدون عداد', cls: 'bg-stone-100 text-stone-600' };
    const d = splitDuration(w.target!.getTime() - now);
    const left = d.days > 0 ? `${d.days} يوم ${d.hours} ساعة` : `${d.hours} ساعة ${d.minutes} دقيقة`;
    if (w.state === 'ended') return { text: 'الوقت خلص — الحجز مقفول', cls: 'bg-stone-200 text-stone-700' };
    if (w.state === 'not_started') return { text: `يبدأ بعد ${left}`, cls: 'bg-indigo-100 text-indigo-800' };
    return { text: `ينتهي بعد ${left}`, cls: d.days === 0 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900' };
  };

  // Inline Quick price edit states in inventory tab
  const [quickEditId, setQuickEditId] = useState<string | null>(null);
  const [quickPrice, setQuickPrice] = useState<number>(0);
  const [quickQuota, setQuickQuota] = useState<number>(0);
  const [quickDeposit, setQuickDeposit] = useState<number>(0);
  const [branchPhonesText, setBranchPhonesText] = useState((merchantSettings.branchPhones || []).join('\n'));
  const [vfNumbersText, setVfNumbersText] = useState(merchantSettings.vodafoneCashNumbers.join('\n'));

  // Filter reservations
  const filteredReservations = reservations.filter(r => {
    const matchesStatus = statusFilter === 'all' || r.reservationStatus === statusFilter;
    const query = searchQuery.toLowerCase().trim();
    const matchesSearch = !query || 
      r.code.toLowerCase().includes(query) ||
      r.customerName.toLowerCase().includes(query) ||
      r.customerPhone.includes(query) ||
      r.productNameAr.toLowerCase().includes(query);
    return matchesStatus && matchesSearch;
  });

  // Calculate Metrics
  const totalReservationsCount = reservations.length;
  const activeReservations = reservations.filter(r => r.reservationStatus !== 'cancelled');
  const pendingReservations = activeReservations.filter(r => r.paymentStatus === 'pending_verification');
  const pendingCount = pendingReservations.length;
  const pendingDepositsValue = pendingReservations.reduce((sum, r) => sum + r.depositAmount, 0);
  const collectedDeposits = activeReservations
    .filter(r => r.paymentStatus === 'deposit_paid' || r.paymentStatus === 'fully_paid')
    .reduce((sum, r) => sum + r.depositAmount, 0);
  const totalExpectedRevenue = activeReservations.reduce((sum, r) => sum + r.totalPrice, 0);
  // Remaining balances still to be collected at the branch (not yet picked up)
  const remainingAtBranch = activeReservations
    .filter(r => r.reservationStatus !== 'completed')
    .reduce((sum, r) => sum + r.remainingAmount, 0);
  const confirmedReservationsCount = reservations.filter(r => r.reservationStatus === 'confirmed' || r.reservationStatus === 'in_preparation' || r.reservationStatus === 'ready_for_delivery' || r.reservationStatus === 'completed').length;
  const fulfillmentRate = totalReservationsCount > 0 ? Math.round((confirmedReservationsCount / totalReservationsCount) * 100) : 0;

  // Handlers for reservation updates
  const handleConfirmBooking = (resId: string) => {
    const updated = reservations.map(r => {
      if (r.id === resId) {
        const nowIso = new Date().toISOString();
        return {
          ...r,
          paymentStatus: 'deposit_paid' as PaymentStatus,
          reservationStatus: (r.reservationStatus === 'pending' ? 'confirmed' : r.reservationStatus) as ReservationStatus,
          history: [
            ...r.history,
            {
              status: 'confirmed' as ReservationStatus,
              timestamp: nowIso,
              noteAr: `تم التأكد من تحويل المقدم (${formatCurrency(r.depositAmount, merchantSettings.currencyAr, 'ar')}) وتأكيد الحجز.`,
              noteEn: 'Deposit transfer verified. Booking confirmed.',
            },
          ],
        };
      }
      return r;
    });
    onUpdateReservations(updated);
    if (inspectReservation?.id === resId) {
      setInspectReservation(updated.find(r => r.id === resId) || null);
    }
  };

  const handleUpdateStatus = (resId: string, newStatus: ReservationStatus) => {
    const updated = reservations.map(r => {
      if (r.id === resId) {
        const nowIso = new Date().toISOString();
        let noteAr = `تحديث الحالة إلى: ${newStatus}`;
        let noteEn = `Status updated to: ${newStatus}`;
        if (newStatus === 'in_preparation') {
          noteAr = 'الطلب قيد التجهيز والتصنيع الفعلي.';
          noteEn = 'Order in preparation.';
        } else if (newStatus === 'ready_for_delivery') {
          noteAr = 'الطلب جاهز للاستلام من الفرع.';
          noteEn = 'Ready for branch pickup.';
        } else if (newStatus === 'completed') {
          noteAr = 'العميل استلم من الفرع ودفع الباقي.';
          noteEn = 'Picked up at the branch and remaining amount paid.';
        } else if (newStatus === 'confirmed') {
          noteAr = 'تم تأكيد المقدم والحجز.';
          noteEn = 'Deposit and booking confirmed.';
        } else if (newStatus === 'cancelled') {
          noteAr = 'تم إلغاء الحجز.';
          noteEn = 'Booking cancelled.';
        }

        return {
          ...r,
          reservationStatus: newStatus,
          paymentStatus: (newStatus === 'completed'
            ? 'fully_paid'
            : newStatus !== 'pending' && newStatus !== 'cancelled' && r.paymentStatus === 'pending_verification'
              ? 'deposit_paid'
              : r.paymentStatus) as PaymentStatus,
          history: [
            ...r.history,
            {
              status: newStatus,
              timestamp: nowIso,
              noteAr,
              noteEn,
            },
          ],
        };
      }
      return r;
    });
    onUpdateReservations(updated);

    if (inspectReservation?.id === resId) {
      setInspectReservation(updated.find(r => r.id === resId) || null);
    }
  };

  const handleSaveMerchantNote = (resId: string) => {
    const updated = reservations.map(r => {
      if (r.id === resId) {
        return { ...r, merchantNotes: editNotes };
      }
      return r;
    });
    onUpdateReservations(updated);
    if (inspectReservation?.id === resId) {
      setInspectReservation(updated.find(r => r.id === resId) || null);
    }
  };

  // Product Inventory Handlers
  const handleSaveProduct = (prod: Product) => {
    const exists = products.some(p => p.id === prod.id);
    if (exists) {
      onUpdateProducts(products.map(p => p.id === prod.id ? prod : p));
    } else {
      onUpdateProducts([prod, ...products]);
    }
    setProductToEdit(null);
  };

  // Tap once to arm, tap again within 4s to delete (no browser confirm popup needed)
  const [pendingDeleteId, setPendingDeleteId] = useState<string | null>(null);
  const handleDeleteProduct = (id: string) => {
    if (pendingDeleteId === id) {
      onUpdateProducts(products.filter(p => p.id !== id));
      setPendingDeleteId(null);
    } else {
      setPendingDeleteId(id);
      setTimeout(() => setPendingDeleteId(cur => (cur === id ? null : cur)), 4000);
    }
  };

  const handleSaveQuickEdit = (productId: string) => {
    onUpdateProducts(products.map(p => {
      if (p.id === productId) {
        return {
          ...p,
          price: quickPrice,
          depositAmount: Math.min(Math.max(1, quickDeposit), quickPrice),
          reservationQuota: quickQuota,
        };
      }
      return p;
    }));
    setQuickEditId(null);
  };

  // Save Settings
  const handleSaveSettings = (e: React.FormEvent) => {
    e.preventDefault();
    // Never let the store form overwrite login credentials changed in the account section
    onUpdateSettings({ ...settingsForm, adminUsername: merchantSettings.adminUsername, adminPassword: merchantSettings.adminPassword });
    setSettingsSavedToast(true);
    setTimeout(() => setSettingsSavedToast(false), 3000);
  };

  const [credSaving, setCredSaving] = useState(false);
  const handleSaveCredentials = async (e: React.FormEvent) => {
    e.preventDefault();
    const username = credUsername.trim();
    if (!username) {
      setCredMessage({ ok: false, text: 'اكتب اسم المستخدم' });
      return;
    }
    if (!credCurrent) {
      setCredMessage({ ok: false, text: 'اكتب الباسورد الحالي' });
      return;
    }
    if (credNew && credNew !== credConfirm) {
      setCredMessage({ ok: false, text: 'الباسورد الجديد وتأكيده مش زي بعض' });
      return;
    }
    setCredSaving(true);
    try {
      await onSaveCredentials(username, credCurrent, credNew);
      setCredCurrent('');
      setCredNew('');
      setCredConfirm('');
      setCredMessage({ ok: true, text: credNew ? 'تم تغيير اسم المستخدم والباسورد ✓' : 'تم حفظ اسم المستخدم ✓' });
    } catch (err: any) {
      setCredMessage({ ok: false, text: err?.message || 'مقدرتش أحفظ، جرّب تاني' });
    } finally {
      setCredSaving(false);
    }
  };

  // Delete bookings: tap once to arm, tap again within 4s to delete
  const [pendingDeleteResId, setPendingDeleteResId] = useState<string | null>(null);
  const handleDeleteReservation = async (resId: string) => {
    if (pendingDeleteResId !== resId) {
      setPendingDeleteResId(resId);
      setTimeout(() => setPendingDeleteResId(cur => (cur === resId ? null : cur)), 4000);
      return;
    }
    setPendingDeleteResId(null);
    if (inspectReservation?.id === resId) setInspectReservation(null);
    await onDeleteReservations([resId]);
  };

  const getWhatsAppLink = (phone: string, text: string) => {
    return whatsappLink(phone, text);
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      
      {/* Top Banner & Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-stone-200 shadow-sm">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-xs font-bold px-2.5 py-0.5 rounded-full bg-stone-900 text-amber-300">
              Admin Command Center
            </span>
            <span className="text-xs text-stone-500 font-medium">
              {merchantSettings.storeNameAr}
            </span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-black text-stone-900 mt-1">
            {t.merchantTitle}
          </h1>
          <p className="text-xs sm:text-sm text-stone-500 mt-1">
            {t.merchantSubtitle}
          </p>
        </div>

        {/* Tab Switcher */}
        <div className="flex items-center gap-1.5 p-1.5 bg-stone-100 rounded-2xl border border-stone-200/80 overflow-x-auto">
          <button
            onClick={() => setActiveTab('reservations')}
            className={`flex items-center gap-2 px-3.5 py-2 text-xs font-bold rounded-xl whitespace-nowrap transition-all ${
              activeTab === 'reservations'
                ? 'bg-white text-stone-900 shadow-sm'
                : 'text-stone-600 hover:text-stone-900'
            }`}
          >
            <ShoppingBag className="w-4 h-4" />
            <span>{t.tabReservations}</span>
            <span className="bg-stone-200 text-stone-800 text-[10px] px-1.5 py-0.2 rounded-full">
              {reservations.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('inventory')}
            className={`flex items-center gap-2 px-3.5 py-2 text-xs font-bold rounded-xl whitespace-nowrap transition-all ${
              activeTab === 'inventory'
                ? 'bg-white text-stone-900 shadow-sm'
                : 'text-stone-600 hover:text-stone-900'
            }`}
          >
            <Package className="w-4 h-4" />
            <span>{t.tabInventory}</span>
          </button>

          <button
            onClick={() => setActiveTab('analytics')}
            className={`flex items-center gap-2 px-3.5 py-2 text-xs font-bold rounded-xl whitespace-nowrap transition-all ${
              activeTab === 'analytics'
                ? 'bg-white text-stone-900 shadow-sm'
                : 'text-stone-600 hover:text-stone-900'
            }`}
          >
            <BarChart3 className="w-4 h-4" />
            <span>{t.tabAnalytics}</span>
          </button>

          <button
            onClick={() => setActiveTab('settings')}
            className={`flex items-center gap-2 px-3.5 py-2 text-xs font-bold rounded-xl whitespace-nowrap transition-all ${
              activeTab === 'settings'
                ? 'bg-white text-stone-900 shadow-sm'
                : 'text-stone-600 hover:text-stone-900'
            }`}
          >
            <SettingsIcon className="w-4 h-4" />
            <span>{t.tabSettings}</span>
          </button>
        </div>
      </div>

      {/* METRIC CARDS ROW */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div className="p-4 sm:p-5 rounded-2xl bg-white border border-stone-200 shadow-sm">
          <div className="flex items-center justify-between text-xs text-stone-500 font-semibold mb-2">
            <span>{t.statTotalReservations}</span>
            <ShoppingBag className="w-4 h-4 text-stone-400" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-stone-900">
            {totalReservationsCount}
          </div>
          <div className="mt-1 text-[11px] text-emerald-700 font-medium flex items-center gap-1">
            <CheckCircle2 className="w-3.5 h-3.5" />
            <span>كل حجز = قطعة واحدة</span>
          </div>
        </div>

        <div className="p-4 sm:p-5 rounded-2xl bg-white border border-stone-200 shadow-sm">
          <div className="flex items-center justify-between text-xs text-stone-500 font-semibold mb-2">
            <span>{t.statConfirmedBookings}</span>
            <DollarSign className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-emerald-700">
            {formatCurrency(collectedDeposits, currency, language)}
          </div>
          <div className="mt-1 text-[11px] text-stone-500 font-medium">
            {confirmedReservationsCount} حجز مقدمه اتأكد
          </div>
        </div>

        <div className="p-4 sm:p-5 rounded-2xl bg-white border border-stone-200 shadow-sm">
          <div className="flex items-center justify-between text-xs text-stone-500 font-semibold mb-2">
            <span>{t.statPendingBookings}</span>
            <Clock className="w-4 h-4 text-amber-500" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-amber-600">
            {formatCurrency(pendingDepositsValue, currency, language)}
          </div>
          <div className="mt-1 text-[11px] text-amber-800 font-medium">
            {pendingCount} حجز — راجع صورة التحويل أو الواتساب
          </div>
        </div>

        <div className="p-4 sm:p-5 rounded-2xl bg-white border border-stone-200 shadow-sm">
          <div className="flex items-center justify-between text-xs text-stone-500 font-semibold mb-2">
            <span>{t.statTotalRevenue}</span>
            <TrendingUp className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-2xl sm:text-3xl font-black text-stone-900">
            {formatCurrency(totalExpectedRevenue, currency, language)}
          </div>
          <div className="mt-1 text-[11px] text-blue-700 font-medium">
            نسبة الإنجاز: {fulfillmentRate}%
          </div>
        </div>
      </div>

      {/* TAB 1: RESERVATIONS LIST & WORKFLOW */}
      {activeTab === 'reservations' && (
        <div className="bg-white rounded-3xl border border-stone-200 shadow-sm overflow-hidden space-y-4 p-5 sm:p-6">
          
          {/* Action & Filter Toolbar */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              {/* Search input */}
              <div className="relative min-w-[240px]">
                <Search className={`absolute top-1/2 -translate-y-1/2 w-4 h-4 text-stone-400 ${
                  language === 'ar' ? 'right-3' : 'left-3'
                }`} />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder={t.searchReservationsPlaceholder}
                  className={`w-full py-2 px-3 text-xs rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 ${
                    language === 'ar' ? 'pr-9' : 'pl-9'
                  }`}
                />
              </div>

              {/* Status Filter */}
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="py-2 px-3 text-xs rounded-xl border border-stone-300 bg-white font-medium text-stone-700 focus:ring-2 focus:ring-stone-900"
              >
                <option value="all">{t.filterStatusAll}</option>
                <option value="pending">{t.filterPending}</option>
                <option value="confirmed">{t.filterConfirmed}</option>
                <option value="in_preparation">{t.filterPrep}</option>
                <option value="ready_for_delivery">{t.filterShipping}</option>
                <option value="completed">{t.filterCompleted}</option>
                <option value="cancelled">{t.filterCancelled}</option>
              </select>
            </div>

            {/* Export CSV button */}
            <button
              onClick={() => exportReservationsToCSV(reservations)}
              className="py-2 px-4 rounded-xl border border-stone-200 hover:bg-stone-50 text-stone-700 text-xs font-bold flex items-center justify-center gap-1.5 transition-colors shrink-0"
            >
              <Download className="w-3.5 h-3.5" />
              <span>{t.exportCSV}</span>
            </button>
          </div>

          {/* Table Container */}
          <div className="overflow-x-auto rounded-2xl border border-stone-200">
            <table className="w-full text-xs text-right rtl:text-right ltr:text-left">
              <thead className="bg-stone-50 border-b border-stone-200 text-stone-600 font-bold">
                <tr>
                  <th className="py-3 px-3.5">{t.colCode}</th>
                  <th className="py-3 px-3.5">{t.colCustomer}</th>
                  <th className="py-3 px-3.5">{t.colProduct}</th>
                  <th className="py-3 px-3.5">{t.colDeposit}</th>
                  <th className="py-3 px-3.5">{t.colTotal}</th>
                  <th className="py-3 px-3.5">{t.colStatus}</th>
                  <th className="py-3 px-3.5 text-center">{t.colActions}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {filteredReservations.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-8 text-center text-stone-400">
                      لا توجد حجوزات مطابقة للفلاتر الحالية
                    </td>
                  </tr>
                ) : (
                  filteredReservations.map((res) => {
                    const isPending = res.paymentStatus === 'pending_verification' && res.reservationStatus !== 'cancelled';
                    return (
                      <tr 
                        key={res.id} 
                        className={`hover:bg-stone-50/80 transition-colors ${
                          inspectReservation?.id === res.id ? 'bg-amber-50/40' : ''
                        }`}
                      >
                        {/* Code & Date */}
                        <td className="py-3 px-3.5 whitespace-nowrap">
                          <span className="font-mono font-bold text-stone-900 bg-stone-100 px-2 py-0.5 rounded">
                            {res.code}
                          </span>
                          <span className="block text-[10px] text-stone-400 mt-1">
                            {formatDate(res.createdAt, language)}
                          </span>
                        </td>

                        {/* Customer & Phone */}
                        <td className="py-3 px-3.5">
                          <div className="font-bold text-stone-900">{res.customerName}</div>
                          <div className="text-[11px] text-stone-500 dir-ltr">{res.customerPhone}</div>
                        </td>

                        {/* Product & Qty */}
                        <td className="py-3 px-3.5">
                          <div className="font-semibold text-stone-800 line-clamp-1 max-w-[180px]">
                            {language === 'ar' ? res.productNameAr : res.productNameEn}
                          </div>
                          <div className="text-[10px] text-stone-500">قطعة واحدة</div>
                        </td>

                        {/* Deposit, method & proof */}
                        <td className="py-3 px-3.5 whitespace-nowrap">
                          <div className="font-extrabold text-emerald-800">
                            {formatCurrency(res.depositAmount, currency, language)}
                          </div>
                          <div className="text-[10px] text-stone-500">
                            {res.paymentMethod === 'instapay' ? 'إنستاباي' : 'فودافون كاش'}
                            {' · '}
                            {res.paymentProofUrl ? '📎 صورة' : res.proofSentOnWhatsApp ? '💬 واتساب' : res.paymentReferenceNumber ? `#${res.paymentReferenceNumber}` : '—'}
                          </div>
                          <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded inline-block mt-0.5 ${
                            res.paymentStatus === 'pending_verification' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'
                          }`}>
                            {res.paymentStatus === 'pending_verification' ? 'قيد المراجعة ⏳' : res.paymentStatus === 'fully_paid' ? 'مدفوع بالكامل ✓' : 'معتمد ✓'}
                          </span>
                        </td>

                        {/* Total & remaining at branch */}
                        <td className="py-3 px-3.5 whitespace-nowrap">
                          <div className="font-bold text-stone-900">
                            {formatCurrency(res.totalPrice, currency, language)}
                          </div>
                          <div className="text-[10px] text-stone-500">
                            الباقي في الفرع: {formatCurrency(res.remainingAmount, currency, language)}
                          </div>
                        </td>

                        {/* Reservation Stage */}
                        <td className="py-3 px-3.5 whitespace-nowrap">
                          <span className={`px-2.5 py-1 rounded-full text-[11px] font-bold inline-block ${
                            res.reservationStatus === 'confirmed' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' :
                            res.reservationStatus === 'in_preparation' ? 'bg-blue-50 text-blue-800 border border-blue-200' :
                            res.reservationStatus === 'ready_for_delivery' ? 'bg-indigo-50 text-indigo-800 border border-indigo-200' :
                            res.reservationStatus === 'completed' ? 'bg-stone-900 text-white' :
                            res.reservationStatus === 'cancelled' ? 'bg-red-50 text-red-700' :
                            'bg-amber-50 text-amber-800 border border-amber-200'
                          }`}>
                            {res.reservationStatus === 'confirmed' ? t.filterConfirmed :
                             res.reservationStatus === 'in_preparation' ? t.filterPrep :
                             res.reservationStatus === 'ready_for_delivery' ? t.filterShipping :
                             res.reservationStatus === 'completed' ? t.filterCompleted :
                             res.reservationStatus === 'cancelled' ? t.filterCancelled : t.filterPending}
                          </span>
                        </td>

                        {/* Actions */}
                        <td className="py-3 px-3.5 text-center whitespace-nowrap">
                          <div className="flex items-center justify-center gap-1.5">
                            {isPending && (
                              <button
                                onClick={() => handleConfirmBooking(res.id)}
                                className="p-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-colors"
                                title={t.confirmBooking}
                              >
                                <Check className="w-3.5 h-3.5" />
                              </button>
                            )}

                            <button
                              onClick={() => {
                                setInspectReservation(res);
                                setEditNotes(res.merchantNotes || '');
                              }}
                              className="p-1.5 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-700 transition-colors"
                              title={t.viewReservation}
                            >
                              <Eye className="w-3.5 h-3.5" />
                            </button>

                            <a
                              href={getWhatsAppLink(
                                res.customerPhone,
                                `مرحباً أستاذ ${res.customerName}، بخصوص حجزك (${res.code}) للمنتج ${res.productNameAr} من متجر ${merchantSettings.storeNameAr}: `
                              )}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="p-1.5 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-colors"
                              title={t.directWhatsAppCustomer}
                            >
                              <MessageCircle className="w-3.5 h-3.5" />
                            </a>

                            <button
                              onClick={() => handleDeleteReservation(res.id)}
                              className={`p-1.5 rounded-lg flex items-center gap-1 text-xs font-bold transition-colors ${pendingDeleteResId === res.id ? 'bg-red-600 text-white' : 'bg-red-50 hover:bg-red-100 text-red-600'}`}
                              title="حذف الطلب"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                              {pendingDeleteResId === res.id && <span>متأكد؟</span>}
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* INSPECT RESERVATION SLIDE-OVER MODAL */}
          {inspectReservation && (
            <div className="sheet-overlay" onClick={() => setInspectReservation(null)}>
              <div 
                className="sheet-panel sm:max-w-2xl"
                onClick={(e) => e.stopPropagation()}
              >
                <div className="flex items-center justify-between p-4 sm:p-5 border-b border-stone-100 bg-stone-50/70">
                  <div className="flex items-center gap-2">
                    <span className="font-mono font-black text-base text-stone-900 bg-stone-200 px-2.5 py-1 rounded-lg">
                      {inspectReservation.code}
                    </span>
                    <span className="text-xs text-stone-500 font-medium">
                      تفاصيل الحجز الكاملة
                    </span>
                  </div>
                  <button
                    onClick={() => setInspectReservation(null)}
                    className="p-2 rounded-xl text-stone-400 hover:text-stone-700 hover:bg-stone-200/50"
                  >
                    <X className="w-5 h-5" />
                  </button>
                </div>

                <div className="overflow-y-auto p-4 sm:p-6 space-y-5 text-xs">
                  
                  {/* Status update controls */}
                  <div className="p-4 rounded-2xl bg-stone-50 border border-stone-200 space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-bold text-stone-800">
                        تحديث مرحلة الحجز الحالية:
                      </span>
                      <select
                        value={inspectReservation.reservationStatus}
                        onChange={(e) => handleUpdateStatus(inspectReservation.id, e.target.value as ReservationStatus)}
                        className="py-1.5 px-3 rounded-xl border border-stone-300 font-bold bg-white text-stone-900"
                      >
                        <option value="pending">المقدم قيد المراجعة (Pending)</option>
                        <option value="confirmed">المقدم وصل — حجز مؤكد (Confirmed)</option>
                        <option value="in_preparation">قيد التجهيز (In Preparation)</option>
                        <option value="ready_for_delivery">جاهز للاستلام من الفرع (Ready)</option>
                        <option value="completed">استلم من الفرع ودفع الباقي (Completed)</option>
                        <option value="cancelled">ملغي (Cancelled)</option>
                      </select>
                    </div>

                    {inspectReservation.paymentStatus === 'pending_verification' && inspectReservation.reservationStatus !== 'cancelled' && (
                      <div className="pt-2 border-t border-stone-200 flex items-center justify-between gap-2">
                        <span className="text-amber-800 font-bold">
                          مقدم قيد المراجعة: {formatCurrency(inspectReservation.depositAmount, currency, language)} — اتأكد إن التحويل وصل.
                        </span>
                        <button
                          onClick={() => handleConfirmBooking(inspectReservation.id)}
                          className="px-4 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold flex items-center gap-1.5 shadow shrink-0"
                        >
                          <Check className="w-3.5 h-3.5" />
                          <span>{t.confirmBooking}</span>
                        </button>
                      </div>
                    )}
                  </div>

                  {/* Customer Info & Financial breakdown */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div className="p-3.5 rounded-2xl bg-stone-50 border border-stone-200 space-y-1.5">
                      <h4 className="font-bold text-stone-900 border-b border-stone-200 pb-1 mb-2">بيانات العميل</h4>
                      <div><strong>الاسم:</strong> {inspectReservation.customerName}</div>
                      <div><strong>الهاتف:</strong> <span className="dir-ltr">{inspectReservation.customerPhone}</span></div>
                      {inspectReservation.customerNotes && (
                        <div className="text-stone-500 pt-1 border-t border-stone-200">
                          <strong>ملاحظات العميل:</strong> {inspectReservation.customerNotes}
                        </div>
                      )}
                    </div>

                    <div className="p-3.5 rounded-2xl bg-amber-50/70 border border-amber-200 space-y-1.5">
                      <h4 className="font-bold text-stone-900 border-b border-amber-200 pb-1 mb-2">البيان المالي</h4>
                      <div className="flex justify-between">
                        <span>المنتج:</span>
                        <span className="font-bold">{inspectReservation.productNameAr}</span>
                      </div>
                      <div className="flex justify-between">
                        <span>الكمية:</span>
                        <span className="font-bold">قطعة واحدة</span>
                      </div>
                      <div className="flex justify-between">
                        <span>السعر الإجمالي:</span>
                        <span className="font-bold">{formatCurrency(inspectReservation.totalPrice, currency, language)}</span>
                      </div>
                      <div className="flex justify-between text-emerald-800 font-black">
                        <span>المقدم ({inspectReservation.paymentMethod === 'instapay' ? 'إنستاباي' : 'فودافون كاش'}):</span>
                        <span>{formatCurrency(inspectReservation.depositAmount, currency, language)}</span>
                      </div>
                      <div className="flex justify-between text-stone-800 font-bold">
                        <span>الباقي في الفرع:</span>
                        <span>{formatCurrency(inspectReservation.remainingAmount, currency, language)}</span>
                      </div>
                      {inspectReservation.paymentReferenceNumber && (
                        <div className="pt-1 text-[11px] text-stone-600 border-t border-amber-200">
                          رقم العملية: <strong className="font-mono">{inspectReservation.paymentReferenceNumber}</strong>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* Transfer proof */}
                  <div className="p-3.5 rounded-2xl bg-stone-50 border border-stone-200">
                    <h4 className="font-bold text-stone-900 mb-2">{t.proofImage}</h4>
                    {inspectReservation.paymentProofUrl ? (
                      <a href={inspectReservation.paymentProofUrl} target="_blank" rel="noopener noreferrer" className="block rounded-xl overflow-hidden border border-stone-200 max-h-64 bg-stone-900 flex items-center justify-center">
                        <img src={inspectReservation.paymentProofUrl} alt="Transfer proof" className="max-h-64 object-contain" />
                      </a>
                    ) : (
                      <div className="p-4 text-center text-stone-500 bg-white rounded-xl border border-stone-200 space-y-2">
                        <div>
                          {inspectReservation.proofSentOnWhatsApp
                            ? t.noProofAttached
                            : 'العميل ماارفعش صورة — اتأكد برقم العملية أو اطلب الصورة على واتساب.'}
                        </div>
                        <a
                          href={getWhatsAppLink(inspectReservation.customerPhone, `مرحباً ${inspectReservation.customerName}، بخصوص حجز ${inspectReservation.code}: ممكن صورة تحويل المقدم؟`)}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-bold"
                        >
                          <MessageCircle className="w-3.5 h-3.5" />
                          <span>اطلب الصورة من العميل</span>
                        </a>
                      </div>
                    )}
                  </div>

                  {/* Internal Merchant Notes */}
                  <div className="space-y-2">
                    <label className="block font-bold text-stone-800">{t.merchantNotesLabel}</label>
                    <textarea
                      rows={2}
                      value={editNotes}
                      onChange={(e) => setEditNotes(e.target.value)}
                      placeholder="أدخل ملاحظات داخلية (موعد الاستلام، ملاحظات التحويل، تأكيد خاص)..."
                      className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs"
                    />
                    <button
                      onClick={() => handleSaveMerchantNote(inspectReservation.id)}
                      className="px-4 py-1.5 rounded-xl bg-stone-900 text-white font-bold text-xs hover:bg-stone-800 flex items-center gap-1.5"
                    >
                      <Save className="w-3.5 h-3.5" />
                      <span>{t.saveNotes}</span>
                    </button>
                  </div>

                </div>

                <div className="p-4 border-t border-stone-200 bg-stone-50/60 flex items-center justify-between">
                  <a
                    href={getWhatsAppLink(inspectReservation.customerPhone, `مرحباً ${inspectReservation.customerName} بخصوص طلب الحجز ${inspectReservation.code}`)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs flex items-center gap-2"
                  >
                    <MessageCircle className="w-4 h-4" />
                    <span>مراسلة واتساب</span>
                  </a>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => handleDeleteReservation(inspectReservation.id)}
                      className={`px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 ${pendingDeleteResId === inspectReservation.id ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100'}`}
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                      <span>{pendingDeleteResId === inspectReservation.id ? 'متأكد؟ دوس تاني' : 'حذف الطلب'}</span>
                    </button>
                    <button
                      onClick={() => setInspectReservation(null)}
                      className="px-4 py-2 rounded-xl border border-stone-300 text-stone-700 text-xs font-bold"
                    >
                      إغلاق
                    </button>
                  </div>
                </div>
              </div>
            </div>
          )}

        </div>
      )}

      {/* TAB 2: INVENTORY & PRICES MANAGEMENT */}
      {activeTab === 'inventory' && (
        <div className="bg-white rounded-3xl border border-stone-200 shadow-sm p-5 sm:p-6 space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-black text-stone-900">
                {t.tabInventory}
              </h2>
              <p className="text-xs text-stone-500">
                ضيف منتجات جديدة، وحدد السعر والمقدم والكمية المتاحة وعداد وقت الحجز لكل منتج. كل حجز بيخصم قطعة واحدة.
              </p>
            </div>
            <button
              onClick={() => {
                setProductToEdit(null);
                setIsProductModalOpen(true);
              }}
              className="py-2.5 px-4 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 font-bold text-xs flex items-center justify-center gap-2 shadow"
            >
              <Plus className="w-4 h-4" />
              <span>{t.addProductBtn}</span>
            </button>
          </div>

          <div className="overflow-x-auto rounded-2xl border border-stone-200">
            <table className="w-full text-xs text-right rtl:text-right ltr:text-left">
              <thead className="bg-stone-50 border-b border-stone-200 text-stone-600 font-bold">
                <tr>
                  <th className="py-3 px-3.5">{t.inventoryColName}</th>
                  <th className="py-3 px-3.5">{t.inventoryColPrice}</th>
                  <th className="py-3 px-3.5">{t.inventoryColDeposit}</th>
                  <th className="py-3 px-3.5">{t.inventoryColQuota}</th>
                  <th className="py-3 px-3.5">⏱️ وقت الحجز</th>
                  <th className="py-3 px-3.5">{t.inventoryColArrival}</th>
                  <th className="py-3 px-3.5">{t.inventoryColStatus}</th>
                  <th className="py-3 px-3.5 text-center">{t.colActions}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {products.map((prod) => {
                  const isQuickEditing = quickEditId === prod.id;
                  return (
                    <tr key={prod.id} className="hover:bg-stone-50/70 transition-colors">
                      {/* Product Name & Image */}
                      <td className="py-3 px-3.5">
                        <div className="flex items-center gap-3">
                          <img
                            src={prod.images[0]}
                            alt={prod.nameAr}
                            className="w-10 h-10 rounded-xl object-cover border border-stone-200"
                          />
                          <div>
                            <span className="font-bold text-stone-900 block line-clamp-1 max-w-[200px]">
                              {language === 'ar' ? prod.nameAr : prod.nameEn}
                            </span>
                            <span className="text-[10px] text-stone-400">
                              {prod.category}
                            </span>
                          </div>
                        </div>
                      </td>

                      {/* Total Price */}
                      <td className="py-3 px-3.5 whitespace-nowrap">
                        {isQuickEditing ? (
                          <input
                            type="number"
                            value={quickPrice}
                            onChange={(e) => setQuickPrice(Number(e.target.value))}
                            className="w-24 px-2 py-1 rounded border border-stone-300 font-bold text-xs"
                          />
                        ) : (
                          <span className="font-bold text-stone-900">
                            {formatCurrency(prod.price, currency, language)}
                          </span>
                        )}
                      </td>

                      {/* Deposit */}
                      <td className="py-3 px-3.5 whitespace-nowrap">
                        {isQuickEditing ? (
                          <input
                            type="number"
                            value={quickDeposit}
                            onChange={(e) => setQuickDeposit(Number(e.target.value))}
                            className="w-24 px-2 py-1 rounded border border-emerald-400 font-bold text-xs text-emerald-800"
                          />
                        ) : (
                          <div>
                            <span className="font-extrabold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                              {formatCurrency(prod.depositAmount, currency, language)}
                            </span>
                            <span className="text-[10px] text-stone-500 block mt-0.5">
                              الباقي: {formatCurrency(Math.max(0, prod.price - prod.depositAmount), currency, language)}
                            </span>
                          </div>
                        )}
                      </td>

                      {/* Quota & Reserved */}
                      <td className="py-3 px-3.5 whitespace-nowrap">
                        {isQuickEditing ? (
                          <input
                            type="number"
                            value={quickQuota}
                            onChange={(e) => setQuickQuota(Number(e.target.value))}
                            className="w-20 px-2 py-1 rounded border border-stone-300 font-bold text-xs"
                          />
                        ) : (
                          <div>
                            <span className="font-bold text-stone-800">{prod.reservedCount} / {prod.reservationQuota}</span>
                            <span className="text-[10px] text-stone-500 block">
                              متبقي: {Math.max(0, prod.reservationQuota - prod.reservedCount)} قطعة
                            </span>
                          </div>
                        )}
                      </td>

                      {/* Booking countdown */}
                      <td className="py-3 px-3.5 whitespace-nowrap">
                        {(() => {
                          const d = describeWindow(prod);
                          return (
                            <button
                              onClick={() => { setProductToEdit(prod); setIsProductModalOpen(true); }}
                              className={`px-2 py-1 rounded-lg text-[11px] font-bold ${d.cls} hover:opacity-80`}
                              title="تعديل عداد الوقت"
                            >
                              {d.text}
                            </button>
                          );
                        })()}
                      </td>

                      {/* Arrival date */}
                      <td className="py-3 px-3.5 whitespace-nowrap text-stone-600">
                        {prod.estimatedArrivalAr}
                      </td>

                      {/* Status */}
                      <td className="py-3 px-3.5 whitespace-nowrap">
                        <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                          prod.status === 'active' ? 'bg-emerald-100 text-emerald-800' :
                          prod.status === 'limited' ? 'bg-red-100 text-red-800' :
                          prod.status === 'closed' ? 'bg-stone-200 text-stone-700' : 'bg-blue-100 text-blue-800'
                        }`}>
                          {prod.status === 'active' ? 'متاح' : prod.status === 'limited' ? 'محدود' : prod.status === 'closed' ? 'مغلق' : 'قريباً'}
                        </span>
                      </td>

                      {/* Actions */}
                      <td className="py-3 px-3.5 text-center whitespace-nowrap">
                        <div className="flex items-center justify-center gap-1.5">
                          {isQuickEditing ? (
                            <button
                              onClick={() => handleSaveQuickEdit(prod.id)}
                              className="px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-bold text-xs hover:bg-emerald-700"
                            >
                              حفظ
                            </button>
                          ) : (
                            <button
                              onClick={() => {
                                setQuickEditId(prod.id);
                                setQuickPrice(prod.price);
                                setQuickDeposit(prod.depositAmount);
                                setQuickQuota(prod.reservationQuota);
                              }}
                              className="p-1.5 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-700"
                              title="تعديل سريع للسعر والمقدم والكوتا"
                            >
                              <DollarSign className="w-3.5 h-3.5" />
                            </button>
                          )}

                          <button
                            onClick={() => {
                              setProductToEdit(prod);
                              setIsProductModalOpen(true);
                            }}
                            className="p-1.5 rounded-lg bg-stone-100 hover:bg-stone-200 text-stone-700"
                            title="تعديل كامل للبيانات"
                          >
                            <Edit3 className="w-3.5 h-3.5" />
                          </button>

                          <button
                            onClick={() => handleDeleteProduct(prod.id)}
                            className={`p-1.5 rounded-lg flex items-center gap-1 text-xs font-bold ${pendingDeleteId === prod.id ? 'bg-red-600 text-white' : 'bg-red-50 hover:bg-red-100 text-red-600'}`}
                            title="حذف المنتج"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                            {pendingDeleteId === prod.id && <span>متأكد؟ دوس تاني</span>}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* TAB 3: ANALYTICS & SALES PERFORMANCE */}
      {activeTab === 'analytics' && (
        <div className="space-y-6">
          <div className="bg-white rounded-3xl border border-stone-200 p-6 shadow-sm">
            <h2 className="text-lg font-black text-stone-900 mb-1">{t.analyticsTitle}</h2>
            <p className="text-xs text-stone-500 mb-6">{t.analyticsSubtitle}</p>

            {/* Financial Visual Split */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
              <div className="p-5 rounded-2xl bg-stone-50 border border-stone-200 space-y-4">
                <h3 className="font-bold text-sm text-stone-900">{t.revenueBreakdown}</h3>
                
                <div className="space-y-3">
                  <div>
                    <div className="flex justify-between text-xs mb-1">
                      <span className="text-stone-600">{t.confirmedValue}:</span>
                      <strong className="text-emerald-700">{formatCurrency(collectedDeposits, currency, language)}</strong>
                    </div>
                    <div className="w-full h-3 bg-stone-200 rounded-full overflow-hidden">
                      <div 
                        className="h-full bg-emerald-600"
                        style={{ 
                          width: `${totalExpectedRevenue > 0 ? (collectedDeposits / totalExpectedRevenue) * 100 : 0}%` 
                        }}
                      />
                    </div>
                  </div>

                  <div>
                    <div className="flex justify-between text-xs mb-1">
                      <span className="text-stone-600">{t.pendingValue}:</span>
                      <strong className="text-stone-900">{formatCurrency(remainingAtBranch, currency, language)}</strong>
                    </div>
                    <div className="w-full h-3 bg-stone-200 rounded-full overflow-hidden">
                      <div 
                        className="h-full bg-amber-500"
                        style={{ 
                          width: `${totalExpectedRevenue > 0 ? (remainingAtBranch / totalExpectedRevenue) * 100 : 0}%` 
                        }}
                      />
                    </div>
                  </div>
                </div>

                <div className="p-3 rounded-xl bg-white border border-stone-200 text-xs text-stone-600">
                  💡 المقدم بيضمن جدية العميل، والباقي بيتحصّل في الفرع وقت الاستلام. راجع التحويلات المعلقة بسرعة.
                </div>
              </div>

              {/* Status Distribution */}
              <div className="p-5 rounded-2xl bg-stone-50 border border-stone-200 space-y-3">
                <h3 className="font-bold text-sm text-stone-900">توزيع حالات الحجز الحالية</h3>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div className="p-3 rounded-xl bg-white border border-stone-200">
                    <span className="text-stone-500 block">المقدم قيد المراجعة</span>
                    <strong className="text-lg text-amber-600">
                      {reservations.filter(r => r.reservationStatus === 'pending').length}
                    </strong>
                  </div>
                  <div className="p-3 rounded-xl bg-white border border-stone-200">
                    <span className="text-stone-500 block">حجوزات مؤكدة</span>
                    <strong className="text-lg text-emerald-600">
                      {reservations.filter(r => r.reservationStatus === 'confirmed').length}
                    </strong>
                  </div>
                  <div className="p-3 rounded-xl bg-white border border-stone-200">
                    <span className="text-stone-500 block">قيد التجهيز الفعلي</span>
                    <strong className="text-lg text-blue-600">
                      {reservations.filter(r => r.reservationStatus === 'in_preparation').length}
                    </strong>
                  </div>
                  <div className="p-3 rounded-xl bg-white border border-stone-200">
                    <span className="text-stone-500 block">شحنات مكتملة</span>
                    <strong className="text-lg text-stone-900">
                      {reservations.filter(r => r.reservationStatus === 'completed').length}
                    </strong>
                  </div>
                </div>
              </div>
            </div>

            {/* Top Reserved Products Quota Occupancy */}
            <div className="mt-6 pt-6 border-t border-stone-200">
              <h3 className="font-bold text-sm text-stone-900 mb-4">{t.topProductsTitle}</h3>
              <div className="space-y-3">
                {products.map((p) => {
                  const percent = Math.min(100, Math.round((p.reservedCount / p.reservationQuota) * 100));
                  return (
                    <div key={p.id} className="p-3.5 rounded-2xl bg-stone-50 border border-stone-200/80">
                      <div className="flex items-center justify-between text-xs mb-1.5">
                        <span className="font-bold text-stone-900">{language === 'ar' ? p.nameAr : p.nameEn}</span>
                        <span className="text-stone-600">
                          {p.reservedCount} / {p.reservationQuota} محجوز ({percent}%)
                        </span>
                      </div>
                      <div className="w-full h-2.5 bg-stone-200 rounded-full overflow-hidden">
                        <div 
                          className={`h-full ${percent >= 90 ? 'bg-red-500' : 'bg-emerald-600'}`}
                          style={{ width: `${percent}%` }}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

          </div>
        </div>
      )}

      {/* TAB 4: SETTINGS & PAYMENT DESTINATIONS */}
      {activeTab === 'settings' && (
        <div className="bg-white rounded-3xl border border-stone-200 p-4 sm:p-6 shadow-sm max-w-3xl">
          <div className="mb-6">
            <h2 className="text-lg font-black text-stone-900">{t.settingsTitle}</h2>
            <p className="text-xs text-stone-500 mt-1">{t.settingsDesc}</p>
          </div>

          {settingsSavedToast && (
            <div className="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4" />
              <span>{t.settingsSavedNotice}</span>
            </div>
          )}

          <form onSubmit={handleSaveSettings} className="space-y-4 text-xs">
            {/* Announcement bar */}
            <div className="p-4 rounded-2xl bg-amber-50 border border-amber-300 space-y-3">
              <label className="flex items-center justify-between gap-3 cursor-pointer">
                <div>
                  <span className="block font-bold text-stone-900 text-sm">📢 شريط الإعلان فوق الموقع</span>
                  <span className="block text-[11px] text-stone-600">بيظهر ثابت فوق الهيدر في كل صفحات الموقع. اكتب فيه أي حاجة: عرض، خصم، مواعيد الفرع...</span>
                </div>
                <input
                  type="checkbox"
                  checked={settingsForm.announcementEnabled}
                  onChange={(e) => setSettingsForm({ ...settingsForm, announcementEnabled: e.target.checked })}
                  className="w-5 h-5 accent-stone-900 shrink-0"
                />
              </label>
              <div>
                <label htmlFor="announcement-ar" className="block font-bold text-stone-800 mb-1">نص الإعلان (عربي)</label>
                <textarea
                  id="announcement-ar"
                  rows={2}
                  value={settingsForm.announcementAr}
                  onChange={(e) => setSettingsForm({ ...settingsForm, announcementAr: e.target.value })}
                  placeholder="مثال: خصم ١٠٪ على كل الحجوزات لحد يوم الجمعة 🔥"
                  className="w-full px-3 py-2 rounded-xl border border-amber-300 bg-white text-xs"
                />
              </div>
              <div>
                <label htmlFor="announcement-en" className="block font-bold text-stone-800 mb-1">نص الإعلان (إنجليزي — اختياري)</label>
                <input
                  id="announcement-en"
                  type="text"
                  value={settingsForm.announcementEn}
                  onChange={(e) => setSettingsForm({ ...settingsForm, announcementEn: e.target.value })}
                  placeholder="لو فاضي هيظهر النص العربي"
                  className="w-full px-3 py-2 rounded-xl border border-amber-300 bg-white text-xs dir-ltr text-left"
                />
              </div>
              {settingsForm.announcementEnabled && settingsForm.announcementAr.trim() && (
                <div>
                  <span className="block text-[11px] text-stone-500 mb-1">معاينة:</span>
                  <div className="bg-amber-400 text-stone-950 px-4 py-2 rounded-lg text-center text-xs sm:text-sm font-extrabold">
                    {settingsForm.announcementAr}
                  </div>
                </div>
              )}
            </div>

            
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block font-bold text-stone-700 mb-1">اسم المتجر (بالعربية)</label>
                <input
                  type="text"
                  value={settingsForm.storeNameAr}
                  onChange={(e) => setSettingsForm({ ...settingsForm, storeNameAr: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs sm:text-sm font-bold"
                />
              </div>
              <div>
                <label className="block font-bold text-stone-700 mb-1">Store Name (English)</label>
                <input
                  type="text"
                  value={settingsForm.storeNameEn}
                  onChange={(e) => setSettingsForm({ ...settingsForm, storeNameEn: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs sm:text-sm"
                />
              </div>
            </div>

            {/* Deposit receiving numbers */}
            <div className="p-4 rounded-2xl bg-amber-50/70 border border-amber-200 space-y-3">
              <h3 className="font-bold text-amber-950 text-sm">أرقام استقبال المقدم (مهم جداً)</h3>
              <div>
                <label className="block font-bold text-stone-800 mb-1">
                  {t.vfNumberLabel} * <span className="font-normal text-stone-500">(رقم في كل سطر)</span>
                </label>
                <textarea
                  rows={3}
                  value={vfNumbersText}
                  onChange={(e) => {
                    setVfNumbersText(e.target.value);
                    setSettingsForm({
                      ...settingsForm,
                      vodafoneCashNumbers: e.target.value.split(/[\n,]+/).map(n => n.trim()).filter(Boolean),
                    });
                  }}
                  placeholder={'010xxxxxxxx\n010xxxxxxxx'}
                  className="w-full px-3 py-2 rounded-xl border border-amber-300 bg-white font-mono text-sm font-bold dir-ltr text-left"
                  required
                />
              </div>
              <div>
                <label className="block font-bold text-stone-800 mb-1">{t.instapayLabel} *</label>
                <input
                  type="text"
                  value={settingsForm.instapayAddress}
                  onChange={(e) => setSettingsForm({ ...settingsForm, instapayAddress: e.target.value })}
                  placeholder="01xxxxxxxxx أو name@instapay"
                  className="w-full px-3 py-2 rounded-xl border border-amber-300 bg-white font-mono text-sm font-bold dir-ltr text-left"
                  required
                />
              </div>
              <div>
                <label className="block font-bold text-stone-800 mb-1">{t.whatsappNumberLabel} *</label>
                <input
                  type="text"
                  value={settingsForm.whatsappNumber}
                  onChange={(e) => setSettingsForm({ ...settingsForm, whatsappNumber: e.target.value })}
                  placeholder="01xxxxxxxxx"
                  className="w-full px-3 py-2 rounded-xl border border-amber-300 bg-white font-mono text-sm font-bold dir-ltr text-left"
                  required
                />
                <span className="text-[10px] text-stone-500">الرقم ده بيستقبل صور التحويل، وعليه زرار الواتساب اللي في كل الصفحات.</span>
              </div>
            </div>

            {/* Branches */}
            <div className="p-4 rounded-2xl bg-stone-50 border border-stone-200 space-y-3">
              <div>
                <h3 className="font-bold text-stone-900 text-sm">📍 عناوين الفروع</h3>
                <p className="text-[11px] text-stone-500">بتظهر في صفحة تفاصيل كل منتج وفي وصل الحجز.</p>
              </div>
              <div>
                <label htmlFor="branches-title" className="block font-bold text-stone-700 mb-1">العنوان اللي فوق الفروع</label>
                <input id="branches-title" type="text" value={settingsForm.branchesTitleAr}
                  onChange={(e) => setSettingsForm({ ...settingsForm, branchesTitleAr: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white text-xs" />
              </div>
              {settingsForm.branches.map((b, i) => (
                <div key={i} className="p-3 rounded-xl bg-white border border-stone-200 space-y-2">
                  <div className="flex items-center gap-2">
                    <input
                      id={`branch-name-${i}`}
                      type="text"
                      value={b.name}
                      onChange={(e) => setSettingsForm({ ...settingsForm, branches: settingsForm.branches.map((x, j) => j === i ? { ...x, name: e.target.value } : x) })}
                      placeholder="اسم الفرع (مثال: فرع الكاجوال)"
                      className="flex-1 min-w-0 px-3 py-2 rounded-lg border border-stone-300 text-xs font-bold"
                    />
                    <button
                      type="button"
                      onClick={() => setSettingsForm({ ...settingsForm, branches: settingsForm.branches.filter((_, j) => j !== i) })}
                      className="p-2 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 shrink-0"
                      title="حذف الفرع"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                  <textarea
                    id={`branch-address-${i}`}
                    rows={2}
                    value={b.address}
                    onChange={(e) => setSettingsForm({ ...settingsForm, branches: settingsForm.branches.map((x, j) => j === i ? { ...x, address: e.target.value } : x) })}
                    placeholder="العنوان بالتفصيل"
                    className="w-full px-3 py-2 rounded-lg border border-stone-300 text-xs"
                  />
                </div>
              ))}
              <button
                type="button"
                onClick={() => setSettingsForm({ ...settingsForm, branches: [...settingsForm.branches, { name: '', address: '' }] })}
                className="w-full py-2 rounded-xl border-2 border-dashed border-stone-300 text-stone-600 hover:border-stone-500 hover:text-stone-900 font-bold text-xs flex items-center justify-center gap-1.5"
              >
                <Plus className="w-3.5 h-3.5" />
                <span>إضافة فرع</span>
              </button>
              <div>
                <label htmlFor="branch-phones" className="block font-bold text-stone-700 mb-1">أرقام التليفون (رقم في كل سطر)</label>
                <textarea
                  id="branch-phones"
                  rows={3}
                  value={branchPhonesText}
                  onChange={(e) => {
                    setBranchPhonesText(e.target.value);
                    setSettingsForm({ ...settingsForm, branchPhones: e.target.value.split(/[\n,]+/).map(n => n.trim()).filter(Boolean) });
                  }}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white font-mono text-xs dir-ltr text-left"
                />
              </div>
            </div>

            <div>
              <label className="block font-bold text-stone-700 mb-1">
                {t.instructionsLabel}
              </label>
              <textarea
                rows={2}
                value={settingsForm.depositInstructionsAr}
                onChange={(e) => setSettingsForm({ ...settingsForm, depositInstructionsAr: e.target.value })}
                className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs"
              />
            </div>

            {/* Sound alert toggle */}
            <div className="flex items-center justify-between p-3.5 rounded-2xl bg-stone-50 border border-stone-200">
              <div className="flex items-center gap-2">
                <Volume2 className="w-4 h-4 text-emerald-600" />
                <span className="font-bold text-stone-800">{t.soundToggleLabel}</span>
              </div>
              <input
                type="checkbox"
                checked={settingsForm.soundAlertEnabled}
                onChange={(e) => setSettingsForm({ ...settingsForm, soundAlertEnabled: e.target.checked })}
                className="w-4 h-4 accent-stone-900 rounded"
              />
            </div>

            <div className="pt-4 border-t border-stone-200 flex justify-end">
              <button
                type="submit"
                className="py-2.5 px-6 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 font-bold text-xs flex items-center gap-2 shadow"
              >
                <Save className="w-4 h-4" />
                <span>{t.saveSettingsBtn}</span>
              </button>
            </div>

          </form>

          {/* Login account */}
          <form onSubmit={handleSaveCredentials} className="mt-6 p-4 rounded-2xl bg-stone-50 border border-stone-200 space-y-3 text-xs">
            <div>
              <h3 className="font-bold text-stone-900 text-sm">🔒 بيانات دخول لوحة التحكم</h3>
              <p className="text-[11px] text-stone-500 mt-0.5">
                بوابة الدخول: رابط الموقع وفي آخره <span className="font-mono font-bold dir-ltr">/admin</span> أو <span className="font-mono font-bold dir-ltr">#admin</span>. لازم تكتب الباسورد الحالي عشان تغيّر أي حاجة.
              </p>
            </div>
            {credMessage && (
              <div className={`p-2.5 rounded-xl font-bold ${credMessage.ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-700'}`}>
                {credMessage.text}
              </div>
            )}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label htmlFor="cred-username" className="block font-bold text-stone-700 mb-1">اسم المستخدم</label>
                <input id="cred-username" type="text" autoComplete="username" value={credUsername}
                  onChange={(e) => { setCredUsername(e.target.value); setCredMessage(null); }}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white dir-ltr text-left" />
              </div>
              <div>
                <label htmlFor="cred-current" className="block font-bold text-stone-700 mb-1">الباسورد الحالي *</label>
                <input id="cred-current" type="password" autoComplete="current-password" value={credCurrent}
                  onChange={(e) => { setCredCurrent(e.target.value); setCredMessage(null); }}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white dir-ltr text-left" />
              </div>
              <div>
                <label htmlFor="cred-new" className="block font-bold text-stone-700 mb-1">الباسورد الجديد</label>
                <input id="cred-new" type="password" autoComplete="new-password" value={credNew}
                  onChange={(e) => { setCredNew(e.target.value); setCredMessage(null); }}
                  placeholder="سيبه فاضي لو مش هتغيّره"
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white dir-ltr text-left" />
              </div>
              <div>
                <label htmlFor="cred-confirm" className="block font-bold text-stone-700 mb-1">تأكيد الباسورد الجديد</label>
                <input id="cred-confirm" type="password" autoComplete="new-password" value={credConfirm}
                  onChange={(e) => { setCredConfirm(e.target.value); setCredMessage(null); }}
                  className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white dir-ltr text-left" />
              </div>
            </div>
            <div className="flex justify-end">
              <button type="submit" disabled={credSaving} className="py-2.5 px-5 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 font-bold text-xs disabled:opacity-60">
                {credSaving ? 'جاري الحفظ...' : 'حفظ بيانات الدخول'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Modal for adding/editing product */}
      <NewProductModal
        isOpen={isProductModalOpen}
        onClose={() => {
          setIsProductModalOpen(false);
          setProductToEdit(null);
        }}
        onSave={handleSaveProduct}
        productToEdit={productToEdit}
        language={language}
        currency={currency}
      />

    </div>
  );
};
