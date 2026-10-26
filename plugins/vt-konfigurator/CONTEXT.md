# vt-konfigurator — LLM Context

**Plugin:** WordPress chip tuning configurator for a Vtech dealer  
**Author:** Signuply / signuply.io  
**Stack:** PHP 8.0+, WordPress 6.0+, vanilla JS (zero dependencies), no build step

---

## What it does

Embeds a cascading-dropdown widget on any WP page. User picks Brand → Model → Generation → Engine → Year, clicks "Sprawdź tuning", and sees PowerChip / Chip Tuning power gain results (+KM, +Nm) in a modal popup. Data is scraped from `sklep.vtech.pl`.

---

## Shortcode API

| Shortcode | Mode |
|---|---|
| `[vt_konfigurator]` | Inline widget — customer mode |
| `[vt_konfigurator mode="popup"]` | Trigger button → modal — customer mode |
| `[vt_konfigurator charts="1"]` | Inline — owner mode |
| `[vt_konfigurator mode="popup" charts="1"]` | Popup — owner mode |

**`charts="1"` = owner mode.** The two modes show different UI elements:

| Element | Customer (`charts="0"`) | Owner (`charts="1"`) |
|---|---|---|
| Power gain cards | ✓ | ✓ |
| "Zobacz wykres mocy" (vtech dyno image) | — | ✓ per card |
| "Twoje wykresy" (Google Drive link) | — | ✓ |
| Competitor Stage 1 tiles (ziptuning.nl, rica.nl) | — | ✓ (below "Twoje wykresy", loaded async) |
| Phone numbers | ✓ (if configured) | — |

---

## Data sources

- **Vehicle tree:** `https://sklep.vtech.pl/konfigurator-powerchip/` — inline JS variable `var vtFitmentSearch = {...}` extracted by brace-matching parser (no regex)
- **Result page:** `https://sklep.vtech.pl/powerchip/{brand}/{model}/{gen}/{engine}/?vehicle_year={year}`
- Both fetched server-side via `wp_remote_get`. Tree is stored in `wp_options` (autoload=no). Results are cached per-combination as WP transients.

### External sources (owner mode) — `includes/class-vt-external.php`

- **ziptuning.nl:** HTML scraping. `/chiptuning/` (brands) → `/chiptuning/{brand}/` (model tiles) → `/chiptuning/{brand}/{model}/` (generation tiles, e.g. `8P Mk1 - 2003 -> 2008`) → generation page (engine tiles `a.zip-tegel` with `.zip-tegel__naam` = `2.0 TFSi 200 pk`) → engine page, gains parsed from text `Vermogen +101 pk` / `Koppel +161 Nm`.
- **rica.nl:** JSON via their WP gateway `rica.nl/wp-content/plugins/rica-api-connector/php/gateway.php?ep=GetVehicles&mode=MAKES|MODELS|VARIANTS|VEHICLE`. Gains = `MaxModPower - MaxBasePower`, `MaxModTorque - MaxBaseTorque` of the "Stage 1" product.
- **Matching is heuristic:** brand/model by normalized name ("1 series" = "1 Serie" = "1", "C-Class" = "C"), generation by year range + chassis code (`E8x` wildcard matches `E81`), engine by base power ±5 KM, displacement and diesel/petrol tokens. No match → tile shows "Brak danych".
- Listing pages/API lists cached 7 days as `vt_res_idx_{md5(url)}`; per-vehicle result cached as `vt_res_ext_{md5}` (+ stale backup), so "Wyczyść cache" and uninstall cover them.
- **Endpoint:** `GET /wp-json/vt/v1/external` — same params/validation/rate limit as `/result`. Response: `{ "ziptuning": {hp,nm,label,url}|null, "rica": {...}|null, "_source": "live|cache|stale|partial" }`.

---

## Vehicle tree JSON structure

```json
{
  "tree": {
    "alfa-romeo": {
      "label": "Alfa Romeo",
      "models": {
        "4c": {
          "label": "4C",
          "years": {
            "2014": {
              "label": "2014",
              "engines": [
                {
                  "value": "series-1::1-75-tb-240km",
                  "label": "1.75 TB 240KM - Series 1",
                  "gen_label": "Series 1",
                  "engine": "1.75 TB 240KM"
                }
              ]
            }
          }
        }
      }
    }
  }
}
```

`engine.value` = `"{genSlug}::{engineSlug}"`.  
`engine.engine` = clean display name without the generation suffix.

**Generation is not a top-level key in the tree.** It is extracted in JS by scanning all years' engines for unique `value.split('::')[0]` — see `getGenerations()` in `assets/js/widget.js`.

---

## REST API

**Endpoint:** `GET /wp-json/vt/v1/result`  
**Params:** `brand`, `model`, `gen`, `engine`, `year`  
**Slug validation:** `/^[a-z0-9][a-z0-9\-\.]*$/i` (dots allowed — e.g. `2.0-tsi-195kw`)  
**Rate limit:** 30 req/min per IP via transient `vt_rl_{md5(ip)}`

