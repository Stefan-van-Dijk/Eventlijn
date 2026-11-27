# Event Line installeren op sharon.life

## 1. Hosting voorbereiden

- Gebruik HTTPS, PHP 8.1 of nieuwer met sessies, JSON en de functie `mail()`, plus Apache 2.4 met ondersteuning voor `.htaccess` en `mod_headers`. Controleer met je host welke afzender PHP mag gebruiken en stel die afzender in.
- Maak via SSH of het hostingpaneel een eigen map voor Event Line **buiten alle publieke documentroots**. Stel rechten in op `0700` en geef het PHP-account lees- en schrijfrechten. Gebruik een lokale schijf met werkende bestandsvergrendeling. Event Line houdt zijn organisatiegegevens per organisatie bij, plus een aparte accountindex. Configureer reguliere versleutelde back-ups en test het terugzetten. Leg voor gebruik binnen de organisatie vast hoe lang je aanvragen en evaluaties bewaart en wie inzage en verwijdering afhandelt. Automatisch opschonen en encryptie van de actieve database regelt deze versie niet.
- Event Line draait op hetzelfde weborigin als andere software op `sharon.life`. Behandel alle scripts op dat origin als vertrouwd. Als andere partijen daar scripts kunnen publiceren, kies dan een apart domein of subdomein voor Event Line.

## 2. Uploaden en configureren

