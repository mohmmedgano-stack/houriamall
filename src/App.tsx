import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  Product,
  Reservation,
  MerchantSettings,
  NotificationItem,
  Language,
} from './types';
import { INITIAL_MERCHANT_SETTINGS } from './data/initialData';
import * as api from './utils/api';
import { playNewReservationChime } from './utils/audio';
import { Navbar } from './components/Navbar';
import { ProductCard } from './components/ProductCard';
import { ProductDetailModal } from './components/ProductDetailModal';
import { BookingModal } from './components/BookingModal';
import { OrderTrackingView } from './components/OrderTrackingView';
import { MerchantDashboard } from './components/MerchantDashboard';
import { Footer } from './components/Footer';
import { WhatsAppButton } from './components/WhatsAppButton';
import { MobileTabBar } from './components/MobileTabBar';
import { AdminLogin } from './components/AdminLogin';
import { AdminHeader } from './components/AdminHeader';

type PublicView = 'store' | 'track';

const ADMIN_HASH = '#admin';
const ADMIN_POLL_MS = 20000;

// Admin portal opens at <site>/admin or <site>/#admin
const isAdminHash = () =>
  typeof window !== 'undefined' &&
  (window.location.hash.toLowerCase() === ADMIN_HASH || /\/admin\/?$/i.test(window.location.pathname));

// Defaults shown for a split second until the server answers (never includes credentials)
const { adminUsername: _u, adminPassword: _p, ...DEFAULT_PUBLIC_SETTINGS } = INITIAL_MERCHANT_SETTINGS;
const DEFAULT_SETTINGS = { ...DEFAULT_PUBLIC_SETTINGS, adminUsername: '', adminPassword: '' } as MerchantSettings;

const sameJson = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

