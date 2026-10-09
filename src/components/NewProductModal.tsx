import React, { useState, useEffect } from 'react';
import { isoToLocalInput, localInputToIso } from '../utils/bookingWindow';
import { X, Plus, Trash2, Image as ImageIcon, Sparkles } from 'lucide-react';
import { Product, Language, ProductStatus } from '../types';
import { translations } from '../translations';

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
  if (!isOpen) return null;
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
  const [imageUrl, setImageUrl] = useState('');
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
      setImageUrl(productToEdit.images[0] || '');
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
      setImageUrl(sampleImages[0]);
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
      images: [imageUrl.trim() || sampleImages[0]],
      featuresAr: featuresList.length > 0 ? featuresList : ['ضمان شامل لمدة عام', 'مقدم بسيط والباقي عند الاستلام من الفرع'],
      featuresEn: featuresList.length > 0 ? featuresList : ['1-Year Comprehensive Warranty', 'Small deposit, rest paid at branch pickup'],
      createdAt: productToEdit ? productToEdit.createdAt : new Date().toISOString(),
    };

    onSave(product);
    onClose();
  };

  return (
    <div className="sheet-overlay">
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
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 rounded-2xl bg-amber-50/70 border border-amber-200">
            <div>
              <label className="block text-xs font-bold text-stone-900 mb-1">
                {t.priceLabel} ({currency}) *
              </label>
              <input
                type="number"
                min="1"
                value={price}
                onChange={(e) => setPrice(Number(e.target.value))}
                className="w-full px-3 py-2 rounded-xl border border-amber-300 bg-white font-bold text-stone-900 text-sm"
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

          {/* Image selection */}
          <div>
            <label className="block text-xs font-bold text-stone-700 mb-1">
              {t.imageUrlLabel}
            </label>
            <input
              type="text"
              value={imageUrl.startsWith('data:') ? '(صورة مرفوعة من الجهاز)' : imageUrl}
              onChange={(e) => setImageUrl(e.target.value)}
              placeholder="https://images.unsplash.com/..."
              className="w-full px-3 py-2 rounded-xl border border-stone-300 text-xs font-mono"
            />
            <label className="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-stone-900 text-amber-300 text-xs font-bold cursor-pointer hover:bg-stone-800">
              <span>📷 ارفع صورة من جهازك</span>
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (!file) return;
                  const reader = new FileReader();
                  reader.onload = () => {
                    const img = new Image();
                    img.onload = () => {
                      const max = 1000;
                      const scale = Math.min(1, max / Math.max(img.width, img.height));
                      const canvas = document.createElement('canvas');
                      canvas.width = Math.round(img.width * scale);
                      canvas.height = Math.round(img.height * scale);
                      canvas.getContext('2d')?.drawImage(img, 0, 0, canvas.width, canvas.height);
                      setImageUrl(canvas.toDataURL('image/jpeg', 0.82));
                    };
                    img.src = reader.result as string;
                  };
                  reader.readAsDataURL(file);
                }}
              />
            </label>
            {imageUrl.startsWith('data:') && (
              <img src={imageUrl} alt="" className="mt-2 w-24 h-24 rounded-xl object-cover border border-stone-200" />
            )}
            {/* Quick preset selector */}
            <div className="mt-2 flex items-center gap-2 overflow-x-auto pb-1">
              <span className="text-[10px] text-stone-400 shrink-0">معرض صور سريع:</span>
              {sampleImages.map((img, i) => (
                <button
                  type="button"
                  key={i}
                  onClick={() => setImageUrl(img)}
                  className={`w-10 h-10 rounded-lg overflow-hidden border shrink-0 ${
                    imageUrl === img ? 'ring-2 ring-amber-500 border-amber-500' : 'border-stone-200'
                  }`}
                >
                  <img src={img} alt="Preset" className="w-full h-full object-cover" />
                </button>
              ))}
            </div>
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
              className="px-6 py-2 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 text-xs font-bold shadow"
            >
              {t.saveProduct}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
