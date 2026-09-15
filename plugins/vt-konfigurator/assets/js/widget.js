/* VT Konfigurator – widget.js  |  vanilla JS, zero dependencies */
(function () {
	'use strict';

	var cfg = window.vtKonfigurator;
	if (!cfg || !cfg.tree) return;

	var tree = cfg.tree;
	var i18n = cfg.i18n;
	var settings = cfg.settings;

	// ── Helpers ──────────────────────────────────────────────────────────────

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') { node.textContent = attrs[k]; }
				else if (k === 'html') { node.innerHTML = attrs[k]; }
				else { node.setAttribute(k, attrs[k]); }
			});
		}
		if (children) {
			children.forEach(function (c) { if (c) node.appendChild(c); });
		}
		return node;
	}

	function buildSelect(id, placeholder, items, disabled) {
		var select = el('select', {
			id: id,
			class: 'vtk-select',
			'aria-label': placeholder
		});
		if (disabled) select.disabled = true;

		var opt0 = el('option', { value: '', text: placeholder });
		opt0.selected = true;
		select.appendChild(opt0);

		items.forEach(function (item) {
			select.appendChild(el('option', { value: item.slug, text: item.label }));
		});
		return select;
	}

	function sortByLabel(items) {
		return items.slice().sort(function (a, b) { return a.label.localeCompare(b.label, 'pl'); });
	}

	// ── Tree traversal ───────────────────────────────────────────────────────

	function getBrands() {
		return sortByLabel(Object.keys(tree).map(function (slug) {
			return { slug: slug, label: tree[slug].label };
		}));
	}

	function getModels(brandSlug) {
		var models = tree[brandSlug] && tree[brandSlug].models || {};
		return sortByLabel(Object.keys(models).map(function (slug) {
			return { slug: slug, label: models[slug].label };
		}));
	}

	/**
	 * Extracts unique generations for a brand+model by scanning all years' engines.
	 * Each engine.value = "{genSlug}::{engineSlug}".
	 */
	function getGenerations(brandSlug, modelSlug) {
		var years = tree[brandSlug] &&
			tree[brandSlug].models[modelSlug] &&
			tree[brandSlug].models[modelSlug].years || {};
		var seen = {};
		var gens = [];
		Object.values(years).forEach(function (yearData) {
			(yearData.engines || []).forEach(function (eng) {
				var genSlug = eng.value.split('::')[0];
				if (!seen[genSlug]) {
					seen[genSlug] = true;
					gens.push({ slug: genSlug, label: eng.gen_label });
				}
			});
		});
		return sortByLabel(gens);
	}

	/**
	 * Returns unique engines (deduplicated by engineSlug) for a given generation.
	 */
	function getEngines(brandSlug, modelSlug, genSlug) {
		var years = tree[brandSlug] &&
			tree[brandSlug].models[modelSlug] &&
			tree[brandSlug].models[modelSlug].years || {};
		var seen = {};
		var engines = [];
		Object.values(years).forEach(function (yearData) {
			(yearData.engines || []).forEach(function (eng) {
				var parts = eng.value.split('::');
				if (parts[0] === genSlug && !seen[parts[1]]) {
					seen[parts[1]] = true;
					engines.push({ slug: parts[1], label: eng.engine });
				}
			});
		});
		return sortByLabel(engines);
	}

	/**
	 * Returns the human-readable engine label (e.g. "2.0 TDI 150KM") for a slug pair.
	 */
	function getEngineLabel(brandSlug, modelSlug, genSlug, engineSlug) {
		var years = tree[brandSlug] &&
			tree[brandSlug].models[modelSlug] &&
			tree[brandSlug].models[modelSlug].years || {};
		var target = genSlug + '::' + engineSlug;
		var label  = null;
		Object.values(years).some(function (yearData) {
			return (yearData.engines || []).some(function (eng) {
				if (eng.value === target) { label = eng.engine; return true; }
			});
		});
		return label;
	}

	/**
	 * Returns years in which the selected gen+engine combination exists.
	 */
	function getYears(brandSlug, modelSlug, genSlug, engineSlug) {
		var years = tree[brandSlug] &&
			tree[brandSlug].models[modelSlug] &&
			tree[brandSlug].models[modelSlug].years || {};
		var engineValue = genSlug + '::' + engineSlug;
		var result = [];
		Object.keys(years).forEach(function (yearSlug) {
			var yearData = years[yearSlug];
			var hasEngine = (yearData.engines || []).some(function (e) {
				return e.value === engineValue;
			});
			if (hasEngine) {
				result.push({ slug: yearSlug, label: yearData.label });
			}
		});
		return result.sort(function (a, b) { return a.slug.localeCompare(b.slug); });
	}

	// ── Fetch result ─────────────────────────────────────────────────────────

	function fetchResult(state, callback) {
		var baseUrl = cfg.restUrl.replace(/\/$/, ''); // strip accidental trailing slash
		var url = baseUrl +
			'?brand=' + encodeURIComponent(state.brand) +
			'&model=' + encodeURIComponent(state.model) +
			'&gen=' + encodeURIComponent(state.gen) +
			'&engine=' + encodeURIComponent(state.engine) +
			'&year=' + encodeURIComponent(state.year);

		fetch(url, {
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Accept': 'application/json'
			}
		})
		.then(function (r) {
			if (!r.ok) {
				return r.json().then(function (body) {
					throw new Error(body.message || 'HTTP ' + r.status);
				});
			}
			return r.json();
		})
		.then(function (data) { callback(null, data); })
		.catch(function (err) { callback(err, null); });
	}

	// ── Result card render ───────────────────────────────────────────────────

	function renderGainValue(value, unit) {
		return el('div', { class: 'vtk-gain' }, [
			el('span', { class: 'vtk-gain-value', text: '+' + value }),
			el('span', { class: 'vtk-gain-unit', text: ' ' + unit })
		]);
	}

	function renderProductCard(name, data) {
		var card = el('div', { class: 'vtk-card' });
		card.appendChild(el('h4', { class: 'vtk-card-name', text: name }));
		var gains = el('div', { class: 'vtk-gains' });
		if (data.hp) {
			gains.appendChild(el('div', { class: 'vtk-gain-wrap vtk-gain--hp' }, [
				el('span', { class: 'vtk-gain-label', text: i18n.powerGain }),
				renderGainValue(data.hp, i18n.hp)
			]));
		}
		if (data.nm) {
			gains.appendChild(el('div', { class: 'vtk-gain-wrap vtk-gain--nm' }, [
				el('span', { class: 'vtk-gain-label', text: i18n.torqueGain }),
				renderGainValue(data.nm, i18n.nm)
			]));
		}
		card.appendChild(gains);
		return card;
	}

	// ── Widget instance ──────────────────────────────────────────────────────

	function initWidget(container) {
		container.style.setProperty('--vtk-accent', settings.accentColor);
		container.style.setProperty('--vtk-radius', settings.borderRadius + 'px');

		var showCharts = container.dataset.charts === '1';
		var state = { brand: '', model: '', gen: '', engine: '', year: '' };

		// ── Form ──────────────────────────────────────────────────────────
		var form = el('div', { class: 'vtk-form' });

		var uid = Math.random().toString(36).slice(2, 7);
		var fields = [
			{ key: 'brand',  ph: i18n.selectBrand },
			{ key: 'model',  ph: i18n.selectModel },
			{ key: 'gen',    ph: i18n.selectGeneration },
			{ key: 'engine', ph: i18n.selectEngine },
			{ key: 'year',   ph: i18n.selectYear },
		];
		var selects = {};

		var dropdowns = el('div', { class: 'vtk-dropdowns' });
		fields.forEach(function (f) {
			var id  = 'vtk-' + f.key + '-' + uid;
			var wrap = el('div', { class: 'vtk-field vtk-field--' + f.key });
			var lbl  = el('label', { class: 'vtk-label', 'for': id, text: f.ph });
			var sel  = buildSelect(id, f.ph, [], f.key !== 'brand');
			selects[f.key] = sel;
			wrap.appendChild(lbl);
			wrap.appendChild(sel);
			dropdowns.appendChild(wrap);
		});

		var submitBtn = el('button', { class: 'vtk-btn vtk-btn--submit', type: 'button', text: i18n.checkTuning, disabled: '' });
		var loadingEl = el('div', { class: 'vtk-loading', 'aria-live': 'polite' }, [
			el('div', { class: 'vtk-spinner', 'aria-hidden': 'true' }),
			el('span', { text: i18n.loading })
		]);
		loadingEl.hidden = true;
		var errorEl = el('div', { class: 'vtk-error-msg', role: 'alert' });
		errorEl.hidden = true;

		form.appendChild(dropdowns);
		form.appendChild(submitBtn);

		// ── Result overlay (popup modal) ──────────────────────────────────
		var resultCloseBtn = el('button', { class: 'vtk-result-close', type: 'button', 'aria-label': i18n.closeModal });
		resultCloseBtn.textContent = '×';

		var resultBox = el('div', { class: 'vtk-result-box', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
		resultBox.appendChild(resultCloseBtn);

		var resultOverlay = el('div', { class: 'vtk-result-overlay' });
		resultOverlay.hidden = true;
		resultOverlay.appendChild(resultBox);

		// ── Dyno chart overlay (full-screen image) ────────────────────────
		var dynoImg      = el('img',    { class: 'vtk-dyno-img', alt: '' });
		var dynoCloseBtn = el('button', { class: 'vtk-dyno-close', type: 'button', 'aria-label': i18n.closeModal });
		dynoCloseBtn.textContent = '×';
		var dynoBox      = el('div', { class: 'vtk-dyno-box' });
		dynoBox.appendChild(dynoCloseBtn);
		dynoBox.appendChild(dynoImg);
		var dynoOverlay  = el('div', { class: 'vtk-dyno-overlay' });
		dynoOverlay.hidden = true;
		dynoOverlay.appendChild(dynoBox);

		container.appendChild(form);
		container.appendChild(loadingEl);
		container.appendChild(errorEl);
		container.appendChild(resultOverlay);
		container.appendChild(dynoOverlay);

		// ── Cascade helpers ───────────────────────────────────────────────

		function populateSelect(sel, items, disabled) {
			while (sel.options.length > 1) sel.remove(1);
			sel.disabled = disabled || items.length === 0;
			sel.value = '';
			items.forEach(function (item) {
				sel.appendChild(el('option', { value: item.slug, text: item.label }));
			});
		}

		function resetFrom(level) {
			var order = ['brand', 'model', 'gen', 'engine', 'year'];
			var idx   = order.indexOf(level);
			for (var i = idx; i < order.length; i++) {
				state[order[i]] = '';
				if (i > 0) populateSelect(selects[order[i]], [], true);
			}
			submitBtn.disabled = true;
			errorEl.hidden = true;
		}

		populateSelect(selects.brand, getBrands(), false);

		selects.brand.addEventListener('change', function () {
			state.brand = this.value;
			resetFrom('model');
			if (state.brand) populateSelect(selects.model, getModels(state.brand), false);
		});
		selects.model.addEventListener('change', function () {
			state.model = this.value;
			resetFrom('gen');
			if (state.model) populateSelect(selects.gen, getGenerations(state.brand, state.model), false);
		});
		selects.gen.addEventListener('change', function () {
			state.gen = this.value;
			resetFrom('engine');
			if (state.gen) populateSelect(selects.engine, getEngines(state.brand, state.model, state.gen), false);
		});
		selects.engine.addEventListener('change', function () {
			state.engine = this.value;
			resetFrom('year');
			if (state.engine) populateSelect(selects.year, getYears(state.brand, state.model, state.gen, state.engine), false);
		});
		selects.year.addEventListener('change', function () {
			state.year = this.value;
			errorEl.hidden = true;
			submitBtn.disabled = !state.year;
		});

		// ── Submit → show results in popup ────────────────────────────────

		function makeChartBtn(chartUrl, altText) {
			var btn = el('button', { class: 'vtk-chart-btn', type: 'button' });
			btn.textContent = i18n.viewChart || 'Zobacz wykres mocy';
			btn.addEventListener('click', function () {
				dynoImg.src        = chartUrl;
				dynoImg.alt        = altText;
				dynoOverlay.hidden = false;
				dynoOverlay.removeAttribute('hidden');
			});
			return btn;
		}

		function openResult(data) {
			var brandLabel  = tree[state.brand].label;
			var modelLabel  = tree[state.brand].models[state.model].label;
			var engineLabel = getEngineLabel(state.brand, state.model, state.gen, state.engine);

			// Rebuild result box content.
			resultBox.innerHTML = '';
			resultBox.appendChild(resultCloseBtn);

			var title = el('h3', { class: 'vtk-result-title' });
			title.textContent = i18n.resultsFor + ' ' + brandLabel + ' ' + modelLabel +
				(engineLabel ? ' ' + engineLabel : '') + ' ' + state.year;
			resultBox.appendChild(title);

			var cards = el('div', { class: 'vtk-cards' });
			var cardCount = 0;
			if (data.powerchip) {
				var pcCard = renderProductCard(i18n.powerchip || 'PowerChip', data.powerchip);
				if (showCharts && data.powerchip.chart_url) {
					pcCard.appendChild(makeChartBtn(data.powerchip.chart_url, brandLabel + ' ' + modelLabel + ' PowerChip'));
				}
				cards.appendChild(pcCard);
				cardCount++;
			}
			if (data.chip_tuning) {
				var ctCard = renderProductCard(i18n.chipTuning || 'Chip Tuning', data.chip_tuning);
				if (showCharts && data.chip_tuning.chart_url) {
					ctCard.appendChild(makeChartBtn(data.chip_tuning.chart_url, brandLabel + ' ' + modelLabel + ' Chip Tuning'));
				}
				cards.appendChild(ctCard);
				cardCount++;
			}
			if (cardCount === 1) cards.classList.add('vtk-cards--single');
			resultBox.appendChild(cards);

			// ── Private Drive link (charts="1" mode only) ────────────────
			if (showCharts && cfg.driveUrl) {
				var driveLink = el('a', {
					class:  'vtk-drive-link',
					href:   cfg.driveUrl,
					target: '_blank',
					rel:    'noopener noreferrer',
					text:   i18n.privateCharts
				});
				resultBox.appendChild(driveLink);
			}

			// ── Phone numbers (customer mode only) ───────────────────────
			var activePhones = !showCharts ? (cfg.phones || []).filter(function (p) { return p.number; }) : [];
			if (activePhones.length) {
				var phonesWrap = el('div', { class: 'vtk-phones' });
				phonesWrap.appendChild(el('p', { class: 'vtk-phones-label', text: i18n.interestedCall }));
				activePhones.forEach(function (p) {
					var link = el('a', {
						class: 'vtk-phone-link',
						href:  'tel:' + p.number.replace(/\s/g, '')
					});
					if (p.name) {
						link.appendChild(el('span', { class: 'vtk-phone-name', text: p.name + ':' }));
					}
					link.appendChild(el('span', { class: 'vtk-phone-number', text: p.number }));
					phonesWrap.appendChild(link);
				});
				resultBox.appendChild(phonesWrap);
			}

			resultOverlay.hidden = false;
			resultOverlay.removeAttribute('hidden');
			resultBox.focus();
		}

		function closeResult() {
			resultOverlay.hidden = true;
			submitBtn.disabled = false;
			submitBtn.focus();
		}

		resultCloseBtn.addEventListener('click', closeResult);
		resultOverlay.addEventListener('click', function (e) {
			if (e.target === resultOverlay) closeResult();
		});

		dynoCloseBtn.addEventListener('click', function () { dynoOverlay.hidden = true; });
		dynoOverlay.addEventListener('click',  function (e) { if (e.target === dynoOverlay) dynoOverlay.hidden = true; });

		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			if (!dynoOverlay.hidden)   { dynoOverlay.hidden = true;  return; }
			if (!resultOverlay.hidden) { closeResult(); }
		});

		function doFetch() {
			form.setAttribute('aria-busy', 'true');
			loadingEl.hidden = false;
			submitBtn.disabled = true;
			errorEl.hidden = true;

			fetchResult(state, function (err, data) {
				loadingEl.hidden = true;
				form.removeAttribute('aria-busy');
				submitBtn.disabled = false;

				if (err) {
					errorEl.textContent = err.message || i18n.errorFetch;
					errorEl.hidden = false;
					return;
				}
				if (!data.powerchip && !data.chip_tuning) {
					errorEl.textContent = i18n.noData;
					errorEl.hidden = false;
					return;
				}
				openResult(data);
			});
		}

		submitBtn.addEventListener('click', doFetch);
		Object.values(selects).forEach(function (sel) {
			sel.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !submitBtn.disabled) doFetch();
			});
		});
	}

	// ── Popup / modal logic ──────────────────────────────────────────────────

	function initPopup(modal, triggerBtn) {
		var overlay = modal;

		function openModal() {
			overlay.hidden = false;
			overlay.removeAttribute('hidden');
			triggerBtn.setAttribute('aria-expanded', 'true');
			// Focus first focusable element inside.
			var first = overlay.querySelector('select, button, input, [tabindex]');
			if (first) first.focus();
			document.addEventListener('keydown', trapFocus);
		}

		function closeModal() {
			overlay.hidden = true;
			triggerBtn.setAttribute('aria-expanded', 'false');
			triggerBtn.focus();
			document.removeEventListener('keydown', trapFocus);
		}

		function trapFocus(e) {
			if (e.key === 'Escape') { closeModal(); return; }
			if (e.key !== 'Tab') return;
			var focusable = Array.from(
				overlay.querySelectorAll('select, button, input, [tabindex="0"]')
			).filter(function (el) { return !el.disabled && !el.hidden; });
			if (!focusable.length) return;
			var first = focusable[0];
			var last  = focusable[focusable.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault(); last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault(); first.focus();
			}
		}

		triggerBtn.addEventListener('click', openModal);

		var closeBtn = overlay.querySelector('.vtk-modal-close');
		if (closeBtn) closeBtn.addEventListener('click', closeModal);

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) closeModal();
		});
	}

	// ── Boot ─────────────────────────────────────────────────────────────────

	function boot() {
		document.querySelectorAll('.vtk-widget').forEach(function (container) {
			initWidget(container);
		});

		// Wire popup triggers to modals.
		document.querySelectorAll('.vtk-popup-wrap').forEach(function (wrap) {
			var btn   = wrap.querySelector('.vtk-popup-btn');
			// The modal immediately follows the popup-wrap in DOM.
			var modal = wrap.nextElementSibling;
			if (btn && modal && modal.classList.contains('vtk-modal-overlay')) {
				initPopup(modal, btn);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
