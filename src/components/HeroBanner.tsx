import React from 'react';
import { 
  ShieldCheck, 
  Sparkles, 
  Clock, 
  QrCode, 
  Search, 
  CreditCard,
  ArrowRight,
  ArrowLeft
} from 'lucide-react';
import { Language, MerchantSettings } from '../types';
import { translations } from '../translations';

interface HeroBannerProps {
  language: Language;
  merchantSettings: MerchantSettings;
  searchQuery: string;
  onSearchChange: (query: string) => void;
  selectedCategory: string;
  onCategoryChange: (category: string) => void;
  categories: { id: string; nameAr: string; nameEn: string }[];
  onOpenTrackView: () => void;
}

export const HeroBanner: React.FC<HeroBannerProps> = ({
  language,
  merchantSettings,
  searchQuery,
  onSearchChange,
  selectedCategory,
  onCategoryChange,
  categories,
  onOpenTrackView,
}) => {
  const t = translations[language];
  const ArrowIcon = language === 'ar' ? ArrowLeft : ArrowRight;

  return (
    <div className="relative overflow-hidden bg-gradient-to-b from-stone-900 via-stone-900 to-stone-950 text-white pt-10 pb-16 px-4 sm:px-6 lg:px-8 border-b border-stone-800">
      {/* Decorative background subtle glows */}
      <div className="absolute top-0 right-1/4 w-96 h-96 bg-amber-500/10 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute bottom-0 left-1/4 w-80 h-80 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none" />

      <div className="max-w-6xl mx-auto relative z-10">
        
        {/* Top Badges */}
        <div className="flex flex-wrap items-center justify-center gap-3 mb-6">
          <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-400/10 text-amber-300 border border-amber-400/20">
            <Sparkles className="w-3.5 h-3.5" />
            <span>{language === 'ar' ? 'نظام الحجز المسبق المعتمد' : 'Verified Pre-Order System'}</span>
          </div>
          <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-400/10 text-emerald-300 border border-emerald-400/20">
            <ShieldCheck className="w-3.5 h-3.5" />
            <span>{language === 'ar' ? 'الاستلام من الفرع' : 'Branch Pickup'}</span>
          </div>
          <button
            onClick={onOpenTrackView}
            className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-stone-800 text-stone-200 border border-stone-700 hover:bg-stone-700 transition-colors"
          >
            <QrCode className="w-3.5 h-3.5 text-amber-400" />
            <span>{t.navTrack}</span>
            <ArrowIcon className="w-3 h-3" />
          </button>
        </div>

        {/* Headline */}
        <div className="text-center max-w-3xl mx-auto mb-8">
          <h1 className="text-3xl sm:text-4xl md:text-5xl font-black tracking-tight text-white leading-tight">
            {t.heroTitle}
          </h1>
          <p className="mt-4 text-sm sm:text-base text-stone-300 leading-relaxed max-w-2xl mx-auto">
            {language === 'ar' ? merchantSettings.sloganAr : merchantSettings.sloganEn}
          </p>
        </div>

        {/* Feature Highlights Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 max-w-4xl mx-auto mb-10">
          <div className="flex items-center gap-3 p-3.5 rounded-xl bg-stone-800/60 border border-stone-700/60 backdrop-blur-sm">
            <div className="w-10 h-10 rounded-lg bg-amber-400/10 text-amber-400 flex items-center justify-center shrink-0">
              <CreditCard className="w-5 h-5" />
            </div>
            <div>
              <div className="font-bold text-sm text-stone-100">{t.heroStat1}</div>
              <div className="text-xs text-stone-400">
                {language === 'ar' ? 'ادفع المقدم والباقي في الفرع' : 'Pay deposit now, rest at the branch'}
              </div>
            </div>
          </div>

          <div className="flex items-center gap-3 p-3.5 rounded-xl bg-stone-800/60 border border-stone-700/60 backdrop-blur-sm">
            <div className="w-10 h-10 rounded-lg bg-emerald-400/10 text-emerald-400 flex items-center justify-center shrink-0">
              <QrCode className="w-5 h-5" />
            </div>
            <div>
              <div className="font-bold text-sm text-stone-100">{t.heroStat2}</div>
              <div className="text-xs text-stone-400">
                {language === 'ar' ? 'كود لكل حجز تتابع بيه وتستلم بيه من الفرع' : 'Digital tracking code for every pre-order'}
              </div>
            </div>
          </div>

          <div className="flex items-center gap-3 p-3.5 rounded-xl bg-stone-800/60 border border-stone-700/60 backdrop-blur-sm">
            <div className="w-10 h-10 rounded-lg bg-blue-400/10 text-blue-400 flex items-center justify-center shrink-0">
              <ShieldCheck className="w-5 h-5" />
            </div>
            <div>
              <div className="font-bold text-sm text-stone-100">{t.heroStat3}</div>
              <div className="text-xs text-stone-400">
                {language === 'ar' ? 'حوّل وابعت صورة التحويل على واتساب' : 'Transfer and send the screenshot on WhatsApp'}
              </div>
            </div>
          </div>
        </div>

        {/* Search Bar & Filter Tabs */}
        <div className="max-w-2xl mx-auto space-y-4">
          <div className="relative">
            <Search className={`absolute top-1/2 -translate-y-1/2 w-5 h-5 text-stone-400 ${
              language === 'ar' ? 'right-4' : 'left-4'
            }`} />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder={t.searchPlaceholder}
              className={`w-full py-3.5 bg-stone-800/90 border border-stone-700 rounded-xl text-stone-100 placeholder-stone-400 focus:outline-none focus:ring-2 focus:ring-amber-400 text-sm shadow-inner transition-all ${
                language === 'ar' ? 'pr-12 pl-4 text-right' : 'pl-12 pr-4 text-left'
              }`}
            />
            {searchQuery && (
              <button
                onClick={() => onSearchChange('')}
                className={`absolute top-1/2 -translate-y-1/2 text-xs text-stone-400 hover:text-white px-2 py-1 ${
                  language === 'ar' ? 'left-3' : 'right-3'
                }`}
              >
                ✕
              </button>
            )}
          </div>

          {/* Category Tabs */}
          <div className="flex items-center justify-center gap-2 overflow-x-auto pb-1 text-xs">
            {categories.map((cat) => {
              const isActive = selectedCategory === cat.id;
              return (
                <button
                  key={cat.id}
                  onClick={() => onCategoryChange(cat.id)}
                  className={`px-3.5 py-1.5 rounded-lg whitespace-nowrap font-medium transition-all ${
                    isActive
                      ? 'bg-amber-400 text-stone-950 font-bold shadow'
                      : 'bg-stone-800/80 text-stone-300 hover:bg-stone-700/80 hover:text-white'
                  }`}
                >
                  {language === 'ar' ? cat.nameAr : cat.nameEn}
                </button>
              );
            })}
          </div>
        </div>

      </div>
    </div>
  );
};
