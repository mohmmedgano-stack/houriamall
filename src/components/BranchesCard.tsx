import React from 'react';
import { MapPin, Phone } from 'lucide-react';
import { MerchantSettings } from '../types';

interface BranchesCardProps {
  merchantSettings: MerchantSettings;
}

// Branch addresses + contact numbers (editable from the admin dashboard)
export const BranchesCard: React.FC<BranchesCardProps> = ({ merchantSettings }) => {
  const branches = (merchantSettings.branches || []).filter(b => b.name.trim() || b.address.trim());
  const phones = (merchantSettings.branchPhones || []).filter(Boolean);
  if (branches.length === 0 && phones.length === 0) return null;

  return (
    <div dir="rtl" className="p-4 rounded-2xl bg-white border border-stone-200 space-y-3">
      <h4 className="text-sm font-black text-stone-900">{merchantSettings.branchesTitleAr || 'عنوان فروعنا'}</h4>

      <ul className="space-y-2.5">
        {branches.map((b, i) => (
          <li key={i} className="flex items-start gap-2.5">
            <span className="mt-0.5 w-7 h-7 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
              <MapPin className="w-4 h-4" />
            </span>
            <div className="min-w-0">
              <div className="text-xs font-extrabold text-stone-900">{b.name}</div>
              <div className="text-xs text-stone-600 leading-relaxed">{b.address}</div>
            </div>
          </li>
        ))}
      </ul>

      {phones.length > 0 && (
        <div className="pt-3 border-t border-stone-100 flex flex-wrap items-center gap-2">
          <Phone className="w-4 h-4 text-emerald-600 shrink-0" />
          {phones.map((ph) => (
            <a
              key={ph}
              href={`tel:${ph.replace(/[^0-9+]/g, '')}`}
              className="px-2.5 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold font-mono dir-ltr select-all"
            >
              {ph}
            </a>
          ))}
        </div>
      )}
    </div>
  );
};
