import React from 'react';
import { Search, Globe, Store } from 'lucide-react';
import { Language, MerchantSettings } from '../types';
import { translations } from '../translations';

type PublicView = 'store' | 'track';

interface NavbarProps {
  currentView: PublicView;
  onNavigate: (view: PublicView, extraCode?: string) => void;
  language: Language;
  onToggleLanguage: () => void;
  merchantSettings: MerchantSettings;
}

// Visitor-facing header: browse, track booking, language. No admin entry points.
export const Navbar: React.FC<NavbarProps> = ({
  currentView,
  onNavigate,
  language,
  onToggleLanguage,
  merchantSettings,
}) => {
  const t = translations[language];
  const announcement = (language === 'ar' ? merchantSettings.announcementAr : merchantSettings.announcementEn || merchantSettings.announcementAr).trim();

  const tab = (view: PublicView, Icon: typeof Store, label: string, mobile = false) => (
    <button
      onClick={() => onNavigate(view)}
      className={
        mobile
          ? `flex items-center gap-1.5 px-4 py-1.5 text-xs font-bold rounded-lg ${currentView === view ? 'bg-brand-700 text-white' : 'text-stone-600'}`
          : `flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-lg transition-all ${
              currentView === view ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-200/50'
            }`
      }
    >
      <Icon className={mobile ? 'w-3.5 h-3.5' : 'w-4 h-4'} />
      <span>{label}</span>
    </button>
  );

  return (
    <header className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-stone-200 print:hidden" style={{ paddingTop: 'env(safe-area-inset-top, 0px)' }}>
      {merchantSettings.announcementEnabled && announcement && (
        <div className="bg-brand-700 text-white px-4 py-2 text-center text-xs sm:text-sm font-extrabold leading-snug">
          {announcement}
        </div>
      )}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between gap-3 h-14 md:h-20">
          {/* Logo & Store Branding */}
          <div className="flex items-center gap-2.5 md:gap-3 cursor-pointer min-w-0" onClick={() => onNavigate('store')}>
            <img src="/logo-wide.png" alt="Houria حورية" className="h-10 md:h-14 w-auto rounded-xl shrink-0 shadow-sm ring-1 ring-brand-200" />
            <div>
              <span className="block font-extrabold text-lg md:text-xl tracking-tight text-brand-700 truncate">
                {language === 'ar' ? merchantSettings.storeNameAr : merchantSettings.storeNameEn}
              </span>
              <p className="text-xs text-stone-500 hidden sm:block truncate max-w-xs">{t.brandBadge}</p>
            </div>
          </div>

          <nav className="hidden md:flex items-center gap-1 bg-stone-100 p-1.5 rounded-xl border border-stone-200/80">
            {tab('store', Store, t.navBrowse)}
            {tab('track', Search, t.navTrack)}
          </nav>

          <button
            onClick={onToggleLanguage}
            className="flex items-center gap-1.5 px-2.5 md:px-3 py-2 rounded-lg border border-stone-200 bg-white text-xs font-semibold text-stone-700 hover:bg-stone-50 transition-colors shrink-0"
          >
            <Globe className="w-3.5 h-3.5 text-stone-500" />
            <span className="md:hidden">{language === 'ar' ? 'EN' : 'ع'}</span>
            <span className="hidden md:inline">{t.languageToggle}</span>
          </button>
        </div>

      </div>
    </header>
  );
};
