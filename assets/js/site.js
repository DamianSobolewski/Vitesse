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

  /* --------------------------------------------------- światła awaryjne
   *
   * Trójkąt na konsoli zapala awaryjne w aucie na zdjęciu. Efekt jest w całości
   * w CSS — skrypt tylko przełącza klasę i stan przycisku, więc przy wyłączonym
   * JavaScripcie przycisk po prostu nic nie robi i nic się nie psuje.
   */
  var hazard = document.querySelector('[data-hazard]');
  if (hazard && hero) {
    hazard.addEventListener('click', function () {
      var on = hero.classList.toggle('is-hazard');
      hazard.setAttribute('aria-pressed', String(on));
    });
  }

  /* ----------------------------------------------------- światła pojazdu
   *
   * Włącznik na rzędzie presetów gasi i zapala reflektory. Zapalenie ponownie
   * odpala pełną sekwencję zapłonu, bo to ta sama klasa is-lit, którą wiesza
   * skrypt po wczytaniu zdjęcia.
   */
  var power = document.querySelector('[data-power]');
  if (power && hero) {
    power.addEventListener('click', function () {
      var on = !hero.classList.contains('is-lit');
      hero.classList.toggle('is-lit', on);
      power.setAttribute('aria-pressed', String(on));
    });
  }

  /* ------------------------------------------------------- menu mobilne
   *
   * Menu jest nakładką na cały ekran, ale ma zostać POD nagłówkiem, żeby
   * przycisk zamknięcia był widoczny i klikalny. Odstęp od góry liczymy
   * z realnej dolnej krawędzi nagłówka w chwili otwarcia: nagłówek jest
   * przyklejony, a pasek kontaktowy odjeżdża przy przewijaniu, więc sztywna
   * wartość myliłaby się przy większości pozycji strony.
   */
  if (burger && nav) {
    var setMenu = function (open) {
      if (open) {
        var bottom = header ? header.getBoundingClientRect().bottom : 64;
        document.documentElement.style.setProperty(
          '--vts-chrome-h', Math.max(0, Math.round(bottom)) + 'px');
      }
      nav.classList.toggle('is-open', open);
      // klasa na obu elementach: przewijaniem steruje <html>, nie <body>
      document.documentElement.classList.toggle('vts-menu-open', open);
      document.body.classList.toggle('vts-menu-open', open);
      burger.setAttribute('aria-expanded', String(open));
      burger.textContent = open ? 'ZAMKNIJ' : 'MENU';
    };

    burger.addEventListener('click', function () {
      setMenu(!nav.classList.contains('is-open'));
    });

    // Kliknięcie pozycji zamyka menu. Bez tego blokada przewijania zostawałaby
    // na stronie docelowej wszędzie tam, gdzie odnośnik prowadzi do kotwicy
    // i strona się nie przeładowuje.
    nav.addEventListener('click', function (e) {
      if (e.target.closest('a')) setMenu(false);
    });

    // Escape zamyka menu i oddaje focus przyciskowi
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        setMenu(false);
        burger.focus();
      }
    });

    // Po obrocie na poziomo albo powiększeniu okna nakładka przestaje
    // obowiązywać — zdejmujemy blokadę, żeby strona nie została zamrożona.
    addEventListener('resize', function () {
      if (nav.classList.contains('is-open') && innerWidth > 1080) setMenu(false);
    }, { passive: true });
  }

  /* ------------------------------------------------- akordeon z wyłącznością
   *
   * Wyłączność robi natywny atrybut name na <details> — działa bez skryptu.
   * Ten blok jest wyłącznie asekuracją dla przeglądarek, które go jeszcze nie
   * znają: bez niego rozwijałyby się tam wszystkie pozycje naraz.
   */
  if (!('name' in document.createElement('details'))) {
    document.querySelectorAll('details[name]').forEach(function (d) {
      d.addEventListener('toggle', function () {
        if (!d.open) { return; }
        var grupa = d.getAttribute('name');
        document.querySelectorAll('details[name="' + grupa + '"]').forEach(function (inny) {
          if (inny !== d) { inny.open = false; }
        });
      });
    });
  }

  /* --------------------------------------------------- odliczanie liczb
   *
   * Ruszamy z opóźnieniem, żeby liczba dobiła do wartości mniej więcej wtedy,
   * gdy wskazówka kończy powrót z pełnego wychyłu — inaczej odczyt wyprzedza
   * przyrząd i sekwencja się rozjeżdża.
   *
   * Liczbę bierzemy z atrybutu, a przedrostek i format z tekstu, który wyszedł
   * z serwera. Dzięki temu „+28", „6,5", „4 853" i „4×4" zachowują się poprawnie —
   * to ostatnie nie jest liczbą i nie ma atrybutu, więc nie rusza się wcale.
   *
   * Ten sam mechanizm obsługuje zegary i pasek liczb — stąd „w środku elementu",
   * a nie „w zegarze".
   */
  function odliczWSrodku(el) {
    if (!motionOK || !el.querySelectorAll) { return; }
    [].slice.call(el.querySelectorAll('[data-vts-count]')).forEach(odliczPole);
  }

  function odliczPole(pole) {
    if (pole.dataset.vtsDone) { return; }
    pole.dataset.vtsDone = '1';

    var surowa = pole.dataset.vtsCount.replace(',', '.');
    var cel    = parseFloat(surowa);
    if (isNaN(cel)) { return; }

    var wyjsciowy = pole.textContent.trim();
    var miejsc    = (surowa.split('.')[1] || '').length;
    var przecin   = pole.dataset.vtsCount.indexOf(',') >= 0;
    var przed     = wyjsciowy.charAt(0) === '+' ? '+' : '';
    // Serwer podaje tysiące ze spacją („4 853"). Bez tego licznik kończyłby na
    // „4853" i format zmieniałby się w trakcie animacji.
    var spacja    = /\d[\s\u00a0]\d/.test(wyjsciowy);
    var zapisz    = function (v) {
      var t = v.toFixed(miejsc);
      if (spacja) { t = t.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0'); }
      pole.textContent = przed + (przecin ? t.replace('.', ',') : t);
    };

    var start = null, czas = 900;
    zapisz(0);
    setTimeout(function () {
      requestAnimationFrame(function krok(t) {
        if (start === null) { start = t; }
        var p = Math.min(1, (t - start) / czas);
        zapisz(cel * (1 - Math.pow(1 - p, 3)));
        if (p < 1) { requestAnimationFrame(krok); } else { zapisz(cel); }
      });
    }, 700);
  }

  /* ------------------------------------------------- delikatne wejścia kart
   *
   * Animujemy wyłącznie kafelki i karty — bloki tekstowe zostawiamy w spokoju,
   * bo poruszający się akapit utrudnia czytanie, a nie pomaga.
   * Hero jest wykluczone: zawiera element LCP i treść nad linią zgięcia.
   */
  if (motionOK && 'IntersectionObserver' in window) {
    var items = document.querySelectorAll(
      '.vts-card, .vts-cat-tile, .vts-dyno__card, .vts-gauge, .vts-wynik, .vts-liczba'
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
          odliczWSrodku(el);
          obs.unobserve(el);
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

      document.querySelectorAll('.vts-reveal').forEach(function (el) { io.observe(el); });
    }
  }
})();
