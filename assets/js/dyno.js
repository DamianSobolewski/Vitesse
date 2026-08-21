/* Filtrowanie siatki wykresów. Chipy są zwykłymi linkami do archiwów taksonomii —
   JS tylko przechwytuje kliknięcie, żeby nie przeładowywać strony. */
(function () {
  'use strict';

  document.querySelectorAll('[data-vts-dyno]').forEach(function (root) {
    var api  = root.dataset.rest;
    var grid = root.querySelector('[data-grid]');
    var more = root.querySelector('[data-more]');
    var active = '';

    function load(page, append) {
      var url = new URL(api, location.origin);
      if (active) url.searchParams.set('marka', active);
      url.searchParams.set('page', page);

      return fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var html = data.items.map(card).join('');
          if (append) grid.insertAdjacentHTML('beforeend', html);
          else grid.innerHTML = html || '<p class="vts-dyno__empty">Brak wyników dla tego filtra.</p>';

          if (more) {
            more.dataset.page = page;
            more.hidden = page >= data.pages;
          }
        });
    }

    function card(i) {
      var gain = (i.stock && i.tuned) ? ' <em>+' + (i.tuned - i.stock) + '</em>' : '';
      var vals = (i.stock && i.tuned)
        ? '<span class="vts-dyno__v">' + i.stock + ' → <b>' + i.tuned + ' KM</b>' + gain + '</span>' : '';
      var img = i.img
        ? '<span class="vts-dyno__img"><img src="' + i.img + '" alt="" loading="lazy" width="600" height="400"></span>' : '';
      return '<a class="vts-dyno__card" href="' + i.url + '">' + img +
             '<span class="vts-dyno__body"><span class="vts-dyno__t">' + i.title + '</span>' + vals + '</span></a>';
    }

    root.querySelectorAll('[data-filter]').forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        active = chip.dataset.filter;
        root.querySelectorAll('[data-filter]').forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        load(1, false);
      });
    });

    if (more) {
      more.addEventListener('click', function () {
        load(parseInt(more.dataset.page, 10) + 1, true);
      });
    }
  });
})();
