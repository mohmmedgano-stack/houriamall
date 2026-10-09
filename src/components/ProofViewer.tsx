import React, { useState } from 'react';
import { X, ZoomIn, ZoomOut, Download, ExternalLink } from 'lucide-react';
import { useEscapeKey } from '../utils/useDismiss';

interface ProofViewerProps {
  url: string | null;
  title?: string;
  subtitle?: string;
  onClose: () => void;
}

/** Full-screen viewer for the customer's transfer screenshot. Tap outside the image or press Esc to close. */
export const ProofViewer: React.FC<ProofViewerProps> = ({ url, title, subtitle, onClose }) => {
  const [zoomed, setZoomed] = useState(false);
  const [failed, setFailed] = useState(false);
  useEscapeKey(onClose, Boolean(url));
  if (!url) return null;

  const close = () => { setZoomed(false); setFailed(false); onClose(); };
  const btn = 'w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center';

  return (
    <div className="fixed inset-0 z-[120] bg-black/90 flex flex-col" onClick={close} dir="rtl">
      <div className="flex items-center justify-between gap-3 px-4 py-3 text-white" onClick={e => e.stopPropagation()}>
        <div className="min-w-0">
          <div className="font-bold text-sm truncate">{title || 'صورة التحويل'}</div>
          {subtitle && <div className="text-[11px] text-white/60 truncate">{subtitle}</div>}
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button type="button" className={btn} onClick={() => setZoomed(z => !z)} title={zoomed ? 'تصغير' : 'تكبير'}>
            {zoomed ? <ZoomOut className="w-5 h-5" /> : <ZoomIn className="w-5 h-5" />}
          </button>
          <a className={btn} href={url} download target="_blank" rel="noopener noreferrer" title="تحميل الصورة"><Download className="w-5 h-5" /></a>
          <a className={btn} href={url} target="_blank" rel="noopener noreferrer" title="فتح في صفحة جديدة"><ExternalLink className="w-5 h-5" /></a>
          <button type="button" className={btn} onClick={close} title="إغلاق" aria-label="إغلاق"><X className="w-5 h-5" /></button>
        </div>
      </div>
      <div className={`flex-1 min-h-0 p-3 ${zoomed ? 'overflow-auto' : 'flex items-center justify-center overflow-hidden'}`}>
        {failed ? (
          <div className="m-auto text-center text-white/80 text-sm" onClick={e => e.stopPropagation()}>
            الصورة مش راضية تفتح. جرّب "فتح في صفحة جديدة" أو اعمل تحديث للوحة التحكم.
          </div>
        ) : (
          <img
            src={url}
            alt="Transfer proof"
            onError={() => setFailed(true)}
            onClick={e => { e.stopPropagation(); setZoomed(z => !z); }}
            className={zoomed ? 'max-w-none w-[200%] sm:w-auto cursor-zoom-out mx-auto' : 'max-w-full max-h-full object-contain rounded-lg cursor-zoom-in shadow-2xl'}
          />
        )}
      </div>
      <div className="text-center text-[11px] text-white/50 pb-3">اضغط على الصورة للتكبير — اضغط برّه الصورة للإغلاق</div>
    </div>
  );
};
