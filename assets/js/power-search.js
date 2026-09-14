/* Wyszukiwarka mocy — kaskada Marka → Model → Generacja → Silnik i wynik.
 *
 * Wynik przychodzi z /catalog/result po kliknięciu przycisku. Bramki e-mail
 * już nie ma (decyzja klienta, wrzesień 2026): wartości po modyfikacji pokazują
 * się od razu, tak jak w konfiguratorze V-techa. Token z listy silników i limit
 * zapytań po stronie serwera zostają jako hamulec na skrypty, nie na ludzi.
 */
(function () {
  'use strict';

  var motionOK = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  var PUSTE = '– – –';

  /* Odliczanie liczby na ekranie. Przy wyłączonym ruchu wpisujemy od razu
     wartość końcową — nigdy przypadkową klatkę. */
  function licz(el, od, doo, sufiks, prefiks) {
    var koniec = (prefiks || '') + doo + (sufiks || '');
    if (!motionOK || od === doo) { el.textContent = koniec; return; }

    var start = null, czas = 700;
    var krok = function (t) {
      if (start === null) start = t;
      var p = Math.min(1, (t - start) / czas);
      var e = 1 - Math.pow(1 - p, 3);              // wyhamowanie na końcu
      el.textContent = (prefiks || '') + Math.round(od + (doo - od) * e) + (sufiks || '');
      if (p < 1) requestAnimationFrame(krok); else el.textContent = koniec;
    };
    requestAnimationFrame(krok);
  }

  document.querySelectorAll('[data-vts-ps]').forEach(function (root) {
    var api    = root.dataset.rest.replace(/\/$/, '');
    var sel    = function (k) { return root.querySelector('[data-sel="' + k + '"]'); };
    var field  = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
    var out    = root.querySelector('[data-out]');
    var full   = root.querySelector('[data-full]');
    var note   = root.querySelector('[data-note]');
    var errBox = root.querySelector('[data-err]');
    var cta    = root.querySelector('[data-cta]');
    var goKat  = root.querySelector('[data-go-catalog]');
    var goKon  = root.querySelector('[data-go-contact]');

    var token = null, engine = null, stockHp = 0, vin = '';

    function reset(select, placeholder) {
      select.innerHTML = '';
      var o = new Option(placeholder, '');
      o.disabled = true; o.selected = true;
      select.add(o);
      select.disabled = true;
    }

    function populate(select, items, placeholder, label, value) {
      reset(select, placeholder);
      items.forEach(function (it) { select.add(new Option(label(it), value(it))); });
      select.disabled = items.length === 0;
    }

    function get(path, params) {
      var url = new URL(api + path, location.origin);
      Object.keys(params || {}).forEach(function (k) { url.searchParams.set(k, params[k]); });
      return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw new Error(b && b.message ? b.message : 'HTTP ' + r.status);
          return b;
        });
      });
    }

    /* Chowa wynik i wyłącza przycisk — po każdej zmianie w kaskadzie. */
    function zgas() {
      out.hidden = true;
      errBox.hidden = true;
      full.innerHTML = '';
      engine = null; token = null; stockHp = 0;
      if (cta) { cta.disabled = true; }
    }

    sel('make').addEventListener('change', function (e) {
      zgas();
      reset(sel('gen'), 'Generacja'); reset(sel('eng'), 'Silnik');
      get('/catalog/models', { make: e.target.value }).then(function (rows) {
        populate(sel('model'), rows, 'Model', function (r) { return r.name; }, function (r) { return r.id; });
      });
    });

    sel('model').addEventListener('change', function (e) {
      zgas();
      reset(sel('eng'), 'Silnik');
      get('/catalog/generations', { model: e.target.value }).then(function (rows) {
        populate(sel('gen'), rows, 'Generacja', function (r) { return r.name; }, function (r) { return r.id; });
      });
    });

    sel('gen').addEventListener('change', function (e) {
      zgas();
      get('/catalog/engines', { generation: e.target.value }).then(function (rows) {
        populate(sel('eng'), rows, 'Silnik',
          function (r) { return r.name + ' · ' + r.stock_hp + ' KM'; },
          function (r) { return r.id; });
        sel('eng').__rows = rows;
      });
    });

    sel('eng').addEventListener('change', function (e) {
      var rows = sel('eng').__rows || [];
      var row  = rows.filter(function (r) { return String(r.id) === e.target.value; })[0];
      zgas();
      if (!row) return;
      engine  = row.id;
      token   = row.token;
      stockHp = row.stock_hp || 0;
      if (cta) { cta.disabled = false; cta.focus({ preventScroll: true }); }
    });

    /* Górny rząd: VIN (tylko przy włączonej fladze). Endpoint zwraca markę i rok —
       trafienie ustawia pierwszy select i wyzwala tę samą kaskadę co klik. */
    var vinBox = root.querySelector('[data-vin]');
    var vinInput = root.querySelector('[data-vin-input]');
    if (vinBox && vinInput) {
      var vinMsg  = root.querySelector('[data-vin-msg]');
      var vinGo   = root.querySelector('[data-vin-go]');
      var vinAuto = false;

      var vinPokaz = function (tekst, ok) {
        vinMsg.textContent = tekst;
        vinMsg.hidden = !tekst;
        vinMsg.classList.toggle('is-ok', !!ok);
      };

      var vinSzukaj = function () {
        var v = (vinInput.value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        vinInput.value = v;
        if (!v) { vinPokaz('', false); return; }
        if (v.length !== 17) { vinPokaz('Numer VIN ma 17 znaków, wpisano ' + v.length + '.', false); return; }

        vinGo.disabled = true;
        get('/catalog/vin', { vin: v })
          .then(function (res) {
            vinPokaz(res.message, res.ok);
            if (!res.ok || !res.make) { sel('make').focus(); return; }
            vinAuto = true;
            sel('make').value = res.make.slug;
            sel('make').dispatchEvent(new Event('change'));
            vinAuto = false;
            vin = v;
            sel('model').focus();
          })
          .catch(function () { vinPokaz('Nie udało się sprawdzić numeru. Wybierz pojazd z list poniżej.', false); })
          .finally(function () { vinGo.disabled = false; });
      };

      vinGo.addEventListener('click', vinSzukaj);
      vinInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); vinSzukaj(); }
      });
      sel('make').addEventListener('change', function () { if (!vinAuto) { vin = ''; } });
    }

    function fail(msg) {
      errBox.textContent = msg;
      errBox.hidden = false;
      out.hidden = false;
    }

    /* Przycisk: pobiera wynik i rozkłada go na ekranie. */
    if (cta) {
      cta.addEventListener('click', function () {
        if (!engine || !token) { return; }
        cta.disabled = true;
        var etykieta = cta.textContent;
        cta.textContent = 'Sprawdzam…';

        get('/catalog/result', { engine: engine, token: token })
          .then(pokaz)
          .catch(function (err) { fail(err.message); })
          .finally(function () { cta.disabled = false; cta.textContent = etykieta; });
      });
    }

    function pokaz(data) {
      errBox.hidden = true;
      full.innerHTML = '';

      field('veh').textContent = data.vehicle;

      if (data.stock_hp) { licz(field('shp'), 0, data.stock_hp, ' KM'); }
      else { field('shp').textContent = PUSTE; }

      // V-tech nie podaje momentu fabrycznego dla każdej wersji. Wartość słowna
      // w miejscu liczby dostaje własny, mniejszy krój — inaczej napis wychodzi
      // na sąsiednią kolumnę.
      var kom = field('snm').parentElement;
      kom.classList.toggle('is-text', !data.stock_nm);
      if (data.stock_nm) { licz(field('snm'), 0, data.stock_nm, ' Nm'); }
      else { field('snm').textContent = 'brak danych'; }

      var warianty = data.results || [];
      var naj = warianty.filter(function (r) {
        return data.best && r.label === data.best.label;
      })[0] || warianty[0];

      if (naj) {
        if (naj.tuned_hp) { licz(field('thp'), stockHp || 0, naj.tuned_hp, ' KM'); }
        else { field('thp').textContent = PUSTE; }
        licz(field('ghp'), 0, naj.gain_hp, ' KM', '+');
      } else {
        field('thp').textContent = PUSTE;
        field('ghp').textContent = PUSTE;
      }

      full.innerHTML = warianty.map(function (r) {
        var moc = '<span>moc <b>+' + r.gain_hp + ' KM</b>' +
                  (r.tuned_hp ? ' <em>→ ' + r.tuned_hp + '</em>' : '') + '</span>';
        var mom = r.gain_nm ? '<span>moment <b>+' + r.gain_nm + ' Nm</b></span>' : '';
        return '<div class="vts-ps__srv"><h4>' + r.label + '</h4>' +
               '<div class="vts-ps__srv-v">' + moc + mom + '</div></div>';
      }).join('');

      note.textContent = data.note || '';

      if (goKat && data.url) { goKat.href = data.url; }
      if (goKon) {
        var u = new URL(root.dataset.contact || goKon.href, location.origin);
        u.searchParams.set('vehicle', data.vehicle.replace(' · ', ' '));
        if (vin) { u.searchParams.set('vin', vin); }
        goKon.href = u.toString();
      }

      out.hidden = false;
      out.scrollIntoView({ behavior: motionOK ? 'smooth' : 'auto', block: 'nearest' });

      if (window.dataLayer) {
        window.dataLayer.push({ event: 'power_search_result', vehicle: data.vehicle });
      }
    }
  });
})();
