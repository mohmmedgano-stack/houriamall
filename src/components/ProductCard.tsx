import React, { useRef, useState } from 'react';
import { 
  Calendar, 
  CheckCircle2, 
  Clock, 
  Flame, 
  ArrowRight, 
  ArrowLeft,
  ChevronRight,
  Info,
  Layers,
  Images
} from 'lucide-react';
import { Product, Language, MerchantSettings } from '../types';
import { translations } from '../translations';
import { formatCurrency } from '../utils/storage';
import { getBookingWindow, useNow } from '../utils/bookingWindow';
import { CountdownTimer } from './CountdownTimer';

interface ProductCardProps {
  product: Product;
  language: Language;
  currency: string;
  onBookNow: (product: Product) => void;
  onViewDetails: (product: Product) => void;
}

export const ProductCard: React.FC<ProductCardProps> = ({
  product,
  language,
  currency,
  onBookNow,
  onViewDetails,
}) => {
  const t = translations[language];
  const percentBooked = Math.min(100, Math.round((product.reservedCount / product.reservationQuota) * 100));
  const remainingSlots = Math.max(0, product.reservationQuota - product.reservedCount);
  const now = useNow(1000);
  const bookingWindow = getBookingWindow(product, now);
  const timeEnded = bookingWindow.state === 'ended';
  const notStarted = bookingWindow.state === 'not_started' || product.status === 'coming_soon';
  const isClosed = product.status === 'closed' || remainingSlots === 0 || timeEnded;
  const cannotBook = isClosed || notStarted;
  const isLimited = product.status === 'limited' || (remainingSlots <= 5 && remainingSlots > 0);
  const remainingAtBranch = Math.max(0, product.price - product.depositAmount);
  const ArrowIcon = language === 'ar' ? ArrowLeft : ArrowRight;

  return (
    <div className="group bg-white rounded-2xl border border-stone-200/90 hover:border-brand-400/80 shadow-sm hover:shadow-xl transition-all duration-300 flex flex-col overflow-hidden">
      
      {/* Product Image & Badges */}
      <div className="relative aspect-[16/10] sm:aspect-[4/3] w-full max-w-full overflow-hidden bg-stone-100">
        <CardGallery images={product.images} alt={language === 'ar' ? product.nameAr : product.nameEn} />

        {/* Status Tag */}
        <div className="absolute top-3 right-3 flex items-center gap-1.5 z-10">
          {isClosed ? (
            <span className="px-2.5 py-1 text-xs font-bold bg-brand-700/90 backdrop-blur-sm text-stone-200 rounded-md">
              {timeEnded ? (language === 'ar' ? 'انتهى الوقت' : 'Time over') : t.statusClosed}
            </span>
          ) : notStarted ? (
            <span className="px-2.5 py-1 text-xs font-bold bg-indigo-600/95 backdrop-blur-sm text-white rounded-md">
              {t.statusComingSoon}
            </span>
          ) : isLimited ? (
            <span className="px-2.5 py-1 text-xs font-bold bg-red-600/95 backdrop-blur-sm text-white rounded-md flex items-center gap-1 shadow">
              <Flame className="w-3.5 h-3.5" />
              <span>{t.statusLimited}</span>
            </span>
          ) : (
            <span className="px-2.5 py-1 text-xs font-bold bg-emerald-700/95 backdrop-blur-sm text-white rounded-md flex items-center gap-1">
              <CheckCircle2 className="w-3.5 h-3.5" />
              <span>{t.statusActive}</span>
            </span>
          )}
        </div>

        {/* Estimated Arrival Badge */}
        <div className="absolute bottom-3 left-3 bg-brand-800/85 backdrop-blur-md text-stone-200 px-2.5 py-1 rounded-md text-[11px] font-medium flex items-center gap-1.5 border border-stone-700/60">
          <Calendar className="w-3.5 h-3.5 text-brand-400" />
          <span>{language === 'ar' ? product.estimatedArrivalAr : product.estimatedArrivalEn}</span>
        </div>
      </div>

      {/* Product Content */}
      <div className="p-4 sm:p-5 flex-1 flex flex-col justify-between">
        <div>
          {/* Category */}
          <div className="text-[11px] font-semibold text-stone-500 uppercase tracking-wider mb-1">
            {language === 'ar' ? product.category : product.categoryEn}
          </div>

          {/* Title */}
          <h3 
            onClick={() => onViewDetails(product)}
            className="text-base font-bold text-stone-900 line-clamp-1 hover:text-brand-600 cursor-pointer transition-colors"
          >
            {language === 'ar' ? product.nameAr : product.nameEn}
          </h3>

          {/* Description */}
          <p className="mt-1.5 text-xs text-stone-600 line-clamp-2 leading-relaxed">
            {language === 'ar' ? product.descriptionAr : product.descriptionEn}
          </p>

          {/* Quota Progress Bar */}
          <div className="mt-4 p-2.5 rounded-xl bg-stone-50 border border-stone-200/60">
            <div className="flex items-center justify-between text-xs mb-1.5">
              <span className="text-stone-600 font-medium">
                {t.reservedQuota}: <strong className="text-stone-900 font-bold">{product.reservedCount} / {product.reservationQuota}</strong> {t.pieces}
              </span>
              <span className={`font-bold ${isLimited ? 'text-red-600' : 'text-emerald-700'}`}>
                {remainingSlots} {t.availableSlots}
              </span>
            </div>
            <div className="w-full h-2 bg-stone-200 rounded-full overflow-hidden">
              <div 
                className={`h-full transition-all duration-500 ${
                  isClosed ? 'bg-stone-400' : isLimited ? 'bg-gradient-to-r from-brand-500 to-red-500' : 'bg-emerald-600'
                }`}
                style={{ width: `${percentBooked}%` }}
              />
            </div>
          </div>

          {/* Booking countdown */}
          {bookingWindow.state !== 'none' && (
            <div className="mt-3">
              <CountdownTimer product={product} language={language} />
            </div>
          )}

          {/* Price & booking terms */}
          <div className="mt-4 p-3 rounded-xl bg-brand-50/60 border border-brand-200/80 flex items-end justify-between gap-2">
            <div>
              <span className="text-[11px] text-stone-600 block font-medium">{t.totalPrice}</span>
              <div className="flex items-baseline gap-1.5">
                <span className="text-lg font-black text-stone-900 leading-tight">
                  {formatCurrency(product.price, currency, language)}
                </span>
                {product.originalPrice && (
                  <span className="text-[11px] text-stone-400 line-through">
                    {formatCurrency(product.originalPrice, currency, language)}
                  </span>
                )}
              </div>
            </div>
            <div className="text-end">
              <span className="text-[11px] text-emerald-800 block font-bold">{t.depositRequired}</span>
              <span className="text-lg font-black text-emerald-700 leading-tight">
                {formatCurrency(product.depositAmount, currency, language)}
              </span>
            </div>
          </div>
          
          <div className="mt-1.5 text-[11px] text-stone-500 px-1 flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
            <span>{t.remainingAtBranch}: <strong className="text-stone-700">{formatCurrency(remainingAtBranch, currency, language)}</strong></span>
            <span className="flex items-center gap-1 whitespace-nowrap"><Layers className="w-3 h-3" />{t.oneItemOnly}</span>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="mt-4 sm:mt-5 pt-3 border-t border-stone-100 flex items-center gap-2">
          <button
            onClick={() => onViewDetails(product)}
            className="px-3.5 py-3 sm:px-3 sm:py-2.5 rounded-xl border border-stone-200 text-stone-700 hover:bg-stone-50 text-xs font-semibold transition-colors shrink-0"
            title={t.viewDetails}
          >
            <Info className="w-4 h-4" />
          </button>

          <button
            disabled={cannotBook}
            onClick={() => onBookNow(product)}
            className={`flex-1 py-3 sm:py-2.5 px-4 rounded-xl text-sm font-bold flex items-center justify-center gap-2 transition-all shadow-sm ${
              cannotBook
                ? 'bg-stone-200 text-stone-400 cursor-not-allowed'
                : 'bg-brand-700 hover:bg-brand-800 text-white hover:text-white shadow hover:shadow-md'
            }`}
          >
            <span>{timeEnded ? (language === 'ar' ? 'انتهى وقت الحجز' : 'Booking ended') : isClosed ? t.statusClosed : notStarted ? (language === 'ar' ? 'الحجز لسه مبدأش' : 'Not open yet') : `${t.reserveNow} ${formatCurrency(product.depositAmount, currency, language)}`}</span>
            {!cannotBook && <ArrowIcon className="w-4 h-4" />}
          </button>
        </div>

      </div>

    </div>
  );
};