**Response shape:**
```json
{
  "powerchip":   { "hp": 50, "nm": 80, "chart_url": "https://sklep.vtech.pl/chart/9356" },
  "chip_tuning": { "hp": 29, "nm": 28, "chart_url": "https://sklep.vtech.pl/chart/9356" },
  "_source": "live"
}
```

`chart_url` is **per-product** (parsed from `.vt-fitment-chart-link[data-vt-modal-src]` within the same card container). `_source` is one of: `live`, `cache`, `stale`.

---

## Result popup

- Results open in a fixed-position overlay (`.vtk-result-overlay`) — never inline.
- **Title:** `Wyniki tuningu dla {Brand} {Model} {engineLabel} {year}` — engine label resolved from tree by `getEngineLabel()`.
- Cards: `powerchip` and `chip_tuning` rendered only when not null. If only one card, `vtk-cards--single` class added → card is centered.
- No "Sprawdź inny pojazd" (reset) button — removed.
- Close: × button, click backdrop, or Escape key.
- Dyno image opens in a second full-screen overlay (`.vtk-dyno-overlay`); Escape closes dyno first, then result modal.

---

## Admin settings (Ustawienia → VT Konfigurator)

Stored in `wp_options` key `vt_konfigurator_settings`:

| Key | Default | Notes |
|---|---|---|
| `source_url` | `https://sklep.vtech.pl/konfigurator-powerchip/` | Tree source URL |
| `accent_color` | `#e53e3e` | CSS `--vtk-accent` |
| `border_radius` | `8` | CSS `--vtk-radius` in px |
| `load_css` | `1` | Toggle plugin stylesheet |
| `cache_enabled` | `1` | Enable result transient cache |
| `cache_ttl` | `7` | Cache TTL in days |
| `phone_1_number` | `''` | Shown in customer popup if not empty |
| `phone_1_name` | `''` | Label next to phone 1 |
| `phone_2_number` | `''` | — |
| `phone_2_name` | `''` | — |
| `drive_url` | `''` | Google Drive link — shown in owner popup; NOT login-gated (Drive itself secures it) |

Safety check on tree refresh: new tree is rejected if new combinations count < 80% of previous (protects against broken vtech page structure).

Diagnostic section uses cascading dropdowns (Brand→Model→Gen→Engine→Year) powered by the stored tree JSON — no manual text inputs.

---

## File map

| File | Purpose |
|---|---|
| `vt-konfigurator.php` | Bootstrap, constants (`VT_OPTION_TREE`, `VT_CACHE_PREFIX`, …), requires, instantiates classes |
| `includes/class-vt-storage.php` | All `wp_options` I/O: tree, meta, settings, result transient cache + stale backup, error log |
| `includes/class-vt-fetcher.php` | `wp_remote_get` + DOMDocument/DOMXPath parsing for tree and result pages |
| `includes/class-vt-external.php` | ziptuning.nl / rica.nl lookup + vehicle matching (owner mode) |
| `includes/class-vt-ajax.php` | REST route registration, param validation, rate limiting, cache layer |
| `includes/class-vt-shortcode.php` | `[vt_konfigurator]` shortcode; `wp_localize_script` passes tree, REST URL, nonce, settings, i18n, phones, driveUrl |
| `admin/class-vt-admin.php` | WP settings page with 5 sections; cascading dropdown diagnostic |
| `assets/js/widget.js` | Vanilla JS: dropdowns, fetch, result popup, dyno lightbox |
| `assets/css/widget.css` | CSS custom properties; all classes prefixed `.vtk-` |
| `uninstall.php` | Removes all plugin options and transients on delete |
| `INSTRUKCJA.md` | Polish user manual with CSS class reference |

---

## CSS custom properties

```css
--vtk-accent        /* primary colour — buttons, gain values */
--vtk-accent-dark   /* hover shade */
--vtk-radius        /* border-radius */
--vtk-font
--vtk-bg
--vtk-border
--vtk-text
--vtk-muted         /* secondary labels */
--vtk-card-bg
--vtk-shadow
```

All widget classes are prefixed `.vtk-`. Full reference in `INSTRUKCJA.md` section 8.

---

## Key decisions / gotchas

- **`[hidden]` override:** Elementor/themes reset `[hidden]` to `display:block`. Plugin CSS has `.vtk-widget [hidden] { display: none !important; }`. If `load_css` is disabled, add this to the theme.
- **REST 404 on fresh WP:** requires non-plain permalinks. Fix: Settings → Permalinks → "Post name" → Save.
- **Multiple shortcodes per page:** supported. Each widget gets a unique random `uid` suffix on element IDs. Tree is loaded once via `wp_localize_script`.
- **Engine slugs with dots:** `2.0-tsi-195kw-265km-195` — validation regex allows `.`.
- **Stale cache:** even after TTL expiry, last known result is kept in `wp_options` (no expiry) and returned if live fetch fails.
- **`charts="1"` is the only mode flag** — do not add other flags for owner/customer distinction.