export default function App() {
  const [language, setLanguage] = useState<Language>('ar');

  // Visitors only ever see 'store' and 'track'. The admin dashboard lives at <site>/admin
  const [currentView, setCurrentView] = useState<PublicView>('store');
  const [isAdminRoute, setIsAdminRoute] = useState<boolean>(isAdminHash());
  const [isAdminAuthed, setIsAdminAuthed] = useState<boolean>(Boolean(api.getAdminToken()));
  const [adminFocusCode, setAdminFocusCode] = useState<string>('');
  const [adminDashboardKey, setAdminDashboardKey] = useState(0);
  const [trackedCode, setTrackedCode] = useState<string>('');

  // Server data
  const [products, setProducts] = useState<Product[]>([]);
  const [reservations, setReservations] = useState<Reservation[]>([]);
  const [merchantSettings, setMerchantSettings] = useState<MerchantSettings>(DEFAULT_SETTINGS);
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [storeLoading, setStoreLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [adminError, setAdminError] = useState('');
  // The dashboard copies settings into its forms on mount, so it only mounts after real data arrived
  const [adminLoaded, setAdminLoaded] = useState(false);
  const [savingMessage, setSavingMessage] = useState('');
  const knownNotifIds = useRef<Set<string> | null>(null);

  // Modals
  const [productForBooking, setProductForBooking] = useState<Product | null>(null);
  const [productForDetail, setProductForDetail] = useState<Product | null>(null);

  // ───────── Loading ─────────

  const loadStore = useCallback(async () => {
    try {
      const data = await api.fetchStore();
      setProducts(data.products);
      setMerchantSettings(s => ({ ...s, ...data.settings }));
      setLoadError('');
    } catch (e: any) {
      setLoadError(e?.message || 'مقدرتش أحمّل المنتجات');
    } finally {
      setStoreLoading(false);
    }
  }, []);

  const loadAdmin = useCallback(async (opts: { silent?: boolean } = {}) => {
    try {
      const data = await api.fetchAdminData();
      setProducts(data.products);
      setReservations(data.reservations);
      setMerchantSettings(data.settings);
      // Chime for bookings that arrived since the last refresh
      const ids = new Set(data.notifications.map(n => n.id));
      if (knownNotifIds.current && data.settings.soundAlertEnabled) {
        const fresh = data.notifications.some(n => !n.isRead && !knownNotifIds.current!.has(n.id));
        if (fresh) playNewReservationChime();
      }
      knownNotifIds.current = ids;
      setNotifications(data.notifications);
      setAdminError('');
      setAdminLoaded(true);
    } catch (e: any) {
      if (e instanceof api.ApiError && e.status === 401) {
        setIsAdminAuthed(false);
        return;
      }
      if (!opts.silent) setAdminError(e?.message || 'مقدرتش أحمّل البيانات');
    }
  }, []);

  useEffect(() => {
    loadStore();
    const urlParams = new URLSearchParams(window.location.search);
    const urlTrack = urlParams.get('track') || urlParams.get('code');
    if (urlTrack) {
      setTrackedCode(urlTrack);
      setCurrentView('track');
    }
    const onHash = () => setIsAdminRoute(isAdminHash());
    window.addEventListener('hashchange', onHash);
    window.addEventListener('popstate', onHash);
    return () => {
      window.removeEventListener('hashchange', onHash);
      window.removeEventListener('popstate', onHash);
    };
  }, [loadStore]);

  // Admin: load everything, then keep refreshing so new bookings show up on their own
  useEffect(() => {
    if (!isAdminRoute || !isAdminAuthed) return;
    loadAdmin();
    const id = setInterval(() => {
      if (document.visibilityState === 'visible') loadAdmin({ silent: true });
    }, ADMIN_POLL_MS);
    return () => clearInterval(id);
  }, [isAdminRoute, isAdminAuthed, loadAdmin]);

  // Back on the public site: refresh products (stock changes)
  useEffect(() => {
    if (!isAdminRoute) loadStore();
  }, [isAdminRoute, loadStore]);

  // The admin dashboard is Arabic RTL; the public site follows the language toggle
  const effectiveLanguage: Language = isAdminRoute ? 'ar' : language;
  useEffect(() => {
    document.documentElement.dir = effectiveLanguage === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = effectiveLanguage;
    document.title = isAdminRoute
      ? `لوحة التحكم | ${merchantSettings.storeNameAr}`
      : `${effectiveLanguage === 'ar' ? merchantSettings.storeNameAr : merchantSettings.storeNameEn}`;
  }, [effectiveLanguage, isAdminRoute, merchantSettings.storeNameAr, merchantSettings.storeNameEn]);

  const currency = effectiveLanguage === 'ar' ? merchantSettings.currencyAr : merchantSettings.currencyEn;
  const unreadNotifCount = notifications.filter(n => !n.isRead).length;

  // ───────── Admin writes (sent to the server, then re-synced) ─────────

  const runAdmin = async (label: string, work: () => Promise<unknown>) => {
    setSavingMessage(label);
    try {
      await work();
      setAdminError('');
    } catch (e: any) {
      setAdminError(e?.message || 'مقدرتش أحفظ التغيير، جرّب تاني');
      if (e instanceof api.ApiError && e.status === 401) setIsAdminAuthed(false);
    } finally {
      setSavingMessage('');
      await loadAdmin({ silent: true });
    }
  };

  const handleUpdateReservations = (next: Reservation[]) => {
    const before = reservations;
    setReservations(next); // optimistic
    const nextIds = new Set(next.map(r => r.id));
    const removed = before.filter(r => !nextIds.has(r.id)).map(r => r.id);
    const changed = next.filter(r => {
      const old = before.find(o => o.id === r.id);
      return old && !sameJson(
        [old.reservationStatus, old.paymentStatus, old.merchantNotes, old.history],
        [r.reservationStatus, r.paymentStatus, r.merchantNotes, r.history]
      );
    });
    runAdmin('جاري الحفظ...', async () => {
      if (removed.length) await api.deleteReservations(removed);
      for (const r of changed) {
        await api.updateReservation(r.id, {
          reservationStatus: r.reservationStatus,
          paymentStatus: r.paymentStatus,
          merchantNotes: r.merchantNotes,
          history: r.history,
        });
      }
    });
  };

  const handleUpdateProducts = (next: Product[]) => {
    const before = products;
    setProducts(next); // optimistic
    const nextIds = new Set(next.map(p => p.id));
    const removed = before.filter(p => !nextIds.has(p.id)).map(p => p.id);
    const strip = ({ reservedCount, ...rest }: Product) => rest;
    const changed = next.filter(p => {
      const old = before.find(o => o.id === p.id);
      return !old || !sameJson(strip(old), strip(p));
    });
    runAdmin(changed.some(p => p.images.some(i => i.startsWith('data:'))) ? 'جاري رفع الصور...' : 'جاري الحفظ...', async () => {
      for (const id of removed) await api.deleteProduct(id);
      for (const p of changed) await api.saveProduct(p);
    });
  };

  const handleUpdateSettings = (next: MerchantSettings) => {
    setMerchantSettings(next);
    runAdmin('جاري حفظ الإعدادات...', () => api.saveSettings(next));
  };

  const handleSaveCredentials = async (username: string, current: string, next: string) => {
    await api.saveCredentials(username, current, next);
    setMerchantSettings(s => ({ ...s, adminUsername: username }));
  };

  const handleDeleteReservations = async (ids: string[]) => {
    setReservations(rs => rs.filter(r => !ids.includes(r.id)));
    await runAdmin('جاري الحذف...', () => api.deleteReservations(ids));
  };

  const handleMarkNotificationRead = (id: string) => {
    setNotifications(ns => ns.map(n => n.id === id ? { ...n, isRead: true } : n));
    api.markNotificationsRead([id]).catch(() => {});
  };

  const handleMarkAllNotificationsRead = () => {
    setNotifications(ns => ns.map(n => ({ ...n, isRead: true })));
    api.markNotificationsRead('all').catch(() => {});
  };

  const handleToggleSound = () => {
    handleUpdateSettings({ ...merchantSettings, soundAlertEnabled: !merchantSettings.soundAlertEnabled });
  };

  // ───────── Navigation ─────────

  const handleNavigate = (view: PublicView, extraCode?: string) => {
    setCurrentView(view);
    if (extraCode) setTrackedCode(extraCode);
    const url = new URL(window.location.href);
    if (view === 'track') {
      const code = extraCode || trackedCode;
      if (code) url.searchParams.set('track', code);
      else url.searchParams.delete('track');
    } else {
      url.searchParams.delete('track');
      url.searchParams.delete('code');
    }
    window.history.replaceState({}, '', url.toString());
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const goToSite = () => {
    const url = new URL(window.location.href);
    url.hash = '';
    url.pathname = url.pathname.replace(/\/admin\/?$/i, '/') || '/';
    window.history.pushState({}, '', url.toString());
    setIsAdminRoute(false);
    setCurrentView('store');
  };

  const handleAdminLogout = async () => {
    await api.adminLogout();
    setIsAdminAuthed(false);
    setReservations([]);
    setNotifications([]);
    knownNotifIds.current = null;
    setAdminLoaded(false);
  };

  // ───────────────────────── PRIVATE ADMIN DASHBOARD (/admin) ─────────────────────────
  if (isAdminRoute) {
    if (!isAdminAuthed) {
      return (
        <AdminLogin
          storeName={merchantSettings.storeNameAr}
          onLogin={async (u, p) => {
            await api.adminLogin(u, p);
            setAdminLoaded(false);
            setIsAdminAuthed(true);
          }}
          onBackToSite={goToSite}
        />
      );
    }
    return (
      <div className="min-h-screen bg-stone-50 text-stone-900">
        <AdminHeader
          merchantSettings={merchantSettings}
          notifications={notifications}
          unreadCount={unreadNotifCount}
          onMarkNotificationRead={handleMarkNotificationRead}
          onMarkAllNotificationsRead={handleMarkAllNotificationsRead}
          onOpenReservation={(code) => {
            setAdminFocusCode(code);
            setAdminDashboardKey(k => k + 1);
          }}
          onToggleSound={handleToggleSound}
          onViewSite={goToSite}
          onLogout={handleAdminLogout}
        />

        {(adminError || savingMessage) && (
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div
              role="status"
              className={`p-3 rounded-xl text-xs font-bold border ${adminError ? 'bg-red-50 border-red-200 text-red-700' : 'bg-amber-50 border-amber-200 text-amber-900'}`}
            >
              {adminError || savingMessage}
            </div>
          </div>
        )}

        {!adminLoaded ? (
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
            {adminError ? (
              <div className="space-y-3">
                <p className="text-sm font-bold text-red-700">{adminError}</p>
                <button onClick={() => loadAdmin()} className="px-4 py-2 rounded-xl bg-stone-900 text-amber-300 text-xs font-bold">حاول تاني</button>
              </div>
            ) : (
              <p className="text-sm text-stone-500 animate-pulse">جاري تحميل البيانات...</p>
            )}
          </div>
        ) : (
        <MerchantDashboard
          key={adminDashboardKey}
          products={products}
          reservations={reservations}
          merchantSettings={merchantSettings}
          language="ar"
          currency={merchantSettings.currencyAr}
          onUpdateReservations={handleUpdateReservations}
          onUpdateProducts={handleUpdateProducts}
          onUpdateSettings={handleUpdateSettings}
          onSaveCredentials={handleSaveCredentials}
          onDeleteReservations={handleDeleteReservations}
          selectedReservationCode={adminFocusCode}
        />
        )}
      </div>
    );
  }

  // ───────────────────────── PUBLIC VISITOR SITE ─────────────────────────
  return (
    <div className="has-mobile-tabbar min-h-screen flex flex-col bg-stone-50 text-stone-900 selection:bg-amber-400 selection:text-stone-900">
      <Navbar
        currentView={currentView}
        onNavigate={handleNavigate}
        language={language}
        onToggleLanguage={() => setLanguage(prev => prev === 'ar' ? 'en' : 'ar')}
        merchantSettings={merchantSettings}
      />

      <main className="flex-1">
        {currentView === 'store' && (
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-12">
            <div className="flex items-center justify-between mb-5 sm:mb-8 gap-3">
              <div>
                <h2 className="text-xl sm:text-3xl font-black text-stone-900 tracking-tight">
                  {language === 'ar' ? 'منتجات الحجز المتاحة' : 'Available Pre-Orders'}
                </h2>
                <p className="text-xs sm:text-sm text-stone-500 mt-1">
                  {language === 'ar'
                    ? 'اختار المنتج وادفع المقدم فودافون كاش أو إنستاباي، وخد الكوبون واستلم من الفرع.'
                    : 'Pick a product, pay the deposit via Vodafone Cash or InstaPay, and pick up at the branch with your coupon.'}
                </p>
              </div>
              {!storeLoading && (
                <div className="text-xs text-stone-500 font-semibold bg-stone-100 px-3 py-1.5 rounded-xl border border-stone-200 shrink-0">
                  {products.length} {language === 'ar' ? 'منتج' : 'products'}
                </div>
              )}
            </div>

            {storeLoading ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-8" aria-busy="true">
                {[0, 1, 2].map(i => (
                  <div key={i} className="rounded-2xl border border-stone-200 bg-white overflow-hidden animate-pulse">
                    <div className="aspect-[16/10] sm:aspect-[4/3] bg-stone-200" />
                    <div className="p-4 space-y-3">
                      <div className="h-4 w-2/3 bg-stone-200 rounded" />
                      <div className="h-3 w-full bg-stone-100 rounded" />
                      <div className="h-10 w-full bg-stone-100 rounded-xl" />
                    </div>
                  </div>
                ))}
              </div>
            ) : loadError ? (
              <div className="text-center py-16 bg-white rounded-3xl border border-red-200 space-y-3">
                <p className="text-red-700 text-sm font-bold">{loadError}</p>
                <button onClick={() => { setStoreLoading(true); loadStore(); }} className="px-4 py-2 rounded-xl bg-stone-900 text-amber-300 text-xs font-bold">
                  {language === 'ar' ? 'حاول تاني' : 'Try again'}
                </button>
              </div>
            ) : products.length === 0 ? (
              <div className="text-center py-16 bg-white rounded-3xl border border-stone-200">
                <p className="text-stone-400 text-sm">
                  {language === 'ar' ? 'مفيش منتجات متاحة للحجز دلوقتي' : 'No products available right now'}
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-8">
                {products.map((product) => (
                  <ProductCard
                    key={product.id}
                    product={product}
                    language={language}
                    currency={currency}
                    onBookNow={(p) => setProductForBooking(p)}
                    onViewDetails={(p) => setProductForDetail(p)}
                  />
                ))}
              </div>
            )}
          </div>
        )}

        {currentView === 'track' && (
          <OrderTrackingView
            initialCode={trackedCode}
            language={language}
            currency={currency}
            merchantSettings={merchantSettings}
            onNavigateToStore={() => handleNavigate('store')}
          />
        )}
      </main>

      {productForDetail && (
        <ProductDetailModal
          key={productForDetail.id}
          product={productForDetail}
          isOpen={true}
          onClose={() => setProductForDetail(null)}
          onBookNow={(p) => setProductForBooking(p)}
          language={language}
          currency={currency}
          merchantSettings={merchantSettings}
        />
      )}

      {productForBooking && (
        <BookingModal
          key={productForBooking.id}
          product={products.find(p => p.id === productForBooking.id) || productForBooking}
          isOpen={true}
          onClose={() => setProductForBooking(null)}
          onReservationCreated={() => loadStore()}
          onNavigateToTrack={(code) => handleNavigate('track', code)}
          language={language}
          currency={currency}
          merchantSettings={merchantSettings}
        />
      )}

      <WhatsAppButton language={language} merchantSettings={merchantSettings} />
      <MobileTabBar currentView={currentView} onNavigate={handleNavigate} language={language} merchantSettings={merchantSettings} />

      <Footer language={language} merchantSettings={merchantSettings} onNavigate={handleNavigate} />
    </div>
  );
}
