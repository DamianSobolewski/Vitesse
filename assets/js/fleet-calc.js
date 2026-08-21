/* Kalkulator oszczędności flotowych. Liczy po stronie klienta — suwak musi
   odpowiadać w tej samej klatce, żaden request tego nie da. */
(function () {
  'use strict';
  var fmt = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 0 });

  document.querySelectorAll('[data-vts-calc]').forEach(function (root) {
    var cfg = JSON.parse(root.dataset.cfg);
    var num = function (k) { return root.querySelector('[data-num="' + k + '"]'); };
    var put = function (k, v) { root.querySelector('[data-c="' + k + '"]').textContent = v; };

    function recalc() {
      var veh = parseFloat(num('veh').value) || 0;
      var km  = parseFloat(num('km').value)  || 0;
      var fu  = parseFloat(num('fu').value)  || 0;
      var pr  = parseFloat(num('pr').value)  || 0;

      var litres  = veh * (km / 100) * fu * (cfg.saving_pct / 100);
      var year    = litres * pr;
      var month   = year / 12;
      var payback = month > 0 ? Math.ceil((cfg.price_per_car * veh) / month) : 0;

      put('year',   fmt.format(Math.round(year)) + ' zł');
      put('month',  fmt.format(Math.round(month)) + ' zł');
      put('litres', fmt.format(Math.round(litres)) + ' l');
      put('co2',    fmt.format(Math.round(litres * cfg.co2_per_l)) + ' kg');
      put('payback', payback > 0 && payback <= 120 ? payback + ' mies.' : '—');
    }

    root.querySelectorAll('[data-rng]').forEach(function (rng) {
      var input = num(rng.dataset.rng);
      rng.addEventListener('input', function () { input.value = rng.value; recalc(); });
      input.addEventListener('input', function () { rng.value = input.value; recalc(); });
    });

    recalc();
  });
})();
