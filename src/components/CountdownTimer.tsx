import React from 'react';
import { Timer, Hourglass, Lock } from 'lucide-react';
import { Language, Product } from '../types';
import { getBookingWindow, splitDuration, useNow } from '../utils/bookingWindow';

interface CountdownTimerProps {
  product: Product;
  language: Language;
  size?: 'sm' | 'lg';
}

// Live countdown for a product's booking window (until it opens, or until it closes)
export const CountdownTimer: React.FC<CountdownTimerProps> = ({ product, language, size = 'sm' }) => {
  const now = useNow(1000);
  const w = getBookingWindow(product, now);
  const ar = language === 'ar';
  if (w.state === 'none') return null;

  if (w.state === 'ended') {
    return (
      <div className="flex items-center justify-center gap-1.5 p-2 rounded-xl bg-stone-200 text-stone-600 text-xs font-bold">
        <Lock className="w-3.5 h-3.5" />
        <span>{ar ? 'انتهى وقت الحجز' : 'Booking time is over'}</span>
      </div>
    );
  }

  const { days, hours, minutes, seconds } = splitDuration(w.target!.getTime() - now);
  const upcoming = w.state === 'not_started';
  const urgent = !upcoming && w.target!.getTime() - now < 24 * 60 * 60 * 1000;
  const units = [
    { v: days, l: ar ? 'يوم' : 'd' },
    { v: hours, l: ar ? 'ساعة' : 'h' },
    { v: minutes, l: ar ? 'دقيقة' : 'm' },
    { v: seconds, l: ar ? 'ثانية' : 's' },
  ];
  const tone = upcoming
    ? 'bg-indigo-50 border-indigo-200 text-indigo-900'
    : urgent
      ? 'bg-red-50 border-red-200 text-red-900'
      : 'bg-brand-50 border-brand-200 text-brand-950';
  const box = upcoming ? 'bg-indigo-600' : urgent ? 'bg-red-600' : 'bg-brand-700';
  const Icon = upcoming ? Hourglass : Timer;
  const big = size === 'lg';

  return (
    <div className={`p-2.5 rounded-xl border ${tone}`}>
      <div className="flex items-center justify-center gap-1.5 text-[11px] font-extrabold mb-1.5">
        <Icon className={`w-3.5 h-3.5 ${urgent ? 'animate-pulse' : ''}`} />
        <span>{upcoming ? (ar ? 'الحجز يبدأ خلال' : 'Booking opens in') : (ar ? 'الحجز ينتهي خلال' : 'Booking ends in')}</span>
      </div>
      <div className="flex items-center justify-center gap-1.5" dir="ltr">
        {units.map((u, i) => (
          <div key={i} className={`${box} text-white rounded-lg text-center ${big ? 'w-14 py-1.5' : 'w-11 py-1'}`}>
            <div className={`font-mono font-black leading-none ${big ? 'text-xl' : 'text-base'}`}>{String(u.v).padStart(2, '0')}</div>
            <div className="text-[9px] opacity-80 mt-0.5">{u.l}</div>
          </div>
        ))}
      </div>
    </div>
  );
};
