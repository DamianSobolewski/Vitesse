/* Wyszukiwarka mocy — kaskada + bramka leadowa.
   Wartości po modyfikacji nie istnieją po stronie klienta, dopóki serwer
   ich nie zwróci w odpowiedzi na wysłanie formularza. */
(function () {
  'use strict';

  document.querySelectorAll('[data-vts-ps]').forEach(function (root) {
    var api    = root.dataset.rest.replace(/\/$/, '');
    var sel    = function (k) { return root.querySelector('[data-sel="' + k + '"]'); };
    var field  = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
    var out    = root.querySelector('[data-out]');
    var gate   = root.querySelector('[data-gate]');
    var errBox = root.querySelector('[data-err]');
    var note   = root.querySelector('[data-note]');
    var token  = null;
    var engine = null;

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

    function clearResult() {
      out.hidden = true;
      gate.hidden = false;
      note.hidden = true;
      errBox.hidden = true;
      var full = root.querySelector('.vts-ps__full');
      if (full) full.remove();
      root.querySelectorAll('.vts-ps__cell.is-gain').forEach(function (c) {
        c.classList.add('is-locked');
        c.querySelector('b').textContent = '—';
      });
    }

    sel('make').addEventListener('change', function (e) {
      clearResult();
      reset(sel('gen'), 'Generacja'); reset(sel('eng'), 'Silnik');
      get('/catalog/models', { make: e.target.value }).then(function (rows) {
        populate(sel('model'), rows, 'Model', function (r) { return r.name; }, function (r) { return r.id; });
      });
    });

    sel('model').addEventListener('change', function (e) {
      clearResult();
      reset(sel('eng'), 'Silnik');
      get('/catalog/generations', { model: e.target.value }).then(function (rows) {
        populate(sel('gen'), rows, 'Generacja', function (r) { return r.name; }, function (r) { return r.id; });
      });
    });

    sel('gen').addEventListener('change', function (e) {
      clearResult();
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

      engine = row.id;
      token  = row.token;

      clearResult();
      out.hidden = false;
      field('shp').textContent = row.stock_hp ? row.stock_hp + ' KM' : '—';
      field('veh').textContent = sel('make').selectedOptions[0].text + ' ' +
        sel('model').selectedOptions[0].text + ' ' + row.name;
    });

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
          consent: true,
          company: gate.querySelector('[name=company]').value
        })
      })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error(res.body && res.body.message ? res.body.message : 'Nie udało się wysłać.');
          reveal(res.body);
        })
        .catch(function (err) { fail(err.message); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Pokaż wynik'; });
    });

    function fail(msg) {
      errBox.textContent = msg;
      errBox.hidden = false;
    }

    function reveal(data) {
      gate.hidden = true;

      // Podsumowanie pokazuje jeden, najmocniejszy wariant; poziomy są rozpisane niżej.
      var top = data.best;
      if (top) {
        field('thp').textContent = top.tuned_hp ? top.tuned_hp + ' KM' : '—';
        field('ghp').textContent = '+' + top.gain_hp + ' KM';
        field('gnm').textContent = top.gain_nm ? '+' + top.gain_nm + ' Nm' : '—';
        root.querySelectorAll('.vts-ps__cell.is-gain')
            .forEach(function (c) { c.classList.remove('is-locked'); });
      }

      var wrap = document.createElement('div');
      wrap.className = 'vts-ps__full';
      wrap.innerHTML = data.results.map(function (r) {
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
