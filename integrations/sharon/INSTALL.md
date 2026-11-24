# Optionele Sharon-koppeling

Eventlijn werkt zelfstandig. Met de koppeling kan een medewerker een **eigen goedgekeurde of afgesloten deelname** als lokale privénotitie in Sharon bewaren. De medewerker opent de overdracht in Eventlijn en bevestigt het bewaren in Sharon.

1. Zoek de HTTPS-URL waar je **de Sharon-app zelf** opent. De Sharon-app en de ontvangpagina moeten hetzelfde protocol, domein en poort gebruiken zodat ze dezelfde browseropslag zien. De locatie van de PHP-API van Sharon bepaalt dit niet.
2. Plaats `eventlijn.html` en `eventlijn-handoff.js` bij `index.html` van de Sharon-frontend. De twee bestanden gebruiken Sharons bestaande `src/core/storage/ObjectStore.js` en `src/core/ids/IdentifierService.js`. Bij GitHub Pages publiceer je ze mee in de bestaande Sharon-site.
3. Stel in Eventlijn `EVENTLIJN_SHARON_URL` in op de volledige HTTPS-URL naar `eventlijn.html` zonder querystring of fragment. Als je Sharon opent via `https://stefan-van-dijk.github.io/sharon/`, wordt dit `https://stefan-van-dijk.github.io/sharon/eventlijn.html`.
4. Open de koppeling vanuit een eigen goedgekeurde testdeelname. Controleer dat Sharon de titel, bezoekdag en locatie toont. Klik op **Bewaar in Notities en lijsten** en open Sharon in hetzelfde browserprofiel. Zet **Notities en lijsten** zo nodig aan via **Mijn Sharon**.

De overdracht bevat uitsluitend de drie getoonde velden en gebruikt een URL-fragment. Het fragment wordt niet in de HTTP-aanvraag meegestuurd en verdwijnt bij openen direct uit de adresbalk. Het wordt alleen na bevestiging in de lokale opslag van Sharon bewaard. De notitie staat los van latere wijzigingen in Eventlijn en kan in Sharon worden bewerkt of verwijderd. Gebruik bij een geïnstalleerde PWA hetzelfde browserprofiel en dezelfde opslagcontext. Die kunnen per apparaat of browser verschillen.

Voor uitschakelen verwijder je de ingestelde URL. Bestaande notities blijven beschikbaar in Sharon.

## Hergebruik

ShopList gebruikt Sharons bestaande browseropslag op hetzelfde origin via `sharon.mjs`. De ontvangpagina past datzelfde patroon toe en gebruikt Sharons bestaande opslag en notitiemodel. De productgebonden koppelcodes en lijstbevoegdheden van ShopList blijven binnen die koppeling. Sharons Agenda is in deze bronversie een voorvertoning. De overdracht verschijnt in **Notities en lijsten**.
