import React, { useState, useEffect } from 'react';
import { isoToLocalInput, localInputToIso } from '../utils/bookingWindow';
import { X, Plus, Trash2, Image as ImageIcon, Sparkles } from 'lucide-react';
import { compressImage } from '../utils/api';
import { Product, Language, ProductStatus } from '../types';
import { translations } from '../translations';
import { useEscapeKey } from '../utils/useDismiss';

interface NewProductModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (product: Product) => void;
  productToEdit?: Product | null;
  language: Language;
  currency: string;
}

export const NewProductModal: React.FC<NewProductModalProps> = ({
  isOpen,
  onClose,
  onSave,
  productToEdit,
  language,
  currency,
}) => {
  const t = translations[language];

  const [nameAr, setNameAr] = useState('');
  const [nameEn, setNameEn] = useState('');
  const [category, setCategory] = useState('أجهزة إلكترونية');
  const [categoryEn, setCategoryEn] = useState('Electronics');
  const [price, setPrice] = useState<number>(3500);
  const [depositAmount, setDepositAmount] = useState<number>(300);
  const [reservationQuota, setReservationQuota] = useState<number>(20);
  const [estimatedArrivalAr, setEstimatedArrivalAr] = useState('١٥ نوفمبر ٢٠٢٦');
  const [estimatedArrivalEn, setEstimatedArrivalEn] = useState('November 15, 2026');
  const [status, setStatus] = useState<ProductStatus>('active');
  const [descriptionAr, setDescriptionAr] = useState('');
  const [descriptionEn, setDescriptionEn] = useState('');
  const [images, setImages] = useState<string[]>([]);
  const [imageUrlInput, setImageUrlInput] = useState('');
  const [imageBusy, setImageBusy] = useState(false);
  const MAX_IMAGES = 8;
  const addImages = (list: string[]) => setImages(prev => [...prev, ...list.filter(u => u && !prev.includes(u))].slice(0, MAX_IMAGES));
  const removeImage = (i: number) => setImages(prev => prev.filter((_, idx) => idx !== i));
  const makeMain = (i: number) => setImages(prev => [prev[i], ...prev.filter((_, idx) => idx !== i)]);
  const moveImage = (i: number, dir: -1 | 1) => setImages(prev => {
    const j = i + dir; if (j < 0 || j >= prev.length) return prev;
    const next = [...prev]; [next[i], next[j]] = [next[j], next[i]]; return next;
  });
  const handleFiles = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setImageBusy(true);
    try {
      const room = MAX_IMAGES - images.length;
      const picked = Array.from(files).filter(f => f.type.startsWith('image/')).slice(0, Math.max(0, room));
      const out: string[] = [];
      for (const f of picked) {
        try { out.push(await compressImage(f, 1400, 0.8)); } catch { /* skip unreadable file */ }
      }
      addImages(out);
    } finally { setImageBusy(false); }
  };
  const [featuresArText, setFeaturesArText] = useState('');
  const [errorMessage, setErrorMessage] = useState('');
  // Booking countdown window (datetime-local values)
  const [timerEnabled, setTimerEnabled] = useState(false);
  const [startsAtLocal, setStartsAtLocal] = useState('');
  const [endsAtLocal, setEndsAtLocal] = useState('');

  // Sample quick images presets for merchant convenience
  const sampleImages = [
    'https://images.unsplash.com/photo-1517668808822-9ebb02f2a0e6?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1546868871-7041f2a55e12?auto=format&fit=crop&w=800&q=80',
  ];

  useEffect(() => {
    if (productToEdit) {
      setNameAr(productToEdit.nameAr);
      setNameEn(productToEdit.nameEn);
      setCategory(productToEdit.category);
      setCategoryEn(productToEdit.categoryEn);
      setPrice(productToEdit.price);
      setDepositAmount(productToEdit.depositAmount ?? 0);
      setReservationQuota(productToEdit.reservationQuota);
      setEstimatedArrivalAr(productToEdit.estimatedArrivalAr);
      setEstimatedArrivalEn(productToEdit.estimatedArrivalEn);
      setStatus(productToEdit.status);
      setTimerEnabled(Boolean(productToEdit.bookingEndsAt || productToEdit.bookingStartsAt));
      setStartsAtLocal(isoToLocalInput(productToEdit.bookingStartsAt));
      setEndsAtLocal(isoToLocalInput(productToEdit.bookingEndsAt));
      setDescriptionAr(productToEdit.descriptionAr);
      setDescriptionEn(productToEdit.descriptionEn);
      setImages(productToEdit.images || []);
      setFeaturesArText(productToEdit.featuresAr?.join('\n') || '');
    } else {
      setNameAr('');
      setNameEn('');
      setCategory('أجهزة إلكترونية فاخرة');
      setCategoryEn('Premium Electronics');
      setPrice(2500);
      setDepositAmount(300);
      setReservationQuota(25);
      setEstimatedArrivalAr('٢٠ نوفمبر ٢٠٢٦');
      setEstimatedArrivalEn('November 20, 2026');
      setStatus('active');
      setTimerEnabled(false);
      setStartsAtLocal('');
      setEndsAtLocal('');
      setDescriptionAr('');
      setDescriptionEn('');
      setImages([]);
      setFeaturesArText('ضمان أصالة معتمد لمدة عامين\nشحن سريع ومجاني عند وصول الدفعة\nمقدم بسيط والباقي عند الاستلام من الفرع');
    }
  }, [productToEdit]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!nameAr.trim()) {
      setErrorMessage('يرجى كتابة اسم المنتج بالعربية');
      return;
    }
    if (price <= 0) {
      setErrorMessage('السعر لازم يكون أكبر من صفر');
      return;
    }
    if (depositAmount <= 0 || depositAmount >= price) {
      setErrorMessage('المقدم لازم يكون أكبر من صفر وأقل من السعر الإجمالي');
      return;
    }

    const bookingStartsAt = timerEnabled ? localInputToIso(startsAtLocal) : undefined;
    const bookingEndsAt = timerEnabled ? localInputToIso(endsAtLocal) : undefined;
    if (timerEnabled && !bookingEndsAt) {
      setErrorMessage('حدد وقت انتهاء الحجز، أو اقفل عداد الوقت');
      return;
    }
    if (bookingStartsAt && bookingEndsAt && new Date(bookingStartsAt) >= new Date(bookingEndsAt)) {
      setErrorMessage('وقت بداية الحجز لازم يكون قبل وقت النهاية');
      return;
    }
    if (reservationQuota < (productToEdit?.reservedCount ?? 0)) {
      setErrorMessage(`الكمية مينفعش تكون أقل من عدد القطع المحجوزة فعلاً (${productToEdit?.reservedCount})`);
      return;
    }

    const featuresList = featuresArText
      .split('\n')
      .map(s => s.trim())
      .filter(Boolean);

    const product: Product = {
      id: productToEdit ? productToEdit.id : `prod-${Date.now()}`,
      nameAr: nameAr.trim(),
      nameEn: nameEn.trim() || nameAr.trim(),
      category: category.trim(),
      categoryEn: categoryEn.trim(),
      price: Number(price),
      depositAmount: Number(depositAmount),
      reservationQuota: Number(reservationQuota),
      reservedCount: productToEdit ? productToEdit.reservedCount : 0,
      estimatedArrivalAr: estimatedArrivalAr.trim(),
      estimatedArrivalEn: estimatedArrivalEn.trim() || estimatedArrivalAr.trim(),
      status,
      bookingStartsAt,
      bookingEndsAt,
      descriptionAr: descriptionAr.trim() || 'منتج حصري متاح للحجز المسبق لفترة محدودة.',
      descriptionEn: descriptionEn.trim() || 'Exclusive pre-order item available for a limited time.',
      images: images.length > 0 ? images : [sampleImages[0]],
      featuresAr: featuresList.length > 0 ? featuresList : ['ضمان شامل لمدة عام', 'مقدم بسيط والباقي عند الاستلام من الفرع'],
      featuresEn: featuresList.length > 0 ? featuresList : ['1-Year Comprehensive Warranty', 'Small deposit, rest paid at branch pickup'],
      createdAt: productToEdit ? productToEdit.createdAt : new Date().toISOString(),
    };

    onSave(product);
    onClose();
  };

  useEscapeKey(onClose, isOpen);
  if (!isOpen) return null;

  return (
    <div className="sheet-overlay" onClick={onClose}>
      <div 
        className="sheet-panel sm:max-w-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between p-4 sm:p-5 border-b border-stone-100 bg-stone-50/70">
          <h2 className="text-lg font-black text-stone-900">
            {productToEdit ? t.modalEditTitle : t.modalAddTitle}
          </h2>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-stone-400 hover:text-stone-700 hover:bg-stone-200/50"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="overflow-y-auto p-4 sm:p-6 space-y-4">
          {errorMessage && (
            <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs">
              {errorMessage}
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-stone-700 mb-1">
                {t.productNameArLabel} *
              </label>
              <input
                type="text"
                value={nameAr}
                onChange={(e) => setNameAr(e.target.value)}
                placeholder="مثال: ساعة تيتانيوم ذكية الترا"
                className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs sm:text-sm focus:ring-2 focus:ring-stone-900"
                required
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-stone-700 mb-1">
                {t.productNameEnLabel}
              </label>
              <input
                type="text"
                value={nameEn}
                onChange={(e) => setNameEn(e.target.value)}
                placeholder="e.g. Titanium Ultra Smartwatch"
                className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs sm:text-sm focus:ring-2 focus:ring-stone-900"
              />
            </div>
          </div>

          {/* Pricing, deposit & quota (each booking = one piece) */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 rounded-2xl bg-brand-50/70 border border-brand-200">
            <div>
              <label className="block text-xs font-bold text-stone-900 mb-1">
                {t.priceLabel} ({currency}) *
              </label>
              <input
                type="number"
                min="1"
                value={price}
                onChange={(e) => setPrice(Number(e.target.value))}
                className="w-full px-3 py-2 rounded-xl border border-brand-300 bg-white font-bold text-stone-900 text-sm"
                required
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-emerald-900 mb-1">
                {t.depositLabel} ({currency}) *
              </label>
              <input
                type="number"
                min="1"
                max={price}
                value={depositAmount}
                onChange={(e) => setDepositAmount(Number(e.target.value))}
                className="w-full px-3 py-2 rounded-xl border border-emerald-400 bg-white font-black text-emerald-800 text-sm"
                required
              />
              <span className="text-[10px] text-stone-500 mt-0.5 block">
                الباقي في الفرع: {Math.max(0, price - depositAmount)} {currency}
              </span>
            </div>

            <div>
              <label className="block text-xs font-bold text-stone-900 mb-1">
                {t.quotaLabel} *
              </label>
              <input
                type="number"
                min="1"
                value={reservationQuota}
                onChange={(e) => setReservationQuota(Number(e.target.value))}
                className="w-full px-3 py-2 rounded-xl border border-stone-300 bg-white font-bold text-stone-900 text-sm"
                required
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-stone-700 mb-1">
                {t.arrivalDateLabel}
              </label>
              <input
                type="text"
                value={estimatedArrivalAr}
                onChange={(e) => setEstimatedArrivalAr(e.target.value)}
                placeholder="مثال: ١٥ نوفمبر ٢٠٢٦"
                className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs sm:text-sm"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-stone-700 mb-1">
                حالة المنتج للحجز
              </label>
              <select
                value={status}
                onChange={(e) => setStatus(e.target.value as ProductStatus)}
                className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs sm:text-sm bg-white"
              >
                <option value="active">متاح للحجز (Active)</option>
                <option value="limited">أوشك على النفاد (Limited)</option>
                <option value="closed">اكتمل الحجز (Closed)</option>
                <option value="coming_soon">قريباً (Coming Soon)</option>
              </select>
            </div>
          </div>

          {/* Booking countdown timer */}
          <div className="p-3.5 rounded-2xl bg-indigo-50/60 border border-indigo-200 space-y-3">
            <label className="flex items-center justify-between gap-3 cursor-pointer">
              <div>
                <span className="block text-sm font-bold text-indigo-950">⏱️ عداد وقت للحجز</span>
                <span className="block text-[11px] text-indigo-800">الحجز يقفل لوحده لما الوقت يخلص، والعداد بيظهر للزوار على المنتج.</span>
              </div>
              <input
                type="checkbox"
                checked={timerEnabled}
                onChange={(e) => {
                  setTimerEnabled(e.target.checked);
                  if (e.target.checked && !endsAtLocal) {
                    setEndsAtLocal(isoToLocalInput(new Date(Date.now() + 3 * 24 * 3600 * 1000).toISOString()));
                  }
                }}
                className="w-5 h-5 accent-indigo-600"
              />
            </label>

            {timerEnabled && (
              <>
                <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                  <span className="text-indigo-900 font-bold">مدة سريعة من دلوقتي:</span>
                  {[
                    { l: '٦ ساعات', h: 6 },
                    { l: '٢٤ ساعة', h: 24 },
                    { l: '٣ أيام', h: 72 },
                    { l: 'أسبوع', h: 168 },
                    { l: 'أسبوعين', h: 336 },
                  ].map(p => (
                    <button
                      type="button"
                      key={p.h}
                      onClick={() => {
                        setStartsAtLocal('');
                        setEndsAtLocal(isoToLocalInput(new Date(Date.now() + p.h * 3600 * 1000).toISOString()));
                      }}
                      className="px-2.5 py-1 rounded-lg bg-white border border-indigo-300 text-indigo-800 font-bold hover:bg-indigo-100"
                    >
                      {p.l}
                    </button>
                  ))}
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-bold text-stone-800 mb-1">بداية الحجز (اختياري)</label>
                    <input
                      type="datetime-local"
                      value={startsAtLocal}
                      onChange={(e) => setStartsAtLocal(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl border border-indigo-300 bg-white text-xs sm:text-sm"
                    />
                    <span className="text-[10px] text-stone-500">لو حددتها، الزوار هيشوفوا عداد "الحجز يبدأ خلال".</span>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-stone-800 mb-1">نهاية الحجز *</label>
                    <input
                      type="datetime-local"
                      value={endsAtLocal}
                      onChange={(e) => setEndsAtLocal(e.target.value)}
                      className="w-full px-3 py-2 rounded-xl border border-indigo-300 bg-white text-xs sm:text-sm"
                    />
                  </div>
                </div>
              </>
            )}
          </div>

          {/* Product images (up to 8) — the first one is the main photo */}
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="block text-xs font-bold text-stone-700">صور المنتج</label>
              <span className="text-[10px] font-bold text-stone-500">{images.length} / {MAX_IMAGES}</span>
            </div>

            {images.length > 0 ? (
              <div className="grid grid-cols-3 sm:grid-cols-4 gap-2">
                {images.map((img, i) => (
                  <div key={img.slice(-40) + i} className={`relative aspect-square rounded-xl overflow-hidden border-2 bg-stone-100 ${i === 0 ? 'border-brand-600' : 'border-stone-200'}`}>
                    <img src={img} alt="" className="w-full h-full object-cover" />
                    {i === 0 && (
                      <span className="absolute top-1 right-1 px-1.5 py-0.5 rounded-md bg-brand-700 text-white text-[9px] font-bold">الرئيسية</span>
                    )}
                    <button
                      type="button"
                      onClick={() => removeImage(i)}
                      className="absolute top-1 left-1 w-6 h-6 rounded-full bg-black/60 hover:bg-red-600 text-white flex items-center justify-center"
                      title="حذف الصورة"
                      aria-label="حذف الصورة"
                    >
                      <X className="w-3.5 h-3.5" />
                    </button>
                    <div className="absolute bottom-0 inset-x-0 flex items-center justify-between gap-1 p-1 bg-gradient-to-t from-black/60 to-transparent">
                      <button type="button" disabled={i === 0} onClick={() => moveImage(i, -1)} className="w-6 h-6 rounded-md bg-white/85 text-stone-800 text-xs font-black disabled:opacity-30" title="قدّم">›</button>
                      {i !== 0 && (
                        <button type="button" onClick={() => makeMain(i)} className="px-1.5 h-6 rounded-md bg-white/90 text-brand-800 text-[9px] font-bold" title="خليها الصورة الرئيسية">رئيسية</button>
                      )}
                      <button type="button" disabled={i === images.length - 1} onClick={() => moveImage(i, 1)} className="w-6 h-6 rounded-md bg-white/85 text-stone-800 text-xs font-black disabled:opacity-30" title="أخّر">‹</button>
                    </div>
                  </div>
                ))}
                {images.length < MAX_IMAGES && (
                  <label className="aspect-square rounded-xl border-2 border-dashed border-brand-300 bg-brand-50/50 hover:bg-brand-50 flex flex-col items-center justify-center gap-1 cursor-pointer text-brand-700">
                    <Plus className="w-5 h-5" />
                    <span className="text-[10px] font-bold">{imageBusy ? 'جاري التجهيز…' : 'زوّد صور'}</span>
                    <input type="file" accept="image/*" multiple className="hidden" disabled={imageBusy}
                      onChange={(e) => { handleFiles(e.target.files); e.target.value = ''; }} />
                  </label>
                )}
              </div>
            ) : (
              <label className="flex flex-col items-center justify-center gap-1.5 p-5 rounded-2xl border-2 border-dashed border-brand-300 bg-brand-50/50 hover:bg-brand-50 cursor-pointer text-brand-700">
                <ImageIcon className="w-7 h-7" />
                <span className="text-xs font-bold">{imageBusy ? 'جاري تجهيز الصور…' : '📷 ارفع صور المنتج من جهازك'}</span>
                <span className="text-[10px] text-stone-500">تقدر تختار لحد {MAX_IMAGES} صور مرة واحدة — أول صورة هي اللي بتظهر في الكارت</span>
                <input type="file" accept="image/*" multiple className="hidden" disabled={imageBusy}
                  onChange={(e) => { handleFiles(e.target.files); e.target.value = ''; }} />
              </label>
            )}

            {/* Add by link */}
            {images.length < MAX_IMAGES && (
              <div className="mt-2 flex items-center gap-2">
                <input
                  type="url"
                  value={imageUrlInput}
                  onChange={(e) => setImageUrlInput(e.target.value)}
                  placeholder="أو حط لينك صورة https://..."
                  className="flex-1 min-w-0 px-3 py-2 rounded-xl border border-stone-300 text-xs font-mono"
                  dir="ltr"
                />
                <button
                  type="button"
                  disabled={!/^https?:\/\//.test(imageUrlInput.trim())}
                  onClick={() => { addImages([imageUrlInput.trim()]); setImageUrlInput(''); }}
                  className="px-3 py-2 rounded-xl bg-brand-700 text-white text-xs font-bold disabled:opacity-40 shrink-0"
                >
                  إضافة
                </button>
              </div>
            )}

            {images.length === 0 && (
              <div className="mt-2 flex items-center gap-2 overflow-x-auto pb-1">
                <span className="text-[10px] text-stone-400 shrink-0">أو صورة تجريبية:</span>
                {sampleImages.map((img, i) => (
                  <button type="button" key={i} onClick={() => addImages([img])}
                    className="w-10 h-10 rounded-lg overflow-hidden border border-stone-200 shrink-0">
                    <img src={img} alt="Preset" className="w-full h-full object-cover" />
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Description */}
          <div>
            <label className="block text-xs font-bold text-stone-700 mb-1">
              {t.descArLabel}
            </label>
            <textarea
              rows={2}
              value={descriptionAr}
              onChange={(e) => setDescriptionAr(e.target.value)}
              placeholder="وصف مميزات المنتج وشروط الحجز..."
              className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs"
            />
          </div>

          <div>
            <label className="block text-xs font-bold text-stone-700 mb-1">
              {t.featuresArLabel}
            </label>
            <textarea
              rows={3}
              value={featuresArText}
              onChange={(e) => setFeaturesArText(e.target.value)}
              placeholder="ميزة في كل سطر..."
              className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs"
            />
          </div>

          <div className="p-4 border-t border-stone-200 flex items-center justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl border border-stone-300 text-stone-700 text-xs font-bold"
            >
              {t.cancel}
            </button>
            <button
              type="submit"
              className="px-6 py-2 rounded-xl bg-brand-700 hover:bg-brand-800 text-white text-xs font-bold shadow"
            >
              {t.saveProduct}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
