# Sasipea Broneering

Sasipea Akadeemia online-broneerimine ja päevaplaan WordPressi pluginana. Versioon 0.13.0 (1. etapp + kuu töögraafik, tasemed, tasude arvestus, veebipoe ladu, käibemaksu aruanne, oma töölaud /salong/ sidumine modellide andmebaasiga ja kassa).

**NB! Paigalda esmalt staging-keskkonda, mitte päris lehele.**

## Paigaldus

1. WordPressi adminis: Pluginad → Lisa uus → Laadi plugin üles → vali `sasipea-broneering.zip` → Aktiveeri.
2. Loo leht aadressiga `/broneeri/` ja lisa sinna (Elementoris "Shortcode" vidinaga) lühikood `[sasipea_broneering]`.
3. Soovi korral loo leht `/juuksuriõpilane/` lühikoodiga `[sasipea_praktikant]`. Seal näeb sisseloginud juuksuriõpilane oma graafikut.
4. Ava Broneeringud → Juuksuriõpilased, kustuta või nimeta ümber kaks näidisõpilast ja lisa päris juuksuriõpilased koos taseme ja nädalapõhjaga.
5. Ava Broneeringud → Seaded ja kontrolli lahtiolekuajad, reeglid, privaatsuspoliitika aadress ja e-kirjade mallid.

Nõuded: PHP 8.1+, WordPress 6.2+, MySQL 5.7+ või MariaDB 10.3+ (InnoDB).

## Mis on sees

- **Avalik broneerimine** (`[sasipea_broneering]`): teenus → juuksuriõpilane ("Ükskõik kes" või konkreetne) → kuupäev ja kellaaeg → kontaktandmed ja nõusolekud → kinnitus koos "Lisa kalendrisse" (.ics) ja "Tühista või muuda aega" lingiga.
- **E-kirjad**: kliendile kinnitus (.ics manusega), meeldetuletus 24 h enne, teade aja muutmise ja tühistamise kohta; salongile teade uue, muudetud ja tühistatud broneeringu kohta. Mallid on muudetavad Seadete lehel.
- **Päevaplaan** (Broneeringud → Päevaplaan): iga juuksuriõpilane on veerg, päeva- ja nädalavaade, uue broneeringu loomine, lohistamine teisele ajale või juuksuriõpilasele, staatused, blokeeringud ühele juuksuriõpilasele või kogu salongile.
- **Kuu töögraafik** (Broneeringud → Töögraafik): juhendaja avab kuu, märgib linnukesega, kes mis päeval töötab, muudab vajadusel kellaaegu ja salvestab. Kuu on eeltäidetud iga juuksuriõpilase nädalapõhjaga. Lahtris on näha, mitu broneeringut sel päeval juba on. Seadetes saab sisse lülitada, et aegu pakutakse ainult koostatud graafikuga kuudes.
- **Juuksuriõpilaste tasemed** (Seaded → Juuksuriõpilaste tasemed): igal tasemel on soodustus teenuse hinnast (%) ja lisaaeg teenuse kestusele (min). Vaikimisi Noorempraktikant (−10%, +30 min) ja Vanempraktikant. Juuksuriõpilasele saab määrata taseme ja vajadusel isikliku erisuse. Avalik vorm näitab iga juuksuriõpilase juures tema hinda ja kestust ning vaba aja arvutus kasutab pikemat kestust.
- **Materjal ja tooted**: päevaplaanis saab broneeringu juurde märkida kulunud materjali (grammides, hind Seadetes) ja müüdud tooted (Broneeringud → Tooted). Toote saab müüa ka ilma broneeringuta Tasude lehel.
- **Tasud** (Broneeringud → Tasud, ainult admin): iga juuksuriõpilase lehel määratakse tasu eraldi teenustelt, materjali müügilt ja toodete müügilt, kas protsendina käibest või kindla summana. Kuu aruanne näitab juuksuriõpilase kaupa käivet ja tasu, alusridu ning lubab CSV-eksporti. Teenus läheb arvesse, kui broneeringu staatus on "Kohal". See on tasu arvestus, mitte palgaleht: makse ei arvutata. Kui Seadetes on lubatud, näeb juuksuriõpilane menüüs "Minu tasu" enda kuu kokkuvõtet.
- **Veebipoe ladu** (Seaded → Veebipoe ladu): ühendus WooCommerce'i poega selle REST API kaudu. Päevaplaanis saab toodet otsida poest nime, tootekoodi või skännitud triipkoodi järgi; näha on hind ja laoseis. Müük salvestatakse poodi lõpetatud tellimusena ("Makstud salongis"), nii et WooCommerce arvab koguse ise laost maha. Müügirea eemaldamisel tellimus tühistatakse ja ladu taastub.
- **Käibemaks** (Seaded → Käibemaks, aruanne Broneeringud → Käibemaks): määrade ajalugu kujul "kehtib alates + määr". Kuu aruanne näitab teenuste, materjali ja toodete käivet käibemaksuga, käibemaksuta ja käibemaksu eraldi, koos CSV-ga. Kuni määra pole lisatud, käibemaksu ei arvestata. Veebipoe laost müüdud tooted on veebipoe ettevõtte tellimused ja käive, seega neid salongi käibemaksu aruandes ei ole (need on eraldi real teadmiseks).
- **Haldus**: juuksuriõpilased (tase, nädalapõhi, päevade erandid, teenused, seotud WP kasutaja), teenused ja kategooriad, kliendid (otsing, külastuste ajalugu, märkmed), seaded.
- **Rollid**: admin teeb kõike. Roll, mille nimes on "juhendaja", haldab kõiki broneeringuid ja töögraafikut. Roll "Juuksuriõpilane" logib sisse ja näeb oma veergu; Seadete jaotises "Juuksuriõpilase vaade" määratakse, kas ta näeb kliendi telefoni, hinda, e-posti ja kommentaari ning teiste juuksuriõpilaste veerge. Muuta ei saa ta midagi.
- **Isikuandmed**: iga nõusolek salvestatakse aja ja tekstiversiooniga; 2 aastat pärast viimast kontakti anonüümitakse klient automaatselt; WordPressi isikuandmete eksport ja kustutamine on toetatud.

