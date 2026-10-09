import React, { useRef, useState } from 'react';
import { useClickOutside } from '../utils/useDismiss';
import { Bell, Volume2, VolumeX, ExternalLink, LogOut } from 'lucide-react';
import { NotificationItem, MerchantSettings } from '../types';
import { formatDate } from '../utils/storage';

interface AdminHeaderProps {
  merchantSettings: MerchantSettings;
  notifications: NotificationItem[];
  unreadCount: number;
  onMarkNotificationRead: (id: string) => void;
  onMarkAllNotificationsRead: () => void;
  onOpenReservation: (code: string) => void;
  onToggleSound: () => void;
  onViewSite: () => void;
  onLogout: () => void;
}

// Header for the private admin dashboard (notifications, sound alerts, logout)
export const AdminHeader: React.FC<AdminHeaderProps> = ({
  merchantSettings,
  notifications,
  unreadCount,
  onMarkNotificationRead,
  onMarkAllNotificationsRead,
  onOpenReservation,
  onToggleSound,
  onViewSite,
  onLogout,
}) => {
  const [open, setOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);
  useClickOutside(dropdownRef, () => setOpen(false), open);

  return (
    <header className="sticky top-0 z-40 bg-brand-700 text-white border-b border-brand-800">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2.5 min-w-0">
          <img src="/logo-white.png" alt="Houria حورية" className="h-10 w-auto shrink-0" />
          <div className="min-w-0">
            <div className="font-extrabold text-base truncate">{merchantSettings.storeNameAr}</div>
            <div className="text-[10px] text-brand-300 font-bold">لوحة التحكم</div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={onToggleSound}
            title={merchantSettings.soundAlertEnabled ? 'صوت التنبيهات شغال' : 'صوت التنبيهات مقفول'}
            className={`p-2 rounded-lg border ${merchantSettings.soundAlertEnabled ? 'border-emerald-500/40 text-emerald-300 bg-emerald-500/10' : 'border-white/25 text-stone-400'}`}
          >
            {merchantSettings.soundAlertEnabled ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
          </button>

          <div className="relative" ref={dropdownRef}>
            <button onClick={() => setOpen(!open)} className="relative p-2 rounded-lg border border-white/25 hover:bg-brand-800" aria-label="التنبيهات">
              <Bell className="w-4 h-4" />
              {unreadCount > 0 && (
                <span className="absolute -top-1 -right-1 h-4 min-w-4 px-1 rounded-full bg-red-600 text-[10px] font-bold flex items-center justify-center">
                  {unreadCount}
                </span>
              )}
            </button>
            {open && (
              <div className="absolute left-0 top-full mt-2 w-80 bg-white text-stone-900 rounded-2xl shadow-xl border border-stone-200 z-50 overflow-hidden">
                <div className="flex items-center justify-between p-3 bg-stone-100 border-b border-stone-200">
                  <span className="font-bold text-sm">التنبيهات</span>
                  {unreadCount > 0 && (
                    <button onClick={onMarkAllNotificationsRead} className="text-xs text-stone-600 underline">
                      تحديد الكل كمقروء
                    </button>
                  )}
                </div>
                <div className="max-h-80 overflow-y-auto divide-y divide-stone-100">
                  {notifications.length === 0 ? (
                    <div className="p-6 text-center text-sm text-stone-400">مفيش تنبيهات جديدة</div>
                  ) : (
                    notifications.slice(0, 15).map((n) => (
                      <button
                        key={n.id}
                        onClick={() => {
                          onMarkNotificationRead(n.id);
                          setOpen(false);
                          onOpenReservation(n.reservationCode);
                        }}
                        className={`w-full text-right p-3 hover:bg-stone-50 ${!n.isRead ? 'bg-brand-50/60' : ''}`}
                      >
                        <div className="flex justify-between gap-2">
                          <span className="font-bold text-xs">{n.titleAr}</span>
                          <span className="text-[10px] text-stone-400 whitespace-nowrap">{formatDate(n.timestamp, 'ar')}</span>
                        </div>
                        <p className="text-xs text-stone-600 mt-1 line-clamp-2">{n.messageAr}</p>
                        <span className="mt-1 inline-block font-mono text-[11px] font-bold text-emerald-700">{n.reservationCode}</span>
                      </button>
                    ))
                  )}
                </div>
              </div>
            )}
          </div>

          <button onClick={onViewSite} className="hidden sm:flex items-center gap-1.5 px-3 py-2 rounded-lg border border-white/25 hover:bg-brand-800 text-xs font-bold">
            <ExternalLink className="w-3.5 h-3.5" />
            <span>عرض الموقع</span>
          </button>
          <button onClick={onLogout} className="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-brand-900 hover:bg-brand-950 text-xs font-bold text-brand-200">
            <LogOut className="w-3.5 h-3.5" />
            <span>خروج</span>
          </button>
        </div>
      </div>
    </header>
  );
};