1. Pak het ZIP-bestand lokaal uit. Bewaar eventuele bestaande Event Line-configuratie en maak een back-up van een bestaande installatie en datamap.
2. Upload **de bestanden uit de hoofdmap van deze repository** naar de publieke map voor `https://sharon.life/eventline/`. Upload ook de verborgen `.htaccess`-bestanden uit de hoofdmap en `server/`. Laat de andere mappen op sharon.life staan.
3. Kopieer `server/config.example.php` op de host naar `server/config.local.php` en vul het absolute pad van de datamap in bij `DATA_DIR`. Zet `BASE_URL` op `https://sharon.life/eventline/` en geef bij `MAIL_FROM` het door de host geaccepteerde afzenderadres op. Gebruik `sendmail` als `MAIL_MODE` en laat `SHARON_URL` leeg voor zelfstandig gebruik. Dezelfde waarden kunnen als `EVENTLIJN_*`-omgevingsvariabelen worden ingesteld.
4. Open [de statuspagina](https://sharon.life/eventline/api/health.php). Verwacht HTTP 200 met `"storage":"configured"`. Een 503 wijst op ongeldige configuratie of een probleem met de afgeschermde opslag. Controleer ook dat een rechtstreekse HTTP-aanroep naar `/eventline/server/config.local.php` HTTP 403 geeft. Stel beide controles ook in als je nginx gebruikt, met expliciet geblokkeerde toegang tot `/eventline/server/`, uitgeschakelde directorylisting en dezelfde beveiligingsheaders als in de meegeleverde Apache-configuratie.
5. Open [Event Line](https://sharon.life/eventline/), registreer je organisatie met een beheerdersaccount en bevestig het e-mailadres vanuit je mailbox. Nodig vervolgens een leidinggevende en een medewerker uit. Laat beiden het adres bevestigen, activeer beide accounts als beheerder en wijs de leidinggevende toe. Doorloop daarna een testaanvraag en controleer de meldingen in alle betrokken mailboxen, inclusief de spammap.
6. Plan bij voorkeur elke vijf minuten een hostingtaak die de e-mailwachtrij opnieuw probeert. Gebruik hetzelfde PHP-account en geef de echte publieke documentroot op:

   ```sh
   php /absoluut/pad/naar/webroot/eventline/server/mail-retry.php /absoluut/pad/naar/webroot
   ```

   PHP probeert verzending ook direct na relevante handelingen. De taak rapporteert hoeveel berichten wachten op herhaling. Succesvolle overdracht aan `mail()` betekent dat de mailserver het bericht heeft geaccepteerd. Controleer de daadwerkelijke aflevering met een echte mailbox.

## 3. Sharon optioneel inschakelen

Volg `integrations/sharon/INSTALL.md` uit deze repository. In Event Line kan iemand na goedkeuring een privénotitie voor de eigen deelname naar Sharon meenemen. De gebruiker bevestigt de import in Sharon. Alleen titel, bezoekdag en locatie worden overgedragen. Besluiten, gegevens over de organisatie of aanvrager, evaluaties en spotlights blijven in Event Line.

## 4. Doorlopend beheer

Vervang bij volgende releases de applicatiebestanden en behoud `server/config.local.php` en de private datamap. Stop bij terugzetten eerst alle schrijfacties en herstel de accountindex en alle organisatiebestanden uit hetzelfde back-upmoment. Controleer de versie, sessies en e-mail na de update. Gebruik bij deze eerste versie één organisatie per e-mailadres. Accounts kunnen ook als leidinggevende zelf deelnemen zodra een andere actieve beoordelaar is toegewezen. Een beheerder die tegelijk leidinggevende is, legt beide besluiten expliciet vast.

Bij een bestaande installatie blijven het bestaande pad en de huidige instellingen bruikbaar. Gebruik voor `BASE_URL` het werkelijke adres van die installatie. De standaardroute die Sharon opent is `/eventline/`. Upload ook `openbaar.html` en `openbaar.js`. Gebruik op een testinstallatie een fictief evenement om als beheerder de openbare toegang aan en uit te zetten. Controleer de link in een privévenster en herlaad na intrekken. Bij de eerste expliciete openbaarmaking van een ouder evenement bewaart Event Line de nieuwe ID12 in het bestaande organisatiebestand.


## Inloggen met een Sharon-account

Voor de koppeling draait de nieuwe Sharon-endpoint op `https://sharon.life/log/api/connections.php`. Gebruik aan beide kanten dezelfde willekeurige privésleutel van 32 tot 128 letters, cijfers, `_` of `-`. Maak de sleutel met een cryptografische generator en bewaar hem op de servers.

- Stel bij Sharon `SHARON_EVENTLINE_KEY` in, of bewaar `['eventlineKey' => '...']` in `/log/private/account-config.php` volgens de Sharon-installatie-instructie.
- Stel bij Event Line `EVENTLIJN_SHARON_APP_KEY` in, of vul `SHARON_APP_KEY` in `server/config.local.php` in. De sleutel hoort uitsluitend in de private configuratie. De voorbeeldconfiguratie bevat een lege waarde.
- PHP moet cURL met geldige CA-certificaten en uitgaande HTTPS naar `sharon.life` ondersteunen. Behoud de standaard certificaatcontrole. De server wisselt de eenmalige code in op het vaste Sharon-adres.
- Upload `.htaccess`, `index.html`, `eventlijn.js`, `api/index.php` en de gewijzigde serverbestanden. Apache moet de `Authorization`-header doorgeven aan PHP. De meegeleverde `.htaccess` gebruikt `mod_setenvif`. Controleer deze doorgifte ook bij nginx of PHP-CGI.

Open Event Line vanuit een verbonden strook in Sharon. Sharon geeft een eenmalige code van maximaal één minuut door aan het iframe. Event Line wisselt deze code op de server in en zoekt het bijbehorende geverifieerde e-mailadres. Een bestaand actief of wachtend Event Line-account behoudt zijn organisatie, rol en goedkeuringsstatus. Een onbevestigd of geweigerd account vraagt eerst afhandeling via de bestaande toegang. Bij een nieuw adres maakt Event Line een persoonlijke werkomgeving aan.

Binnen Sharon gebruikt Event Line een aparte appaanmelding van twee uur. Het geheime apptoken blijft uitsluitend in het geheugen van de pagina. De eenmalige code wordt vooraf op de server ingewisseld. Daarmee werkt de koppeling ook wanneer de browser cookies van een ingesloten website blokkeert. Uitloggen trekt de appaanmelding in. Een wachtwoordwijziging of ingetrokken lidmaatschap maakt bestaande appaanmeldingen ongeldig. Zelfstandig gebruik houdt de bestaande cookieaanmelding en CSRF-controles.

Controleer op een testinstallatie een bestaand account, een nieuw persoonlijk account en een account dat op goedkeuring wacht. Test de koppeling op Safari/iPhone vanuit Sharon, met geblokkeerde cookies van derden, na uitloggen en met een verlopen code. Controleer dat een eerdere zelfstandige Event Line-aanmelding de Sharon-identiteit niet overschrijft. Bij een fout toont Event Line een melding en kun je terug naar Sharon. De gedeelde sleutel en configuratie blijven bij een applicatie-update bewaard.

Voer voor publicatie de volgende controles uit vanuit de projectmap.

```sh
php tests/storage.php
php tests/sharon_accounts.php
python3 tests/http_flows.py
python3 tests/sharon_http.py
node --check eventlijn.js
```