## Töölaud (/salong/)

Töölaud on oma kujundusega vaade väljaspool WordPressi adminni. Aadress on vaikimisi `/salong/` (muudetav: Seaded → Salongi andmed ja lingid → Töölaua aadressi lõpp). Lehte ei ole vaja WordPressis luua, plugin näitab selle ise ilma teema ja admini ribata. Kui püsilingid on välja lülitatud, töötab aadress `/?sb_toolaud=1`.

- Sisselogimine käib töölaua enda vormiga (WordPressi kasutajanimi või e-post ja parool). Katseid piiratakse: 10 katset 10 minuti jooksul.
- Menüü sõltub rollist:
  - **Juuksuriõpilane** (roll "Juuksuriõpilane"): Avaleht, Päevaplaan (ainult see, mida juhendaja on lubanud), Minu tasu (kui lubatud), Minu konto.
  - **Juhendaja** (roll "Salongi juhendaja" või modellide plugina juhendaja): lisaks Töögraafik ja Kliendid.
  - **Omanik** (roll "Salongi omanik" või administraator): lisaks Tasud, Käibemaks, Tooted, Juuksuriõpilased, Teenused, Seaded.
- Keelatud vaate aadressi käsitsi avamine annab teate "Seda vaadet sul näha ei ole lubatud" (403). Õigusi kontrollitakse serveris nii vaate näitamisel kui ka salvestamisel.
- Rollidega "Juuksuriõpilane", "Salongi juhendaja" ja "Salongi omanik" kasutajad WP adminni ei pääse: nad suunatakse töölauale ja admini riba neile ei näidata. Administraator näeb endiselt mõlemat.
- "Minu konto" all saab igaüks oma parooli muuta.
- Kasutajakontod loob administraator WordPressis (Kasutajad → Lisa uus) ja valib rolli. Juuksuriõpilase konto seotakse juuksuriõpilasega lehel Juuksuriõpilased.

