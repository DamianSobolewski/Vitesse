/* ---------- indeks katalogu ---------- */
const IDX = {};
CATALOG.forEach(e => {
  (IDX[e.make] ??= {});
  (IDX[e.make][e.model] ??= {});
  IDX[e.make][e.model][e.gen] = e.engines;
});

const fill = (sel, items, placeholder) => {
  sel.innerHTML = '';
  const o = new Option(placeholder, '');
  o.disabled = true; o.selected = true;
  sel.add(o);
  items.forEach((it, i) => sel.add(new Option(typeof it === 'string' ? it : it.name, i)));
  sel.disabled = items.length === 0;
};

const pl = n => n.toLocaleString('pl-PL');

/* ---------- krzywa mocy (kierunek B) ---------- */
const SHAPE = {
  diesel:  [0.12, 0.38, 0.66, 0.86, 0.97, 1.00, 0.96, 0.86, 0.70],
  benzyna: [0.08, 0.22, 0.40, 0.58, 0.74, 0.88, 0.97, 1.00, 0.97],
};

function curvePoints(peak, fuel, maxY) {
  const s = SHAPE[fuel] || SHAPE.diesel;
  const x0 = 40, x1 = 510, y0 = 210, yTop = 14;
  return s.map((v, i) => [
    x0 + (x1 - x0) * i / (s.length - 1),
    y0 - (y0 - yTop) * (peak * v) / maxY,
  ]);
}

/* wygładzenie splajnem kardynalnym */
function smooth(pts) {
  if (pts.length < 2) return '';
  let d = `M ${pts[0][0].toFixed(1)} ${pts[0][1].toFixed(1)}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
    const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
    d += ` C ${c1[0].toFixed(1)} ${c1[1].toFixed(1)}, ${c2[0].toFixed(1)} ${c2[1].toFixed(1)}, ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`;
  }
  return d;
}

function drawChart(root, eng) {
  const maxY = Math.ceil(eng.chip_hp * 1.12 / 10) * 10;
  const stock = curvePoints(eng.stock_hp, eng.fuel, maxY);
  const tuned = curvePoints(eng.chip_hp, eng.fuel, maxY);
  const q = f => root.querySelector(`[data-f="${f}"]`);

  q('stock').setAttribute('d', smooth(stock));
  q('tuned').setAttribute('d', smooth(tuned));
  q('area').setAttribute('d', smooth(tuned) + ' L ' + smooth(stock.slice().reverse()).slice(1) + ' Z');
  q('ax1').textContent = maxY;
  q('ax75').textContent = Math.round(maxY * 0.75);

  // animacja rysowania
  const t = q('tuned'), len = t.getTotalLength();
  if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
    t.style.transition = 'none';
    t.style.strokeDasharray = len; t.style.strokeDashoffset = len;
    requestAnimationFrame(() => {
      t.style.transition = 'stroke-dashoffset .85s cubic-bezier(.2,.7,.3,1)';
      t.style.strokeDashoffset = 0;
    });
  }
}

/* ---------- wyszukiwarka: kaskada + bramka ---------- */
document.querySelectorAll('[data-ps]').forEach(ps => {
  const root  = ps.closest('.mock');
  const kind  = ps.dataset.ps;
  const S     = k => ps.querySelector(`[data-sel="${k}"]`);
  const F     = k => root.querySelector(`[data-f="${k}"]`);
  const gate  = root.querySelector('[data-gate]');
  const out   = ps.querySelector('[data-out]');
  let current = null;

  const makes = Object.keys(IDX);
  fill(S('make'), makes, 'Marka');
  ['model', 'gen', 'eng'].forEach(k => fill(S(k), [], { model: 'Model', gen: 'Generacja', eng: 'Silnik' }[k]));

  const reset = from => {
    const order = ['model', 'gen', 'eng'];
    order.slice(order.indexOf(from)).forEach(k =>
      fill(S(k), [], { model: 'Model', gen: 'Generacja', eng: 'Silnik' }[k]));
    out?.classList.remove('on');
    root.querySelectorAll('.lock').forEach(c => c.classList.add('hid'));
    gate?.classList.remove('done');
  };

  S('make').onchange = e => {
    reset('model');
    fill(S('model'), Object.keys(IDX[makes[e.target.value]]), 'Model');
  };
  S('model').onchange = e => {
    reset('gen');
    const m = IDX[makes[S('make').value]];
    fill(S('gen'), Object.keys(m[Object.keys(m)[e.target.value]]), 'Generacja');
  };
  S('gen').onchange = e => {
    reset('eng');
    const m  = IDX[makes[S('make').value]];
    const mk = m[Object.keys(m)[S('model').value]];
    fill(S('eng'), mk[Object.keys(mk)[e.target.value]], 'Silnik');
  };
  S('eng').onchange = e => {
    const m  = IDX[makes[S('make').value]];
    const mk = m[Object.keys(m)[S('model').value]];
    const gs = mk[Object.keys(mk)[S('gen').value]];
    current  = gs[e.target.value];
    show(current);
  };

  function show(en) {
    root.querySelectorAll('.lock').forEach(c => c.classList.add('hid'));
    gate?.classList.remove('done');

    if (kind === 'a') {
      out.classList.add('on');
      F('shp').textContent = en.stock_hp + ' KM';
      F('snm').textContent = en.stock_nm + ' Nm';
      F('thp').textContent = en.chip_hp + ' KM';
      F('tnm').textContent = en.chip_nm + ' Nm';
      F('teaser').textContent = `${en.name} · zysk mocy i momentu`;
    } else {
      F('title').textContent = en.name;
      F('dhp').textContent = '+' + (en.chip_hp - en.stock_hp) + ' KM';
      F('dnm').textContent = '+' + (en.chip_nm - en.stock_nm) + ' Nm';
      drawChart(root, en);
    }
  }

  gate?.querySelector('[data-submit]')?.addEventListener('click', () => {
    const input = gate.querySelector('input');
    if (!input.checkValidity() || !input.value) { input.focus(); input.reportValidity(); return; }
    root.querySelectorAll('.lock').forEach(c => c.classList.remove('hid'));
    gate.classList.add('done');
  });
});

/* ---------- kalkulator flotowy (kierunek C) ---------- */
document.querySelectorAll('[data-calc]').forEach(calc => {
  const SAVING = 0.065;          // 6,5% — środek deklarowanego zakresu 5–8%
  const PRICE_PER_VEHICLE = 1500; // zł netto, docelowo opcja w panelu
  const get = k => parseFloat(calc.querySelector(`[data-num="${k}"]`).value) || 0;
  const put = (k, v) => calc.querySelector(`[data-c="${k}"]`).textContent = v;

  function calcAll() {
    const veh = get('veh'), km = get('km'), fu = get('fu'), pr = get('pr');
    const litres = veh * (km / 100) * fu * SAVING;
    const year = litres * pr;
    const month = year / 12;
    const payback = month > 0 ? Math.ceil((PRICE_PER_VEHICLE * veh) / month) : 0;

    put('year', pl(Math.round(year)) + ' zł');
    put('month', pl(Math.round(month)) + ' zł');
    put('litres', pl(Math.round(litres)) + ' l');
    put('payback', payback > 0 && payback < 120 ? payback + ' mies.' : '—');
  }

  calc.querySelectorAll('[data-rng]').forEach(rng => {
    const num = calc.querySelector(`[data-num="${rng.dataset.rng}"]`);
    rng.addEventListener('input', () => { num.value = rng.value; calcAll(); });
    num.addEventListener('input', () => { rng.value = num.value; calcAll(); });
  });
  calcAll();
});
