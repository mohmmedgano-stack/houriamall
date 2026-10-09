import React from 'react';
import { Store, Search } from 'lucide-react';
import { Language, MerchantSettings } from '../types';
import { whatsappLink } from '../utils/storage';

type PublicView = 'store' | 'track';

interface MobileTabBarProps {
  currentView: PublicView;
  onNavigate: (view: PublicView) => void;
  language: Language;
  merchantSettings: MerchantSettings;
}

// Phone-only bottom navigation: products, track booking, WhatsApp
export const MobileTabBar: React.FC<MobileTabBarProps> = ({ currentView, onNavigate, language, merchantSettings }) => {
  const ar = language === 'ar';
  const item = (active: boolean) =>
    `flex-1 flex flex-col items-center justify-center gap-0.5 h-16 text-[11px] font-bold transition-colors ${
      active ? 'text-stone-900' : 'text-stone-400'
    }`;

  return (
    <nav className="mobile-tabbar md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-stone-200 shadow-[0_-4px_20px_rgb(0_0_0/0.06)] print:hidden">
      <div className="flex items-stretch max-w-md mx-auto">
        <button onClick={() => onNavigate('store')} className={item(currentView === 'store')} aria-current={currentView === 'store' ? 'page' : undefined}>
          <span className={`flex items-center justify-center w-12 h-7 rounded-full ${currentView === 'store' ? 'bg-brand-300/60' : ''}`}>
            <Store className="w-5 h-5" />
          </span>
          <span>{ar ? 'المنتجات' : 'Products'}</span>
        </button>
        <button onClick={() => onNavigate('track')} className={item(currentView === 'track')} aria-current={currentView === 'track' ? 'page' : undefined}>
          <span className={`flex items-center justify-center w-12 h-7 rounded-full ${currentView === 'track' ? 'bg-brand-300/60' : ''}`}>
            <Search className="w-5 h-5" />
          </span>
          <span>{ar ? 'تتبع حجزي' : 'Track'}</span>
        </button>
        <a
          href={whatsappLink(merchantSettings.whatsappNumber, ar ? 'مرحباً، عندي استفسار بخصوص الحجز' : 'Hello, I have a question about booking')}
          target="_blank"
          rel="noopener noreferrer"
          className={item(false) + ' !text-[#128C7E]'}
        >
          <span className="flex items-center justify-center w-12 h-7 rounded-full bg-[#25D366]/15">
            <svg viewBox="0 0 32 32" className="w-5 h-5 fill-current" aria-hidden="true">
              <path d="M16.04 3C9.4 3 4 8.38 4 15a11.9 11.9 0 0 0 1.62 6L4 29l8.2-1.58A12.06 12.06 0 0 0 16.04 27C22.66 27 28 21.62 28 15S22.66 3 16.04 3Zm5.34 14.5c-.3-.14-1.74-.86-2-.96-.28-.1-.48-.14-.68.14-.2.3-.78.96-.96 1.16-.18.2-.36.22-.66.08-.3-.14-1.24-.46-2.36-1.46-.88-.78-1.46-1.74-1.64-2.04-.16-.3-.02-.46.14-.6.12-.12.3-.34.44-.5.14-.18.2-.3.3-.5.1-.2.04-.38-.02-.52-.08-.14-.68-1.64-.92-2.24-.24-.58-.5-.5-.68-.5h-.58c-.2 0-.52.08-.8.38-.28.3-1.04 1.02-1.04 2.5s1.08 2.9 1.22 3.1c.14.2 2.1 3.2 5.08 4.48.7.3 1.26.48 1.7.62.7.22 1.36.2 1.86.12.58-.08 1.74-.7 2-1.4.24-.68.24-1.28.18-1.4-.08-.12-.28-.2-.58-.34Z" />
            </svg>
          </span>
          <span>{ar ? 'واتساب' : 'WhatsApp'}</span>
        </a>
      </div>
    </nav>
  );
};
