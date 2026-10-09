import React, { useState } from 'react';
import { Search } from 'lucide-react';
import { Language } from '../types';

interface TrackBoxProps {
  language: Language;
  onTrack: (codeOrPhone: string) => void;
}

// Compact "track your booking" bar shown at the top of the store page
export const TrackBox: React.FC<TrackBoxProps> = ({ language, onTrack }) => {
  const ar = language === 'ar';
  const [value, setValue] = useState('');
  const [error, setError] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!value.trim()) {
      setError(ar ? 'اكتب كود الحجز أو رقم موبايلك' : 'Enter your booking code or mobile number');
      return;
    }
    onTrack(value.trim());
  };

  return (
    <section className="bg-stone-900 border-b border-stone-800 px-4 py-4 sm:py-8">
      <form onSubmit={submit} className="max-w-xl mx-auto">
        <label htmlFor="track-input" className="block text-sm sm:text-base font-extrabold text-white mb-2.5 text-center">
          {ar ? 'تتبع حجزك' : 'Track your booking'}
        </label>
        <div className="flex items-stretch gap-2">
          <div className="relative flex-1 min-w-0">
            <Search className={`absolute top-1/2 -translate-y-1/2 w-4 h-4 text-stone-400 ${ar ? 'right-3.5' : 'left-3.5'}`} />
            <input
              id="track-input"
              type="text"
              value={value}
              onChange={(e) => { setValue(e.target.value); setError(''); }}
              placeholder={ar ? 'كود الحجز أو رقم الموبايل' : 'Booking code (RSV-123456) or mobile'}
              className={`w-full py-3 bg-stone-800 border border-stone-700 rounded-xl text-stone-100 placeholder-stone-400 focus:outline-none focus:ring-2 focus:ring-amber-400 text-sm ${ar ? 'pr-10 pl-3' : 'pl-10 pr-3'}`}
            />
          </div>
          <button type="submit" className="px-4 sm:px-5 rounded-xl bg-amber-400 hover:bg-amber-300 text-stone-950 font-extrabold text-sm shrink-0">
            {ar ? 'تتبع' : 'Track'}
          </button>
        </div>
        {error && <p className="mt-2 text-xs font-bold text-red-300 text-center">{error}</p>}
      </form>
    </section>
  );
};
