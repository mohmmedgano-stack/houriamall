import React, { useState, useEffect } from 'react';
import {
  Product,
  Reservation,
  MerchantSettings,
  NotificationItem,
  Language,
} from './types';
import {
  loadProducts,
  saveProducts,
  loadReservations,
  saveReservations,
  loadMerchantSettings,
  saveMerchantSettings,
  loadNotifications,
  saveNotifications,
} from './utils/storage';
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
const ADMIN_SESSION_KEY = 'hourya_admin_session';

// Admin portal opens at <site>/admin or <site>/#admin
const isAdminHash = () =>
  typeof window !== 'undefined' &&
  (window.location.hash.toLowerCase() === ADMIN_HASH || /\/admin\/?$/i.test(window.location.pathname));

const readAdminSession = () => {
  try { return sessionStorage.getItem(ADMIN_SESSION_KEY) === '1'; } catch { return false; }
};

export default function App() {
  const [language, setLanguage] = useState<Language>('ar');

  // Visitors only ever see 'store' and 'track'. The admin dashboard lives at <site>/#admin
  const [currentView, setCurrentView] = useState<PublicView>('store');
  const [isAdminRoute, setIsAdminRoute] = useState<boolean>(isAdminHash());
  const [isAdminAuthed, setIsAdminAuthed] = useState<boolean>(readAdminSession());
  const [adminFocusCode, setAdminFocusCode] = useState<string>('');
  const [adminDashboardKey, setAdminDashboardKey] = useState(0);

  const [trackedCode, setTrackedCode] = useState<string>('');

  // Persistent States
  const [products, setProducts] = useState<Product[]>([]);
  const [reservations, setReservations] = useState<Reservation[]>([]);
  const [merchantSettings, setMerchantSettings] = useState<MerchantSettings>(loadMerchantSettings());
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);

  // Modals
  const [productForBooking, setProductForBooking] = useState<Product | null>(null);
  const [productForDetail, setProductForDetail] = useState<Product | null>(null);

  useEffect(() => {
    setProducts(loadProducts());
    setReservations(loadReservations());
    setMerchantSettings(loadMerchantSettings());
    setNotifications(loadNotifications());

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
  }, []);

  // Keep the admin view in sync with bookings made in other tabs of the same browser
  useEffect(() => {
    const onStorage = () => {
      setProducts(loadProducts());
      setReservations(loadReservations());
      setNotifications(loadNotifications());
    };
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, []);

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

  const handleReservationCreated = (newReservation: Reservation) => {
    const updatedReservations = [newReservation, ...reservations];
    setReservations(updatedReservations);
    saveReservations(updatedReservations);

    // Each booking takes exactly one piece from the available quantity
    const updatedProducts = products.map(p => {
      if (p.id === newReservation.productId) {
        const newReserved = p.reservedCount + 1;
        return {
          ...p,
          reservedCount: newReserved,
          status: newReserved >= p.reservationQuota ? 'closed' : p.status,
        } as Product;
      }
      return p;
    });
    setProducts(updatedProducts);
    saveProducts(updatedProducts);

    // Notification shown only inside the admin dashboard
    const newNotification: NotificationItem = {
      id: `notif-${Date.now()}`,
      titleAr: 'حجز جديد — مقدم قيد المراجعة',
      titleEn: 'New booking — deposit under review',
      messageAr: `${newReservation.customerName} حجز قطعة من "${newReservation.productNameAr}" ودفع مقدم ${newReservation.depositAmount} ج.م (${newReservation.code}).`,
      messageEn: `${newReservation.customerName} booked one "${newReservation.productNameEn}" (${newReservation.code}).`,
      reservationCode: newReservation.code,
      timestamp: new Date().toISOString(),
      isRead: false,
      type: 'new_booking',
    };
    const updatedNotifications = [newNotification, ...notifications];
    setNotifications(updatedNotifications);
    saveNotifications(updatedNotifications);
  };

  const handleMarkNotificationRead = (id: string) => {
    const updated = notifications.map(n => n.id === id ? { ...n, isRead: true } : n);
    setNotifications(updated);
    saveNotifications(updated);
  };

  const handleMarkAllNotificationsRead = () => {
    const updated = notifications.map(n => ({ ...n, isRead: true }));
    setNotifications(updated);
    saveNotifications(updated);
  };

  const handleToggleSound = () => {
    const updatedSettings = { ...merchantSettings, soundAlertEnabled: !merchantSettings.soundAlertEnabled };
    setMerchantSettings(updatedSettings);
    saveMerchantSettings(updatedSettings);
  };

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

  const handleAdminLogin = () => {
    try { sessionStorage.setItem(ADMIN_SESSION_KEY, '1'); } catch { /* ignore */ }
    setIsAdminAuthed(true);
  };

  const handleAdminLogout = () => {
    try { sessionStorage.removeItem(ADMIN_SESSION_KEY); } catch { /* ignore */ }
    setIsAdminAuthed(false);
  };

  // ───────────────────────── PRIVATE ADMIN DASHBOARD (#admin) ─────────────────────────
  if (isAdminRoute) {
    if (!isAdminAuthed) {
      return <AdminLogin merchantSettings={merchantSettings} onSuccess={handleAdminLogin} onBackToSite={goToSite} />;
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
        <MerchantDashboard
          key={adminDashboardKey}
          products={products}
          reservations={reservations}
          merchantSettings={merchantSettings}
          language="ar"
          currency={merchantSettings.currencyAr}
          onUpdateReservations={(newRes) => {
            setReservations(newRes);
            saveReservations(newRes);
          }}
          onUpdateProducts={(newProds) => {
            setProducts(newProds);
            saveProducts(newProds);
          }}
          onUpdateSettings={(newSettings) => {
            setMerchantSettings(newSettings);
            saveMerchantSettings(newSettings);
          }}
          selectedReservationCode={adminFocusCode}
        />
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
          <div>

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
                <div className="text-xs text-stone-500 font-semibold bg-stone-100 px-3 py-1.5 rounded-xl border border-stone-200 shrink-0">
                  {products.length} {language === 'ar' ? 'منتج' : 'products'}
                </div>
              </div>

              {products.length === 0 ? (
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
          </div>
        )}

        {currentView === 'track' && (
          <OrderTrackingView
            reservations={reservations}
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
          onReservationCreated={handleReservationCreated}
          onNavigateToTrack={(code) => handleNavigate('track', code)}
          language={language}
          currency={currency}
          merchantSettings={merchantSettings}
          existingReservations={reservations}
        />
      )}

      <WhatsAppButton language={language} merchantSettings={merchantSettings} />
      <MobileTabBar currentView={currentView} onNavigate={handleNavigate} language={language} merchantSettings={merchantSettings} />

      <Footer language={language} merchantSettings={merchantSettings} onNavigate={handleNavigate} />
    </div>
  );
}
