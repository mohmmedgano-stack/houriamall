import {createRoot} from 'react-dom/client';
import App from './App.tsx';
import './index.css';

// Any product image that fails to load gets a neutral placeholder instead of a broken icon
const PLACEHOLDER_IMG =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">' +
      '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#292524"/><stop offset="1" stop-color="#57534e"/></linearGradient></defs>' +
      '<rect width="800" height="600" fill="url(#g)"/>' +
      '<g fill="none" stroke="#fbbf24" stroke-width="14" stroke-linejoin="round" opacity="0.9">' +
      '<path d="M310 250h180l-14 170H324z"/><path d="M350 250v-22a50 50 0 0 1 100 0v22"/></g>' +
    '</svg>'
  );
document.addEventListener(
  'error',
  (e) => {
    const el = e.target as HTMLElement;
    if (el instanceof HTMLImageElement && el.dataset.fallback !== '1') {
      el.dataset.fallback = '1';
      el.src = PLACEHOLDER_IMG;
    }
  },
  true
);

createRoot(document.getElementById('root')!).render(<App />);
