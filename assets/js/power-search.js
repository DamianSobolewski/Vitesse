/* Wyszukiwarka mocy — kaskada, bramka leadowa i ekran konsoli.
 *
 * Wartości po modyfikacji nie istnieją po stronie klienta, dopóki serwer ich nie
 * zwróci w odpowiedzi na wysłanie formularza. Nowa oprawa niczego w tym nie
 * zmienia: ekran do tego czasu pokazuje kreski, a nie zamazane liczby.
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
    var gate   = root.querySelector('[data-gate]');
    var errBox = root.querySelector('[data-err]');
    var note   = root.querySelector('[data-note]');

    var token = null, engine = null, stockHp = 0, warianty = null, vin = '';

    var vinBox   = root.querySelector('[data-vin]');
    var vinInput = root.querySelector('[data-vin-input]');
    var vinMsg   = root.querySelector('[data-vin-msg]');
    var cta      = root.querySelector('[data-cta]');

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
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
    }

    /* Gasi ekran do stanu wyjściowego. Presety wracają do wyłączonych — przed
       bramką nie ma czym ich wypełnić, więc nie mogą wyglądać na czynne. */
    function zgas() {
      out.hidden = true;
      gate.hidden = false;
      note.hidden = true;
      errBox.hidden = true;
      warianty = null;

      var full = root.querySelector('.vts-ps__full');
      if (full) full.remove();

      ['thp', 'ghp'].forEach(function (k) { field(k).textContent = PUSTE; });
      root.querySelectorAll('.vts-ps__cell.is-gain')
          .forEach(function (c) { c.classList.add('is-locked'); });
    }

    function zgasCaly() {
      zgas();
      if (cta) { cta.disabled = true; }
      ['shp', 'snm'].forEach(function (k) { field(k).textContent = PUSTE; });
      field('veh').textContent = 'Wybierz pojazd z listy poniżej';
      field('veh').classList.remove('is-set');
    }

    sel('make').addEventListener('change', function (e) {
      zgasCaly();
      reset(sel('gen'), 'Generacja'); reset(sel('eng'), 'Silnik');
      get('/catalog/models', { make: e.target.value }).then(function (rows) {
        populate(sel('model'), rows, 'Model', function (r) { return r.name; }, function (r) { return r.id; });
      });
    });

    sel('model').addEventListener('change', function (e) {
      zgasCaly();
      reset(sel('eng'), 'Silnik');
      get('/catalog/generations', { model: e.target.value }).then(function (rows) {
        populate(sel('gen'), rows, 'Generacja', function (r) { return r.name; }, function (r) { return r.id; });
      });
    });

    sel('gen').addEventListener('change', function (e) {
      zgasCaly();
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
      if (!row) return;

      engine  = row.id;
      token   = row.token;
      stockHp = row.stock_hp || 0;

      zgas();
      out.hidden = false;
      if (cta) { cta.disabled = false; }

      var pojazd = sel('make').selectedOptions[0].text + ' ' +
                   sel('model').selectedOptions[0].text + ' · ' + row.name;
      field('veh').textContent = pojazd;
      field('veh').classList.add('is-set');
      field('veh2').textContent = pojazd;

      // Stan fabryczny zapala się od razu — bramka trzyma tylko wartości po modyfikacji.
      if (row.stock_hp) { licz(field('shp'), 0, row.stock_hp, ' KM'); }
      else { field('shp').textContent = PUSTE; }
      // V-tech nie podaje momentu fabrycznego dla każdej wersji. Wartość słowna
      // w miejscu liczby nie mieści się w rytmie odczytu, więc komórka dostaje
      // własny, mniejszy krój — inaczej napis wychodzi na sąsiednią kolumnę.
      var kom = field('snm').parentElement;
      kom.classList.toggle('is-text', !row.stock_nm);
      if (row.stock_nm) { licz(field('snm'), 0, row.stock_nm, ' Nm'); }
      else { field('snm').textContent = 'brak danych'; }
    });

    /* Górny rząd: VIN.
       Endpoint zwraca wyłącznie markę i rok — nigdy wartości po modyfikacji.
       Trafienie ustawia pierwszy select i wyzwala tę samą kaskadę co klik,
       więc nie ma tu drugiej ścieżki do utrzymania. Pudło sprowadza użytkownika
       o rząd niżej zamiast zostawiać go z samym błędem. */
    if (vinBox && vinInput) {
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
        if (v.length !== 17) { vinPokaz('Numer VIN ma 17 znaków — wpisano ' + v.length + '.', false); return; }

        vinGo.disabled = true;
        get('/catalog/vin', { vin: v })
          .then(function (res) {
            vinPokaz(res.message, res.ok);
            if (!res.ok || !res.make) { sel('make').focus(); return; }

            // Podstawienie marki wyzwala ten sam `change` co klik, a ten czyści VIN.
            // Flaga odróżnia zmianę z dekodera od ręcznej.
            vinAuto = true;
            sel('make').value = res.make.slug;
            sel('make').dispatchEvent(new Event('change'));
            vinAuto = false;
            vin = v;
            sel('model').focus();
          })
          .catch(function () { vinPokaz('Nie udało się sprawdzić numeru — wybierzcie pojazd z list poniżej.', false); })
          .finally(function () { vinGo.disabled = false; });
      };

      vinGo.addEventListener('click', vinSzukaj);
      vinInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); vinSzukaj(); }
      });
      // Ręczna zmiana marki unieważnia VIN — inaczej lead niósłby numer pojazdu,
      // którego użytkownik już nie wybiera.
      sel('make').addEventListener('change', function () {
        if (!vinAuto) { vin = ''; }
      });
    }

    /* Przycisk konwersji. Bramka i tak siedzi niżej w formularzu — CTA tylko
       do niej przewija, żeby na telefonie nie trzeba było szukać pola e-mail. */
    if (cta) {
      cta.addEventListener('click', function () {
        if (out.hidden) { return; }
        out.scrollIntoView({ behavior: motionOK ? 'smooth' : 'auto', block: 'center' });
        var email = gate.querySelector('[name=email]');
        if (email && !gate.hidden) { email.focus({ preventScroll: true }); }
      });
    }

    gate.addEventListener('submit', function (e) {
      e.preventDefault();
      errBox.hidden = true;

      var email   = gate.querySelector('[name=email]');
      var consent = gate.querySelector('[name=consent]');

      if (!email.value || !email.checkValidity()) { email.focus(); return fail('Podaj poprawny adres e-mail.'); }
      if (!consent.checked) { consent.focus(); return fail('Zaznacz zgodę na kontakt.'); }

      var btn = gate.querySelector('button');
      btn.disabled = true;
      btn.textContent = 'Wysyłam…';

      fetch(api + '/lead', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          engine_id: engine,
          token: token,
          email: email.value,
          vin: vin,
          consent: true,
          company: gate.querySelector('[name=company]').value
        })
      })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.body && res.body.message ? res.body.message : 'Nie udało się wysłać.');
          odsloń(res.body);
        })
        .catch(function (err) { fail(err.message); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Pokaż wynik'; });
    });

    function fail(msg) {
      errBox.textContent = msg;
      errBox.hidden = false;
    }

    /* Przełożenie najmocniejszego wariantu na ekran. */
    function pokaz(w) {
      root.querySelectorAll('.vts-ps__cell.is-gain')
          .forEach(function (c) { c.classList.remove('is-locked'); });

      if (w.tuned_hp) { licz(field('thp'), stockHp || 0, w.tuned_hp, ' KM'); }
      else { field('thp').textContent = PUSTE; }
      licz(field('ghp'), 0, w.gain_hp, ' KM', '+');
    }

    function odsloń(data) {
      gate.hidden = true;
      warianty = data.results || [];

      var start = warianty.filter(function (r) {
        return data.best && r.label === data.best.label;
      })[0] || warianty[0];
      if (start) { pokaz(start); }

      var wrap = document.createElement('div');
      wrap.className = 'vts-ps__full';
      wrap.innerHTML = warianty.map(function (r) {
        var moc = '<span>moc <b>+' + r.gain_hp + ' KM</b>' +
                  (r.tuned_hp ? ' <em>→ ' + r.tuned_hp + '</em>' : '') + '</span>';
        var mom = r.gain_nm ? '<span>moment <b>+' + r.gain_nm + ' Nm</b></span>' : '';
        return '<div class="vts-ps__srv"><h4>' + r.label + '</h4>' +
               '<div class="vts-ps__srv-v">' + moc + mom + '</div></div>';
      }).join('');
      out.insertBefore(wrap, note);

      note.textContent = data.note;
      note.hidden = false;

      if (window.dataLayer) {
        window.dataLayer.push({ event: 'generate_lead', lead_source: 'hero-cascade', vehicle: data.vehicle });
      }
    }
  });
})();
