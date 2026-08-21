<?php
/**
 * Mapa starych adresów .php na nową strukturę.
 * Zwykła tablica — wersjonuje się w gicie, da się przejrzeć w code review.
 * Katalog (?auto=…) obsługuje reguła w vts-redirects.php, nie ta mapa.
 */

return [
    'index.php'                                   => '/',

    // osobowe
    'osobowe.php'                                 => '/podnoszenie-mocy/chip-tuning/samochody-osobowe/',
    'osobowe_chiptuning_powerbox.php'             => '/podnoszenie-mocy/powerboxy/',
    'osobowe_ograniczniki.php'                    => '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
    'osobowe_wylaczanie_fap_dpf_scr.php'          => '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
    'osobowe_wylaczanie_ogranicznikow.php'        => '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
    'osobowe_hamownia_podwoziowa.php'             => '/hamownia/',
    'osobowe_hamownia.php'                        => '/hamownia/',

    // dostawcze
    'dostawcze.php'                               => '/podnoszenie-mocy/chip-tuning/samochody-dostawcze/',
    'dostawcze_chiptuning_powerbox.php'           => '/podnoszenie-mocy/powerboxy/',
    'dostawcze_ograniczniki.php'                  => '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
    'dostawcze_wylaczanie_fap_dpf_scr.php'        => '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
    'dostawcze_fap_dpf_scr.php'                   => '/podnoszenie-mocy/dodatkowe-uslugi-ecu/',
    'dostawcze_hamownia_podwoziowa.php'           => '/hamownia/',
    'dostawcze_hamownia.php'                      => '/hamownia/',

    // ciężarowe i autobusy
    'ciezarowe.php'                               => '/podnoszenie-mocy/chip-tuning/ciezarowe-i-autobusy/',
    'ciezarowe_chiptuning.php'                    => '/podnoszenie-mocy/chip-tuning/ciezarowe-i-autobusy/',
    'ciezarowe_chip_tuning.php'                   => '/podnoszenie-mocy/chip-tuning/ciezarowe-i-autobusy/',
    'ciezarowe_hamownia_podwoziowa.php'           => '/hamownia/',
    'ciezarowe_hamownia.php'                      => '/hamownia/',
    'autobusy.php'                                => '/podnoszenie-mocy/chip-tuning/ciezarowe-i-autobusy/',
    'autobusy_chiptuning.php'                     => '/podnoszenie-mocy/chip-tuning/ciezarowe-i-autobusy/',
    'autobusy_hamownia_podwoziowa.php'            => '/hamownia/',
    'autobusy_hamownia.php'                       => '/hamownia/',

    // pozostałe kategorie
    'chiptuning_ev_box_range_extender_samochody_elektryczne.php' => '/ev-hybryda/',
    'chip_tuning_traktor_lodz_skuter.php'         => '/podnoszenie-mocy/chip-tuning/ciagniki-i-maszyny/',
    'chip_tuning_czy_power_box.php'               => '/podnoszenie-mocy/powerboxy/',

    // cennik, promocje, kalkulator
    'chiptuning_cennik.php'                       => '/podnoszenie-mocy/chip-tuning/',
    'promocje.php'                                => '/podnoszenie-mocy/',
    'kalkulator.php'                              => '/podnoszenie-mocy/oferta-dla-flot/kalkulator-oszczednosci/',

    // o firmie
    'opinie.php'                                  => '/o-nas/',
    'media.php'                                   => '/o-nas/',
    'certyfikat.php'                              => '/o-nas/',
    'gwarancja.php'                               => '/gwarancja/',
    'prywatnosc.php'                              => '/polityka-prywatnosci/',

    // wycofany serwis mechaniczny — 301 na stronę wyjaśniającą, nie 410 i nie na stronę główną
    'serwis_scania_volvo.php'                     => '/informacja/serwis-mechaniczny-pojazdow-ciezarowych/',
];
