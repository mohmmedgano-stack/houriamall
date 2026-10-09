import React from 'react';
import { Language, MerchantSettings } from '../types';
import { whatsappLink } from '../utils/storage';

interface WhatsAppButtonProps {
  language: Language;
  merchantSettings: MerchantSettings;
}

// Floating button on every page that opens a WhatsApp chat with the store directly
export const WhatsAppButton: React.FC<WhatsAppButtonProps> = ({ language, merchantSettings }) => {
  const ar = language === 'ar';
  const href = whatsappLink(
    merchantSettings.whatsappNumber,
    ar ? 'مرحباً، عندي استفسار بخصوص الحجز' : 'Hello, I have a question about booking'
  );

  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={ar ? 'تواصل معنا على واتساب' : 'Chat with us on WhatsApp'}
      className="hidden md:flex fixed bottom-5 left-5 z-40 items-center gap-2 rounded-full bg-[#25D366] hover:bg-[#1ebe5b] text-white shadow-xl shadow-emerald-900/20 pl-3 pr-4 py-3 transition-transform hover:scale-105 print:hidden"
    >
      <svg viewBox="0 0 32 32" className="w-7 h-7 fill-current" aria-hidden="true">
        <path d="M16.04 3C9.4 3 4 8.38 4 15a11.9 11.9 0 0 0 1.62 6L4 29l8.2-1.58A12.06 12.06 0 0 0 16.04 27C22.66 27 28 21.62 28 15S22.66 3 16.04 3Zm0 21.8c-1.86 0-3.6-.5-5.1-1.38l-.36-.22-4.86.94.98-4.7-.24-.38A9.7 9.7 0 0 1 6.3 15c0-5.38 4.38-9.76 9.74-9.76 5.38 0 9.74 4.38 9.74 9.76 0 5.38-4.36 9.8-9.74 9.8Zm5.34-7.3c-.3-.14-1.74-.86-2-.96-.28-.1-.48-.14-.68.14-.2.3-.78.96-.96 1.16-.18.2-.36.22-.66.08-.3-.14-1.24-.46-2.36-1.46-.88-.78-1.46-1.74-1.64-2.04-.16-.3-.02-.46.14-.6.12-.12.3-.34.44-.5.14-.18.2-.3.3-.5.1-.2.04-.38-.02-.52-.08-.14-.68-1.64-.92-2.24-.24-.58-.5-.5-.68-.5h-.58c-.2 0-.52.08-.8.38-.28.3-1.04 1.02-1.04 2.5s1.08 2.9 1.22 3.1c.14.2 2.1 3.2 5.08 4.48.7.3 1.26.48 1.7.62.7.22 1.36.2 1.86.12.58-.08 1.74-.7 2-1.4.24-.68.24-1.28.18-1.4-.08-.12-.28-.2-.58-.34Z"/>
      </svg>
      <span className="text-sm font-extrabold hidden sm:inline">{ar ? 'كلمنا واتساب' : 'WhatsApp us'}</span>
    </a>
  );
};
