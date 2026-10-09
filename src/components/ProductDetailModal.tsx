import React, { useState } from 'react';
import { 
  X, 
  Check, 
  Calendar, 
  ShieldCheck, 
  Sparkles, 
  CreditCard, 
  Flame, 
  ArrowRight, 
  ArrowLeft,
  Truck,
  RotateCcw
} from 'lucide-react';
import { Product, Language, MerchantSettings } from '../types';
import { BranchesCard } from './BranchesCard';
import { useEscapeKey } from '../utils/useDismiss';
import { translations } from '../translations';
import { formatCurrency } from '../utils/storage';
import { canBookNow, getBookingWindow, useNow } from '../utils/bookingWindow';
import { CountdownTimer } from './CountdownTimer';

interface ProductDetailModalProps {
  product: Product | null;
  isOpen: boolean;
  onClose: () => void;
  onBookNow: (product: Product) => void;
  language: Language;
  currency: string;
  merchantSettings: MerchantSettings;
}

export const ProductDetailModal: React.FC<ProductDetailModalProps> = ({
  product,
  isOpen,
  onClose,
  onBookNow,
  language,
  currency,
  merchantSettings,
}) => {
  const [selectedImg, setSelectedImg] = useState(0);
  const now = useNow(1000);
  useEscapeKey(onClose, isOpen);
  if (!isOpen || !product) return null;

  const t = translations[language];
  const bookingWindow = getBookingWindow(product, now);
  const isClosed = !canBookNow(product, now);
  const remainingAtBranch = Math.max(0, product.price - product.depositAmount);
  const ArrowIcon = language === 'ar' ? ArrowLeft : ArrowRight;

  return (
    <div className="sheet-overlay" onClick={onClose}>
      <div 
        className="sheet-panel sm:max-w-4xl"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Modal Header */}
        <div className="flex items-center justify-between gap-3 pt-6 pb-3 px-4 sm:p-6 border-b border-stone-100 bg-stone-50/60 sticky top-0 z-10">
          <div className="min-w-0">
            <span className="text-xs font-bold text-amber-700 uppercase tracking-wider">
              {language === 'ar' ? product.category : product.categoryEn}
            </span>
            <h2 className="text-lg sm:text-2xl font-black text-stone-900 line-clamp-2 sm:line-clamp-1 leading-snug">
              {language === 'ar' ? product.nameAr : product.nameEn}
            </h2>
          </div>
          <button
            onClick={onClose}
            className="p-2.5 shrink-0 rounded-xl text-stone-500 bg-stone-100 sm:bg-transparent hover:text-stone-700 hover:bg-stone-200/60 transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Modal Scrollable Content */}
        <div className="overflow-y-auto overscroll-contain p-4 sm:p-6 space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
            
            {/* Gallery Section */}
            <div className="space-y-3">
              <div className="aspect-[16/10] sm:aspect-[4/3] max-w-full rounded-2xl overflow-hidden bg-stone-100 border border-stone-200 shadow-inner">
                <img
                  src={product.images[selectedImg] || product.images[0]}
                  alt="Product view"
                  className="w-full h-full object-cover"
                />
              </div>

              {product.images.length > 1 && (
                <div className="flex items-center gap-2 overflow-x-auto pb-1">
                  {product.images.map((img, idx) => (
                    <button
                      key={idx}
                      onClick={() => setSelectedImg(idx)}
                      className={`w-20 h-16 rounded-xl overflow-hidden border-2 shrink-0 transition-all ${
                        selectedImg === idx ? 'border-amber-500 ring-2 ring-amber-300' : 'border-stone-200 opacity-70 hover:opacity-100'
                      }`}
                    >
                      <img src={img} alt="Thumbnail" className="w-full h-full object-cover" />
                    </button>
                  ))}
                </div>
              )}

              {/* Guarantees */}
              <div className="p-4 rounded-2xl bg-stone-50 border border-stone-200/70 space-y-2 text-xs text-stone-600">
                <div className="flex items-center gap-2 font-medium">
                  <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0" />
                  <span>{language === 'ar' ? 'المقدم جزء من السعر ومخصوم منه' : 'Deposit is part of the price'}</span>
                </div>
                <div className="flex items-center gap-2 font-medium">
                  <Truck className="w-4 h-4 text-amber-600 shrink-0" />
                  <span>{language === 'ar' ? 'الاستلام من الفرع بالكوبون' : 'Pick up at the branch with your coupon'}</span>
                </div>
                <div className="flex items-center gap-2 font-medium">
                  <RotateCcw className="w-4 h-4 text-blue-600 shrink-0" />
                  <span>{language === 'ar' ? 'قطعة واحدة لكل حجز، والباقي في الفرع' : 'One piece per booking, rest paid at branch'}</span>
                </div>
              </div>

              <BranchesCard merchantSettings={merchantSettings} />
            </div>

            {/* Info & Financial Breakdown */}
            <div className="space-y-6">
              
              {bookingWindow.state !== 'none' && <CountdownTimer product={product} language={language} size="lg" />}

              {/* Financial Box */}
              <div className="p-5 rounded-2xl bg-gradient-to-br from-amber-50/80 to-amber-100/50 border border-amber-200 space-y-3">
                <div className="flex items-baseline justify-between">
                  <span className="text-xs font-bold text-amber-900">{t.totalPrice}</span>
                  <span className="text-2xl font-black text-stone-900">
                    {formatCurrency(product.price, currency, language)}
                  </span>
                </div>
                <div className="h-px bg-amber-200/80" />
                <div className="flex items-center justify-between text-xs text-stone-700">
                  <span>{language === 'ar' ? 'الكمية المسموحة:' : 'Allowed quantity:'}</span>
                  <span className="font-bold text-stone-900">{t.oneItemOnly}</span>
                </div>
                <div className="flex items-center justify-between text-xs text-stone-700">
                  <span>{t.depositRequired} ({language === 'ar' ? 'فودافون كاش / إنستاباي' : 'Vodafone Cash / InstaPay'}):</span>
                  <span className="font-black text-emerald-800 text-sm">{formatCurrency(product.depositAmount, currency, language)}</span>
                </div>
                <div className="flex items-center justify-between text-xs text-stone-700">
                  <span>{t.remainingAtBranch}:</span>
                  <span className="font-bold text-stone-900">{formatCurrency(remainingAtBranch, currency, language)}</span>
                </div>
                <div className="flex items-center justify-between text-xs text-stone-700">
                  <span>{t.estimatedDelivery}:</span>
                  <span className="font-bold text-amber-900">{language === 'ar' ? product.estimatedArrivalAr : product.estimatedArrivalEn}</span>
                </div>
              </div>

              {/* Description */}
              <div>
                <h4 className="text-sm font-bold text-stone-900 mb-2">
                  {language === 'ar' ? 'نبذة عن المنتج' : 'About this product'}
                </h4>
                <p className="text-sm text-stone-600 leading-relaxed">
                  {language === 'ar' ? product.descriptionAr : product.descriptionEn}
                </p>
              </div>

              {/* Features List */}
              {product.featuresAr && product.featuresAr.length > 0 && (
                <div>
                  <h4 className="text-sm font-bold text-stone-900 mb-2">
                    {language === 'ar' ? 'أهم المميزات' : 'Key Features'}
                  </h4>
                  <ul className="space-y-2">
                    {(language === 'ar' ? product.featuresAr : product.featuresEn).map((feat, i) => (
                      <li key={i} className="flex items-start gap-2 text-xs text-stone-700">
                        <Check className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                        <span>{feat}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Specs */}
              {product.specs && product.specs.length > 0 && (
                <div>
                  <h4 className="text-sm font-bold text-stone-900 mb-2">
                    {language === 'ar' ? 'المواصفات الفنية' : 'Technical Specifications'}
                  </h4>
                  <div className="grid grid-cols-2 gap-2 text-xs">
                    {product.specs.map((spec, i) => (
                      <div key={i} className="p-2.5 rounded-xl bg-stone-100/70 border border-stone-200/60">
                        <span className="text-stone-500 block text-[11px]">{language === 'ar' ? spec.labelAr : spec.labelEn}</span>
                        <span className="font-bold text-stone-800">{language === 'ar' ? spec.valueAr : spec.valueEn}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

            </div>

          </div>
        </div>

        {/* Modal Footer CTA */}
        <div className="sheet-footer p-3 sm:p-5 border-t border-stone-200 bg-white flex items-center justify-between gap-3">
          <div className="shrink-0">
            <div className="text-[11px] sm:text-xs text-stone-500 font-medium">{t.depositRequired}</div>
            <div className="text-lg sm:text-xl font-black text-emerald-800 whitespace-nowrap">
              {formatCurrency(product.depositAmount, currency, language)}
            </div>
          </div>

          <div className="flex items-center gap-3 flex-1 sm:flex-none justify-end min-w-0">
            <button
              onClick={onClose}
              className="hidden sm:inline-flex px-5 py-2.5 rounded-xl border border-stone-200 text-stone-700 hover:bg-stone-50 text-sm font-semibold transition-colors"
            >
              {t.back}
            </button>
            <button
              disabled={isClosed}
              onClick={() => {
                onClose();
                onBookNow(product);
              }}
              className={`flex-1 sm:flex-none justify-center px-4 sm:px-6 py-3 sm:py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 transition-all shadow-md ${
                isClosed
                  ? 'bg-stone-200 text-stone-400 cursor-not-allowed'
                  : 'bg-stone-900 hover:bg-stone-800 text-amber-300 hover:text-amber-200'
              }`}
            >
              <span>{bookingWindow.state === 'ended' ? (language === 'ar' ? 'انتهى وقت الحجز' : 'Booking ended') : bookingWindow.state === 'not_started' ? (language === 'ar' ? 'الحجز لسه مبدأش' : 'Not open yet') : isClosed ? t.statusClosed : t.reserveNow}</span>
              {!isClosed && <ArrowIcon className="w-4 h-4" />}
            </button>
          </div>
        </div>

      </div>
    </div>
  );
};
