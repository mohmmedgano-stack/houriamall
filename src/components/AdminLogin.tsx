import React, { useState } from 'react';
import { Lock, User, ShoppingBag, ArrowRight, Eye, EyeOff } from 'lucide-react';
import { MerchantSettings } from '../types';

interface AdminLoginProps {
  merchantSettings: MerchantSettings;
  onSuccess: () => void;
  onBackToSite: () => void;
}

// Login portal for the private admin dashboard (username + password)
export const AdminLogin: React.FC<AdminLoginProps> = ({ merchantSettings, onSuccess, onBackToSite }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!username.trim() || !password) {
      setError('اكتب اسم المستخدم والباسورد');
      return;
    }
    if (username.trim().toLowerCase() === merchantSettings.adminUsername.trim().toLowerCase() && password === merchantSettings.adminPassword) {
      onSuccess();
    } else {
      setError('اسم المستخدم أو الباسورد غلط، جرّب تاني.');
    }
  };

  return (
    <div dir="rtl" className="min-h-screen flex items-center justify-center bg-gradient-to-b from-stone-900 to-stone-950 px-4 py-10">
      <form onSubmit={handleSubmit} className="w-full max-w-sm bg-white rounded-3xl shadow-2xl p-6 sm:p-8 space-y-4">
        <div className="text-center pb-1">
          <div className="w-14 h-14 rounded-2xl bg-stone-900 flex items-center justify-center mx-auto">
            <ShoppingBag className="w-7 h-7 text-amber-400" />
          </div>
          <h1 className="mt-3 text-xl font-black text-stone-900">{merchantSettings.storeNameAr}</h1>
          <p className="text-xs text-stone-500 mt-1">تسجيل الدخول للوحة التحكم</p>
        </div>

        {error && (
          <div role="alert" className="p-2.5 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs font-bold">
            {error}
          </div>
        )}

        <div>
          <label htmlFor="admin-username" className="block text-xs font-bold text-stone-700 mb-1">اسم المستخدم</label>
          <div className="relative">
            <User className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-stone-400" />
            <input
              id="admin-username"
              type="text"
              autoComplete="username"
              autoCapitalize="none"
              autoCorrect="off"
              spellCheck={false}
              autoFocus
              value={username}
              onChange={(e) => { setUsername(e.target.value); setError(''); }}
              className="w-full pr-10 pl-3 py-3 rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 text-sm dir-ltr text-left"
              placeholder="اسم المستخدم"
            />
          </div>
        </div>

        <div>
          <label htmlFor="admin-password" className="block text-xs font-bold text-stone-700 mb-1">الباسورد</label>
          <div className="relative">
            <Lock className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-stone-400" />
            <input
              id="admin-password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              value={password}
              onChange={(e) => { setPassword(e.target.value); setError(''); }}
              className="w-full pr-10 pl-11 py-3 rounded-xl border border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-900 text-sm dir-ltr text-left"
              placeholder="••••••"
            />
            <button
              type="button"
              onClick={() => setShowPassword(v => !v)}
              aria-label={showPassword ? 'إخفاء الباسورد' : 'إظهار الباسورد'}
              className="absolute left-2 top-1/2 -translate-y-1/2 p-1.5 text-stone-400 hover:text-stone-700"
            >
              {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
          </div>
        </div>

        <button type="submit" className="w-full py-3 rounded-xl bg-stone-900 hover:bg-stone-800 text-amber-300 font-extrabold text-sm">
          تسجيل الدخول
        </button>

        <button type="button" onClick={onBackToSite} className="w-full text-xs text-stone-500 hover:text-stone-900 flex items-center justify-center gap-1 pt-1">
          <ArrowRight className="w-3.5 h-3.5" />
          <span>رجوع للموقع</span>
        </button>
      </form>
    </div>
  );
};
