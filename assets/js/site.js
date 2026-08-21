/* Vitesse — skrypt szkieletu: menu mobilne + wysokość nagłówka. */
(function () {
  'use strict';

  var header = document.querySelector('.vts-header');
  var burger = document.querySelector('.vts-burger');
  var nav    = document.getElementById('vts-nav');

  function setHeaderHeight() {
    if (!header) return;
    document.documentElement.style.setProperty('--vts-header-h', header.offsetHeight + 'px');
  }
  setHeaderHeight();
  addEventListener('resize', setHeaderHeight, { passive: true });

  /* ---------------------------------------------------------- hero: zapłon
   *
   * Klasę js-anim dodajemy natychmiast — dopiero ona włącza w CSS stan
   * wyjściowy „światła zgaszone". Przy wyłączonym JavaScripcie klasy nie ma,
   * więc kadr jest od razu kompletny.
   *
   * Sekwencję odpalamy po wczytaniu zdjęcia. Bez tego potrafi zagrać na pustym
   * prostokącie. Samego tła nie dotykamy — jest elementem LCP.
   */
  var motionOK = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hero = document.querySelector('.vts-hero');
  var bg   = hero && hero.querySelector('.vts-hero__bg');

  // Klasa włącza w CSS stany wyjściowe animacji. Dodajemy ją tylko wtedy, gdy
  // ruch jest dozwolony — inaczej nic nigdy nie zostałoby ukryte ani odsłonięte.
  if (motionOK) {
    document.documentElement.classList.add('js-anim');
  }

  if (bg && motionOK) {
    var ignite = function () {
      hero.classList.add('is-lit');
      hero.querySelectorAll('.vts-hero__beams, .vts-hero__glow').forEach(function (el) {
        el.addEventListener('animationend', function () { el.style.willChange = 'auto'; }, { once: true });
      });
    };

    var src = (getComputedStyle(bg).backgroundImage || '').replace(/^url\(["']?/, '').replace(/["']?\)$/, '');
    if (src) {
      var probe = new Image();
      probe.onload = probe.onerror = ignite;
      probe.src = src;
      // gdyby zdjęcie utknęło — nie zostawiamy hero w ciemności
      setTimeout(ignite, 2500);
    } else {
      ignite();
    }
  }

  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', String(open));
      burger.textContent = open ? 'ZAMKNIJ' : 'MENU';
    });

    // Escape zamyka menu i oddaje focus przyciskowi
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        nav.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
        burger.textContent = 'MENU';
        burger.focus();
      }
    });
  }

  /* ------------------------------------------------- delikatne wejścia kart
   *
   * Animujemy wyłącznie kafelki i karty — bloki tekstowe zostawiamy w spokoju,
   * bo poruszający się akapit utrudnia czytanie, a nie pomaga.
   * Hero jest wykluczone: zawiera element LCP i treść nad linią zgięcia.
   */
  if (motionOK && 'IntersectionObserver' in window) {
    var items = document.querySelectorAll(
      '.vts-card, .vts-cat-tile, .vts-dyno__card'
    );

    if (items.length) {
      items.forEach(function (el) {
        if (!el.closest('.vts-hero')) { el.classList.add('vts-reveal'); }
      });

      var io = new IntersectionObserver(function (entries, obs) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) { return; }

          var el = entry.target;
          // przesunięcie względem rodzeństwa daje efekt kaskady w obrębie siatki
          var sibs = [].slice.call(el.parentNode.children).filter(function (n) {
            return n.classList && n.classList.contains('vts-reveal');
          });
          el.style.transitionDelay = Math.min(sibs.indexOf(el), 7) * 60 + 'ms';
          el.classList.add('is-in');
          obs.unobserve(el);
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

      document.querySelectorAll('.vts-reveal').forEach(function (el) { io.observe(el); });
    }
  }
})();