## Modellide andmebaas (plugin sasipea-modellid)

Kui samal lehel on aktiivne plugin `sasipea-modellid`, seob broneering end sellega ise (seadistada pole midagi):

- Uue broneeringu aknas on plokk "Vali modell andmebaasist". Näidatakse valitud teenusele sobivaid modelle (teenuse nimi võrreldakse modellide teenusekategooriatega, nt "Juukselõikus" ja "Lõikus"), ees need, kellega pole kõige kauem ühendust võetud. Linnuke "näita kõiki" eemaldab teenuse filtri. Arhiveeritud modelle ei näidata.
- Modellile tehtud broneering uuendab tema viimase kontakti kuupäeva (see lükkab edasi ka automaatse kustutamise tähtaega).
- Klient, kelle e-post või telefon klapib modelliga, seotakse modelliga ise. Broneeringu kaardil on märge "modell" lingiga modelli kaardile.
- Avaliku vormi linnuke "Lisa mind ka tulevikus modellide andmebaasi" loob uue modelli olekuga "Uus" ja allikaga "broneering", kui teda andmebaasis veel ei ole. Kategooriad ja pildid lisab juhendaja hiljem.
- Modelle näevad ainult juhendaja ja omanik (õigus `sb_manage_bookings`), juuksuriõpilane mitte. Andmed loetakse otse sama lehe andmebaasist ega liigu lehelt välja.
- Modellide plugina juhendaja roll (`sam_juhendaja`) saab broneeringute juhendaja õigused ja võib kasutada töölauda /salong/.

## Kassa

Kassa on juhendajale ja omanikule (õigus `sb_manage_bookings`), töölaual menüüs "Kassa" ja WP adminis Broneeringud → Kassa.

- Päeva vaade näitab maksmata broneeringuid ("Ootab maksmist"), makstud arveid ja päeva käivet makseviiside kaupa (sularaha, kaart, ülekanne).
- "Kassasse" (päeva vaates või päevaplaanis broneeringu kaardil) avab arve: teenus on juba real, juurde saab lisada materjali grammides, oma tooteid ja poe laost tooteid: otsing nime, triipkoodi või tootekoodi järgi näitab leide koos hinna ja laoseisuga (triipkoodilugejaga piisab skännimisest ja Enterist). Päevaplaanis broneeringule varem lisatud materjal ja tooted tulevad arvele kaasa.
- Teenuse hinda saab kassas muuta (soodustus). Makse lõpetamisel salvestatakse hind broneeringule, broneering läheb staatusesse "Kohal" ja teenus jõuab tasude arvestusse.
- "Müük ilma broneeringuta" teeb arve ainult toodetest või materjalist; müüjaks valitud juuksuriõpilane saab müügi oma tasude arvestusse.
- Kviitung saadetakse kliendile e-postiga kohe pärast makset. Aadress on eeltäidetud broneeringu e-postiga ja seda saab enne makset muuta või tühjaks jätta. Kviitungi lehelt saab selle uuesti saata (ka teisele aadressile) või printida. Makse saab tühistada ainult omanik (arve avaneb uuesti).
- "Kustuta arve" eemaldab arve koos ridadega. Poe laost müüdud toodete tellimused tühistatakse ja tooted lähevad lattu tagasi; broneering jääb alles ja ootab uuesti maksmist. Avatud arve saab kustutada juhendaja, makstud arve ainult omanik. Kui poe tellimuse tühistamine ei õnnestu, jääb arve alles.
- Makse võib jagada sularaha, kaardi ja ülekande vahel: iga makseviisi reale käib summa, mille klient sellega annab, ja "Veel maksta" näitab jääki. Sularaha reale käib kliendi tegelikult antud summa: kui kokku tuleb rohkem kui arve, näitab kassa "Tagasi anda" ja sularahana läheb kirja ainult arve katmiseks kulunud osa. Nupp "Kogu jääk" paneb reale täpselt järelejäänud summa. Päeva käive ja kviitung näitavad jagatud makset makseviiside kaupa.
- Kassa ei suhtle kaarditerminali ega pangaga: makseviis on märge selle kohta, kuidas klient maksis. Kviitung ei ole ametlik arve (ettevõtte registrikoodi ja käibemaksu ridu sellel ei ole).

