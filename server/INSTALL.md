# Event Line installeren op sharon.life

## 1. Hosting voorbereiden

- Gebruik HTTPS, PHP 8.1 of nieuwer met sessies, JSON en de functie `mail()`, plus Apache 2.4 met ondersteuning voor `.htaccess` en `mod_headers`. Controleer met je host welke afzender PHP mag gebruiken en stel die afzender in.
- Maak via SSH of het hostingpaneel een eigen map voor Event Line **buiten alle publieke documentroots**. Stel rechten in op `0700` en geef het PHP-account lees- en schrijfrechten. Gebruik een lokale schijf met werkende bestandsvergrendeling. Event Line houdt zijn organisatiegegevens per organisatie bij, plus een aparte accountindex. Configureer reguliere versleutelde back-ups en test het terugzetten. Leg voor gebruik binnen de organisatie vast hoe lang je aanvragen en evaluaties bewaart en wie inzage en verwijdering afhandelt. Automatisch opschonen en encryptie van de actieve database regelt deze versie niet.
- Event Line draait op hetzelfde weborigin als andere software op `sharon.life`. Behandel alle scripts op dat origin als vertrouwd. Als andere partijen daar scripts kunnen publiceren, kies dan een apart domein of subdomein voor Event Line.

## 2. Uploaden en configureren

1. Pak het ZIP-bestand lokaal uit. Bewaar eventuele bestaande Event Line-configuratie en maak een back-up van een bestaande installatie en datamap.
2. Upload **de inhoud van de map `eventlijn/`** naar de publieke map voor `https://sharon.life/eventlijn/`. Upload ook de verborgen `.htaccess`-bestanden in `eventlijn/` en `eventlijn/server/`. Laat de andere mappen op sharon.life staan.
3. Kopieer `server/config.example.php` op de host naar `server/config.local.php` en vul het absolute pad van de datamap in bij `DATA_DIR`. Zet `BASE_URL` op `https://sharon.life/eventlijn/` en geef bij `MAIL_FROM` het door de host geaccepteerde afzenderadres op. Gebruik `sendmail` als `MAIL_MODE` en laat `SHARON_URL` leeg voor zelfstandig gebruik. Dezelfde waarden kunnen als `EVENTLIJN_*`-omgevingsvariabelen worden ingesteld.
4. Open [de statuspagina](https://sharon.life/eventlijn/api/health.php). Verwacht HTTP 200 met `"storage":"configured"`. Een 503 wijst op ongeldige configuratie of een probleem met de afgeschermde opslag. Controleer ook dat een rechtstreekse HTTP-aanroep naar `/eventlijn/server/config.local.php` HTTP 403 geeft. Stel beide controles ook in als je nginx gebruikt, met expliciet geblokkeerde toegang tot `/eventlijn/server/`, uitgeschakelde directorylisting en dezelfde beveiligingsheaders als in de meegeleverde Apache-configuratie.
5. Open [Event Line](https://sharon.life/eventlijn/), registreer je organisatie met een beheerdersaccount en bevestig het e-mailadres vanuit je mailbox. Nodig vervolgens een leidinggevende en een medewerker uit. Laat beiden het adres bevestigen, activeer beide accounts als beheerder en wijs de leidinggevende toe. Doorloop daarna een testaanvraag en controleer de meldingen in alle betrokken mailboxen, inclusief de spammap.
6. Plan bij voorkeur elke vijf minuten een hostingtaak die de e-mailwachtrij opnieuw probeert. Gebruik hetzelfde PHP-account en geef de echte publieke documentroot op:

   ```sh
   php /absoluut/pad/naar/webroot/eventlijn/server/mail-retry.php /absoluut/pad/naar/webroot
   ```

   PHP probeert verzending ook direct na relevante handelingen. De taak rapporteert hoeveel berichten wachten op herhaling. Succesvolle overdracht aan `mail()` betekent dat de mailserver het bericht heeft geaccepteerd. Controleer de daadwerkelijke aflevering met een echte mailbox.

## 3. Sharon optioneel inschakelen

Volg `optioneel-sharon/INSTALL.md` uit het pakket. In Event Line kan iemand na goedkeuring een privénotitie voor de eigen deelname naar Sharon meenemen. De gebruiker bevestigt de import in Sharon. Alleen titel, bezoekdag en locatie worden overgedragen. Besluiten, gegevens over de organisatie of aanvrager, evaluaties en spotlights blijven in Event Line.

## 4. Doorlopend beheer

Vervang bij volgende releases de applicatiebestanden en behoud `server/config.local.php` en de private datamap. Stop bij terugzetten eerst alle schrijfacties en herstel de accountindex en alle organisatiebestanden uit hetzelfde back-upmoment. Controleer de versie, sessies en e-mail na de update. Gebruik bij deze eerste versie één organisatie per e-mailadres. Accounts kunnen ook als leidinggevende zelf deelnemen zodra een andere actieve beoordelaar is toegewezen. Een beheerder die tegelijk leidinggevende is, legt beide besluiten expliciet vast.
