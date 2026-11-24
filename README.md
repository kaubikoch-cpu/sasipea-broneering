# Sasipea Broneering

Sasipea Akadeemia online-broneerimine ja päevaplaan WordPressi pluginana. Versioon 0.1.0 (1. etapi esimene töötav versioon).

**NB! Paigalda esmalt staging-keskkonda, mitte päris lehele.**

## Paigaldus

1. WordPressi adminis: Pluginad → Lisa uus → Laadi plugin üles → vali `sasipea-broneering.zip` → Aktiveeri.
2. Loo leht aadressiga `/broneeri/` ja lisa sinna (Elementoris "Shortcode" vidinaga) lühikood `[sasipea_broneering]`.
3. Soovi korral loo leht `/praktikant/` lühikoodiga `[sasipea_praktikant]`. Seal näeb sisseloginud praktikant oma graafikut.
4. Ava Broneeringud → Praktikandid, kustuta või nimeta ümber kaks näidispraktikanti ja lisa päris praktikandid koos töögraafikuga.
5. Ava Broneeringud → Seaded ja kontrolli lahtiolekuajad, reeglid, privaatsuspoliitika aadress ja e-kirjade mallid.

Nõuded: PHP 8.1+, WordPress 6.2+, MySQL 5.7+ või MariaDB 10.3+ (InnoDB).

## Mis on sees

- **Avalik broneerimine** (`[sasipea_broneering]`): teenus → praktikant ("Ükskõik kes" või konkreetne) → kuupäev ja kellaaeg → kontaktandmed ja nõusolekud → kinnitus koos "Lisa kalendrisse" (.ics) ja "Tühista või muuda aega" lingiga.
- **E-kirjad**: kliendile kinnitus (.ics manusega), meeldetuletus 24 h enne, teade aja muutmise ja tühistamise kohta; salongile teade uue, muudetud ja tühistatud broneeringu kohta. Mallid on muudetavad Seadete lehel.
- **Päevaplaan** (Broneeringud → Päevaplaan): iga praktikant on veerg, päeva- ja nädalavaade, uue broneeringu loomine, lohistamine teisele ajale või praktikandile, staatused, blokeeringud ühele praktikandile või kogu salongile.
- **Haldus**: praktikandid (töögraafik, päevade erandid, teenused, seotud WP kasutaja), teenused ja kategooriad, kliendid (otsing, külastuste ajalugu, märkmed), seaded.
- **Rollid**: admin teeb kõike. Roll, mille nimes on "juhendaja", haldab kõiki broneeringuid. Uus roll "Praktikant" näeb ainult enda veergu.
- **Isikuandmed**: iga nõusolek salvestatakse aja ja tekstiversiooniga; 2 aastat pärast viimast kontakti anonüümitakse klient automaatselt; WordPressi isikuandmete eksport ja kustutamine on toetatud.

## Päris cron jagatud majutusel

WordPressi sisseehitatud cron käivitub ainult siis, kui keegi lehte külastab. Et meeldetuletused läheksid õigel ajal:

1. Lisa `wp-config.php` faili: `define( 'DISABLE_WP_CRON', true );`
2. Lisa majutuse halduspaneelis (Zone: Veebiserver → Crontab; cPanel: Cron Jobs) töö, mis käivitub iga 15 minuti järel:

   ```
   */15 * * * * wget -q -O /dev/null "https://sasipeaakadeemia.ee/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
   ```

## E-kirjade kohaletoimetamine

Kirjad saadetakse `wp_mail` kaudu aadressilt info@oppesalong.ee. Et need rämpsposti ei läheks, seadista SMTP-plugin (nt WP Mail SMTP) saatma läbi teie enda postiserveri ning kontrolli, et domeenil oppesalong.ee on SPF ja DKIM kirjed.

## Topeltbroneeringu vältimine

Iga salvestamine (avalik vorm, admin, lohistamine) võtab andmebaasis salongi ühise luku (`GET_LOCK`), alustab transaktsiooni ja arvutab aja vabaduse uuesti. Ühine lukk on vajalik, sest juhendaja maht (korraga juhendatavaid kliente) kehtib üle kõigi praktikantide.

## Testid

```
php tests/run.php          # ilma lisatarkvarata
composer install && composer test   # PHPUnitiga
```

Testid katavad vaba aja arvutuse: tööaeg, pausid, puhver, kattumised, juhendaja maht, etteteatamine ja suveaja vahetus.

## Käsitsi testimise nimekiri

Avalik vorm (telefonis):
- [ ] Teenuste nimekiri, kestused ja hinnad on õiged; "+ materjal" on õigete teenuste juures.
- [ ] E, L ja P on kalendris hallid; T ja N pakuvad aegu 12–19, K ja R 10–16.
- [ ] Viimane pakutav algusaeg lõpeb enne sulgemist (nt 5 h triibutamine T: viimane 14:00).
- [ ] Tühja vormi saatmisel on iga välja juures veateade.
- [ ] Pärast broneerimist tuleb kinnitus ekraanile ja e-postile; .ics avaneb kalendris õige kellaajaga.
- [ ] Kirja link "Tühista või muuda aega" avab broneeringu; aja muutmine ja tühistamine töötavad.
- [ ] Vähem kui 24 h enne algust enam tühistada ei saa ja kuvatakse telefoninumber.
- [ ] Kaks inimest broneerivad korraga sama aja samale praktikandile: üks saab teate, et aeg pole enam vaba.

Admin:
- [ ] Päevaplaanis on uus broneering õige praktikandi veerus.
- [ ] Lohistamine teisele ajale ja teisele praktikandile; hõivatud ajale lohistades tuleb veateade.
- [ ] Staatused Kohal, Ei tulnud, Tühistas salong.
- [ ] Blokeering kogu salongile: avalikus vormis sel ajal aegu ei pakuta.
- [ ] Praktikandi päeva erand (vaba päev) eemaldab tema ajad avalikust vormist.
- [ ] Praktikandi rolliga kasutaja näeb ainult enda veergu ega saa midagi muuta.
- [ ] Salongi teavituskirjad jõuavad aadressile info@oppesalong.ee.

## Mis on veel tegemata

- Otsene sidumine pluginaga `sasipea-modellid` (ootab selle koodi). Praegu on olemas filtrid `sb_find_model_id`, `sb_model_db_optin` ja `sb_model_admin_url`.
- Saloninfra CSV-import.
- Tõlkefail (.pot). Kõik tekstid PHP-s on tõlgitavad; JavaScripti tekstid on praegu otse eesti keeles.
- 2. etapp: ICS-kalendrivood, materjali arvestus, aruanded, ootenimekiri, SMS.

## Üleminek Saloninfrast

- [ ] Staging-keskkonnas läbi testitud.
- [ ] Tulevased Saloninfra broneeringud sisestatud päevaplaani (või imporditud, kui import on valmis).
- [ ] Kodulehe "Broneeri" lingid viivad lehele `/broneeri/`.
- [ ] Facebooki lehe nupp "Book now" viib lehele `/broneeri/`.
- [ ] Google'i ettevõtteprofiili broneerimislink on vahetatud.
- [ ] Saloninfra online-broneering on suletud.