## Broneerimisvorm ja töögraafik

- Esimesel sammul on teenuste grupid üksteise all; grupile vajutades avanevad selle teenused (laial ekraanil paremal kõrval, telefonis grupi all).
- Kalendris saab liikuda ainult nende kuude vahel, mille töögraafik on koostatud (Seaded → "Aegu pakutakse ainult nendes kuudes..."; alates 0.9.3 vaikimisi sees). Viimase koostatud kuu all on teade, et hilisemate kuude graafik on koostamisel ja kaugema aja jaoks võib kirjutada e-postile. Kui ühtegi kuud pole koostatud, näeb klient vabandavat teadet kalendri asemel. Aadress on seadete "Kirjade saatja e-post".

## Kasutajad ja sisselogimine

- Töölaua vaates **Kasutajad** (ainult omanik) saab luua juuksuriõpilase, juhendaja ja omaniku kontosid, muuta nime, e-posti ja rolli, siduda konto juuksuriõpilasega ning konto peatada või taastada. Peatatud konto logitakse kohe välja ja sisse ta enam ei saa; andmed jäävad alles.
- Parooli ei määra ega näe keegi peale kasutaja enda. Uus kasutaja saab e-kirja lingiga (kehtib 24 tundi), kust ta seab töölaual endale parooli. "Saada kutse uuesti" või "Saada parooli link" saadab uue lingi.
- Hallata saab ainult selle plugina rollidega kontosid. WordPressi administraatoreid ja modellide andmebaasi juhendajaid näidatakse nimekirjas, aga muuta saab neid ainult WordPressis. Enda rolli muuta ega ennast peatada ei saa.
- **Sisselogimine Google'i kontoga** (Seaded → Sisselogimine Google'i kontoga): Google Cloud Console'is tuleb luua OAuth klient (Web application) ja lisada seadetes näidatud tagasisuunamise aadress. Sisse saab ainult see, kelle Google'i kinnitatud e-post on sama mis olemasoleva töölaua konto e-post; uusi kontosid Google'i kaudu ei teki. Administraatori konto Google'iga sisse ei saa, kui seda eraldi ei lubata.
- Töögraafikus avab kuupäeval klikkimine selle päeva päevaplaani.

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

Iga salvestamine (avalik vorm, admin, lohistamine) võtab andmebaasis salongi ühise luku (`GET_LOCK`), alustab transaktsiooni ja arvutab aja vabaduse uuesti. Ühine lukk on vajalik, sest juhendaja maht (korraga juhendatavaid kliente) kehtib üle kõigi juuksuriõpilaste.

## Veebipoe ühenduse seadistamine

1. Poe adminis (sasipea.ee): WooCommerce → Seaded → Täpsemalt → REST API → Lisa võti. Kirjeldus nt "Akadeemia broneering", õigus "Lugemine/kirjutamine".
2. Kopeeri Consumer key ja Consumer secret plugina seadetesse (Broneeringud → Seaded → Veebipoe ladu) koos poe aadressiga. Ühendus töötab ainult üle HTTPS-i.
3. Seadete lehel peab ilmuma "Ühendus poega töötab".

