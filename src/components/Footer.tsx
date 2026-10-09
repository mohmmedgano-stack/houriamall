import React, { useRef } from 'react';
import { MessageCircle } from 'lucide-react';
import { Language, MerchantSettings } from '../types';
import { translations } from '../translations';
import { whatsappLink } from '../utils/storage';

interface FooterProps {
  language: Language;
  merchantSettings: MerchantSettings;
  onNavigate: (view: 'store' | 'track') => void;
}

export const Footer: React.FC<FooterProps> = ({
  language,
  merchantSettings,
  onNavigate,
}) => {
  const t = translations[language];
  const adminTaps = useRef<number[]>([]);

  return (
    <footer className="print:hidden bg-brand-700 text-stone-300 border-t border-brand-800 mt-10 sm:mt-20">
      {/* Main Footer Links */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7 sm:py-10">
        <div className="flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-right rtl:md:text-right ltr:md:text-left">
          <div>
            <h3
              className="text-lg font-black text-white select-none"
              onClick={() => {
                // Hidden admin entry: 5 quick taps on the store name opens #admin
                const now = Date.now();
                adminTaps.current = adminTaps.current.filter(t => now - t < 2500).concat(now);
                if (adminTaps.current.length >= 5) {
                  adminTaps.current = [];
                  window.location.hash = 'admin';
                  window.scrollTo({ top: 0 });
                }
              }}
            >
              {language === 'ar' ? merchantSettings.storeNameAr : merchantSettings.storeNameEn}
            </h3>
            <p className="text-xs text-stone-400 mt-1 max-w-md">
              {t.footerNotice}
            </p>
          </div>

          <div className="flex flex-wrap items-center justify-center gap-4 text-xs font-semibold">
            <button
              onClick={() => onNavigate('store')}
              className="text-stone-300 hover:text-white transition-colors"
            >
              {t.navBrowse}
            </button>
            <span className="text-stone-700">·</span>
            <button
              onClick={() => onNavigate('track')}
              className="text-stone-300 hover:text-white transition-colors"
            >
              {t.navTrack}
            </button>
            <span className="text-stone-700">·</span>
            <a
              href={whatsappLink(merchantSettings.whatsappNumber)}
              target="_blank"
              rel="noopener noreferrer"
              className="text-emerald-400 hover:text-emerald-300 flex items-center gap-1"
            >
              <MessageCircle className="w-3.5 h-3.5" />
              <span>دعم واتساب</span>
            </a>
          </div>
        </div>
      </div>
    </footer>
  );
};