/** Swipeable photo strip for the product card (scroll-snap, works in RTL). */
const CardGallery: React.FC<{ images: string[]; alt: string }> = ({ images, alt }) => {
  const list = images.length > 0 ? images : ['https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=600&q=80'];
  const ref = useRef<HTMLDivElement>(null);
  const [idx, setIdx] = useState(0);
  const onScroll = () => {
    const el = ref.current; if (!el) return;
    setIdx(Math.min(list.length - 1, Math.round(Math.abs(el.scrollLeft) / el.clientWidth)));
  };
  const goTo = (i: number) => {
    const el = ref.current; if (!el) return;
    const dir = getComputedStyle(el).direction === 'rtl' ? -1 : 1;
    el.scrollTo({ left: dir * i * el.clientWidth, behavior: 'smooth' });
  };
  return (
    <>
      <div ref={ref} onScroll={onScroll} className="absolute inset-0 flex overflow-x-auto snap-x snap-mandatory [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {list.map((src, i) => (
          <img
            key={i}
            src={src}
            alt={alt}
            loading={i === 0 ? 'eager' : 'lazy'}
            draggable={false}
            className="w-full h-full shrink-0 snap-center object-cover"
          />
        ))}
      </div>
      {list.length > 1 && (
        <>
          <div className="absolute bottom-3 right-3 z-10 px-2 py-0.5 rounded-md bg-black/55 text-white text-[10px] font-bold flex items-center gap-1">
            <Images className="w-3 h-3" />
            <span>{idx + 1}/{list.length}</span>
          </div>
          <div className="absolute bottom-12 inset-x-0 z-10 flex items-center justify-center gap-1.5 pointer-events-none">
            {list.map((_, i) => (
              <button
                key={i}
                type="button"
                onClick={() => goTo(i)}
                aria-label={`صورة ${i + 1}`}
                className={`pointer-events-auto h-1.5 rounded-full transition-all ${i === idx ? 'w-4 bg-white' : 'w-1.5 bg-white/60'}`}
              />
            ))}
          </div>
        </>
      )}
    </>
  );
};