NB! Proovi esmalt ühe odava tootega: müü, kontrolli poes tellimust ja laoseisu, eemalda müük ja kontrolli, et tellimus tühistus ja ladu taastus. Triipkoodiga otsing kasutab poe GTIN/EAN välja ja varuna SKU välja (osal toodetel on triipkood seal). Otsing käib tootenimekirja koopiast, mis uueneb kord ööpäevas ja nupuga "Uuenda tootenimekirja"; esimest korda tuleb nuppu ise vajutada. WooCommerce võib iga salongimüügi kohta saata poe administraatorile "uus tellimus" kirja.

## Testid

```
php tests/run.php          # ilma lisatarkvarata
composer install && composer test   # PHPUnitiga
```

Testid katavad vaba aja arvutuse (tööaeg, pausid, puhver, kattumised, juhendaja maht, etteteatamine, suveaja vahetus, juuksuriõpilase lisaaeg) ning taseme soodustuse, lisaaja, tasude ja käibemaksu arvutuse.

## Käsitsi testimise nimekiri

Avalik vorm (telefonis):
- [ ] Teenuste nimekiri, kestused ja hinnad on õiged; "+ materjal" on õigete teenuste juures.
- [ ] E, L ja P on kalendris hallid; T ja N pakuvad aegu 12–19, K ja R 10–16.
- [ ] Viimane pakutav algusaeg lõpeb enne sulgemist (nt 5 h triibutamine T: viimane 14:00).
- [ ] Tühja vormi saatmisel on iga välja juures veateade.
- [ ] Pärast broneerimist tuleb kinnitus ekraanile ja e-postile; .ics avaneb kalendris õige kellaajaga.
- [ ] Kirja link "Tühista või muuda aega" avab broneeringu; aja muutmine ja tühistamine töötavad.
- [ ] Vähem kui 24 h enne algust enam tühistada ei saa ja kuvatakse telefoninumber.
- [ ] Kaks inimest broneerivad korraga sama aja samale juuksuriõpilasele: üks saab teate, et aeg pole enam vaba.

Admin:
- [ ] Päevaplaanis on uus broneering õige juuksuriõpilase veerus.
- [ ] Lohistamine teisele ajale ja teisele juuksuriõpilasele; hõivatud ajale lohistades tuleb veateade.
- [ ] Staatused Kohal, Ei tulnud, Tühistas salong.
- [ ] Blokeering kogu salongile: avalikus vormis sel ajal aegu ei pakuta.
- [ ] Juuksuriõpilase päeva erand (vaba päev) eemaldab tema ajad avalikust vormist.
- [ ] Juuksuriõpilase rolliga kasutaja näeb ainult enda veergu ega saa midagi muuta; "Juuksuriõpilase vaate" linnukesed peidavad ja näitavad telefoni, hinda ja kommentaari.
- [ ] Töögraafik: uue kuu salvestamine, vaba päev ja lühem päev jõuavad avalikku vormi ja päevaplaani.
- [ ] Noorempraktikandi juures on hind soodsam ja teenus pikem; kinnitusel on õige lõpuaeg ja hind.
- [ ] Broneeringu lohistamine teise taseme juuksuriõpilasele arvutab hinna ja kestuse ümber; ainult aja muutmine neid ei muuda.
- [ ] Materjali (grammid) ja toote lisamine broneeringu juurde; summa on õige ja rea saab eemaldada.
- [ ] Tasude lehel on kuu kokkuvõte õige nii protsendi kui kindla summaga; CSV avaneb Excelis täpitähtedega.
- [ ] Juuksuriõpilane näeb "Minu tasu" ainult siis, kui see on Seadetes lubatud, ja ainult enda rida.
- [ ] Poe toote otsing nime ja triipkoodiga; müük tekitab poes tellimuse ja vähendab laoseisu; eemaldamine tühistab tellimuse.
- [ ] Otsas olevat toodet müüa ei saa; laoseisust suurem kogus annab veateate.
- [ ] Käibemaksu aruanne: summad klapivad Tasude lehe käibega; enne määra algust on käibemaks 0.
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
