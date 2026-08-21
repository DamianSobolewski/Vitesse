<?php
/**
 * Plugin Name: Vitesse — kalkulator flotowy
 * Description: Shortcode [vts_fleet_calc]. Liczenie po stronie klienta, współczynniki z opcji.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Współczynniki trzymamy w opcjach, żeby klient mógł zmienić cenę paliwa
 * bez wdrożenia: wp option update vts_fuel_price 8.10
 */
function vts_fleet_config(): array
{
    return [
        'fuel_price'   => (float) get_option('vts_fuel_price', 7.50),
        'saving_pct'   => (float) get_option('vts_eco_saving_pct', 6.5),
        'saving_min'   => (float) get_option('vts_eco_saving_min', 5),
        'saving_max'   => (float) get_option('vts_eco_saving_max', 8),
        'price_per_car'=> (float) get_option('vts_service_price_per_vehicle', 1500),
        'co2_per_l'    => (float) get_option('vts_co2_kg_per_litre', 2.68),
    ];
}

add_shortcode('vts_fleet_calc', function () {
    $c = vts_fleet_config();

    ob_start(); ?>
    <div class="vts-calc" data-vts-calc data-cfg="<?= esc_attr(wp_json_encode($c)) ?>">
      <div class="vts-calc__head">
        <h3>Ile zaoszczędzi Wasza flota?</h3>
        <p>Przesuńcie suwaki albo wpiszcie własne wartości.</p>
      </div>

      <?php
      $fields = [
          ['veh', 'Liczba pojazdów',            'szt.',     20,     1,   300,   1],
          ['km',  'Roczny przebieg na pojazd',  'km',       120000, 5000, 300000, 5000],
          ['fu',  'Średnie spalanie',           'l/100 km', 30,     4,   60,    0.5],
          ['pr',  'Cena paliwa',                'zł/l',     $c['fuel_price'], 3, 15, 0.1],
      ];
      foreach ($fields as [$k, $label, $unit, $val, $min, $max, $step]) : ?>
        <div class="vts-calc__fld">
          <div class="vts-calc__top">
            <label for="vts-<?= esc_attr($k) ?>"><?= esc_html($label) ?></label>
            <span class="vts-calc__in">
              <input id="vts-<?= esc_attr($k) ?>" type="number" data-num="<?= esc_attr($k) ?>"
                     value="<?= esc_attr($val) ?>" min="<?= esc_attr($min) ?>"
                     max="<?= esc_attr($max) ?>" step="<?= esc_attr($step) ?>">
              <em><?= esc_html($unit) ?></em>
            </span>
          </div>
          <input type="range" data-rng="<?= esc_attr($k) ?>" value="<?= esc_attr($val) ?>"
                 min="<?= esc_attr($min) ?>" max="<?= esc_attr($max) ?>" step="<?= esc_attr($step) ?>"
                 aria-label="<?= esc_attr($label) ?>">
        </div>
      <?php endforeach; ?>

      <div class="vts-calc__out">
        <div class="vts-calc__main">
          <div>
            <span class="vts-calc__lab">Oszczędność roczna</span>
            <p class="vts-calc__val" data-c="year">—</p>
          </div>
          <div class="vts-calc__side">
            <span class="vts-calc__lab">miesięcznie</span>
            <b data-c="month">—</b>
          </div>
        </div>
        <div class="vts-calc__grid">
          <div><span>Mniej paliwa rocznie</span><b data-c="litres">—</b></div>
          <div><span>Mniej CO₂ rocznie</span><b data-c="co2">—</b></div>
          <div><span>Zwrot kosztu usługi</span><b data-c="payback">—</b></div>
        </div>
      </div>

      <p class="vts-calc__disclaim">
        Wyliczenie szacunkowe: zakładamy oszczędność <?= esc_html(number_format_i18n($c['saving_pct'], 1)) ?>%
        (spotykany zakres <?= esc_html($c['saving_min']) ?>–<?= esc_html($c['saving_max']) ?>%)
        oraz koszt usługi <?= esc_html(number_format_i18n($c['price_per_car'])) ?> zł netto za pojazd —
        obie wartości ustalamy indywidualnie. Rzeczywisty wynik zależy od stanu technicznego pojazdów,
        tras i stylu jazdy. Nie stanowi oferty w rozumieniu Kodeksu cywilnego.
      </p>

      <a class="vts-btn vts-btn--primary vts-calc__cta"
         href="<?= esc_url(home_url('/kontakt/')) ?>">Zamów audyt floty</a>
    </div>
    <?php
    return ob_get_clean();
});
