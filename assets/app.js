// Media Buying Command Center — small progressive enhancements (pages work without JS)
(function () {
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };

  // theme: auto → dark → light
  const root = document.documentElement;
  const applyTheme = t => { if (t === 'auto') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', t); };
  applyTheme(store.get('mbcc.theme', 'auto'));
  document.addEventListener('click', e => {
    const tb = e.target.closest('[data-theme-toggle]');
    if (tb) {
      const order = ['auto', 'dark', 'light'];
      const next = order[(order.indexOf(store.get('mbcc.theme', 'auto')) + 1) % 3];
      store.set('mbcc.theme', next); applyTheme(next);
      tb.title = { auto: 'المظهر: تلقائي', dark: 'المظهر: داكن', light: 'المظهر: فاتح' }[next];
      document.dispatchEvent(new Event('themechange'));
    }
    if (e.target.closest('[data-nav-toggle]') || e.target.classList.contains('scrim')) document.body.classList.toggle('nav-open');
    const cp = e.target.closest('.colpick > button');
    if (cp) { cp.parentElement.classList.toggle('open'); e.preventDefault(); }
    else if (!e.target.closest('.colpick')) document.querySelectorAll('.colpick.open').forEach(x => x.classList.remove('open'));
  });

  // confirm dangerous actions
  document.addEventListener('submit', e => {
    const m = e.target.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
    if (m && !confirm(m)) e.preventDefault();
  });
  // filters that submit on change
  document.querySelectorAll('form[data-autosubmit] select, form[data-autosubmit] input[type=date]').forEach(el =>
    el.addEventListener('change', () => el.form.requestSubmit()));

  // column show/hide, remembered per table
  document.querySelectorAll('[data-cols-for]').forEach(picker => {
    const table = document.getElementById(picker.getAttribute('data-cols-for'));
    if (!table) return;
    const key = 'mbcc.cols.' + table.id;
    const hidden = new Set(store.get(key, table.dataset.defaultHidden ? table.dataset.defaultHidden.split(',') : []));
    const apply = () => table.querySelectorAll('[data-col]').forEach(c => { c.style.display = hidden.has(c.dataset.col) ? 'none' : ''; });
    const menu = picker.querySelector('.menu');
    const seen = new Set();
    table.querySelectorAll('thead [data-col]').forEach(th => {
      const c = th.dataset.col; if (seen.has(c)) return; seen.add(c);
      const l = document.createElement('label');
      l.innerHTML = '<input type="checkbox"> <span></span>';
      l.querySelector('span').textContent = th.dataset.label || th.textContent.trim();
      const cb = l.querySelector('input'); cb.checked = !hidden.has(c);
      cb.addEventListener('change', () => { cb.checked ? hidden.delete(c) : hidden.add(c); store.set(key, [...hidden]); apply(); });
      menu.appendChild(l);
    });
    apply();
  });

  // client-side quick search over a table
  document.querySelectorAll('[data-search-for]').forEach(inp => {
    const t = document.getElementById(inp.getAttribute('data-search-for'));
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      t.querySelectorAll('tbody tr').forEach(tr => { tr.style.display = !q || tr.textContent.toLowerCase().includes(q) ? '' : 'none'; });
    });
  });

  // charts: <canvas data-chart='{"type":"line","labels":[...],"series":[{"name":"","data":[],"axis":"y"}]}'>
  const css = n => getComputedStyle(root).getPropertyValue(n).trim();
  const palette = () => [css('--acc'), css('--acc2'), css('--info'), css('--warn'), css('--ok'), css('--bad')];
  const charts = [];
  function draw() {
    if (!window.Chart) return;
    charts.forEach(c => c.destroy()); charts.length = 0;
    document.querySelectorAll('canvas[data-chart]').forEach(cv => {
      let spec; try { spec = JSON.parse(cv.dataset.chart); } catch (e) { return; }
      const pal = palette(), grid = css('--line'), ink = css('--mute');
      const axes = {};
      const datasets = spec.series.map((s, i) => {
        const ax = s.axis || 'y'; axes[ax] = s.axisLabel || axes[ax] || '';
        return { label: s.name, data: s.data, yAxisID: ax, type: s.type || spec.type,
          borderColor: pal[i % pal.length], backgroundColor: (s.type || spec.type) === 'bar' ? pal[i % pal.length] : pal[i % pal.length] + '22',
          borderWidth: 2, pointRadius: spec.labels.length > 40 ? 0 : 2, tension: .3, fill: false, spanGaps: false, borderRadius: 4 };
      });
      const scales = { x: { grid: { color: grid }, ticks: { color: ink, maxRotation: 0, autoSkip: true } } };
      Object.keys(axes).forEach((ax, i) => {
        scales[ax] = { position: i === 0 ? 'right' : 'left', grid: { color: i === 0 ? grid : 'transparent' }, ticks: { color: ink },
          title: { display: !!axes[ax], text: axes[ax], color: ink }, beginAtZero: true };
      });
      charts.push(new Chart(cv, { type: spec.type, data: { labels: spec.labels, datasets },
        options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, locale: 'en',
          plugins: { legend: { labels: { color: ink, boxWidth: 12 } }, tooltip: { rtl: true } }, scales } }));
    });
  }
  if (document.querySelector('canvas[data-chart]')) {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
    s.onload = draw; document.head.appendChild(s);
    document.addEventListener('themechange', draw);
  }
})();
