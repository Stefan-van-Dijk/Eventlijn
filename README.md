# Event Line

**Ga eropuit. Breng iets moois terug.**

Een zelfstandige webapp voor organisatie-evenementen. De tijdlijn ondersteunt zoeken, meerdaagse evenementen en suggesties voor dubbele registraties. Een organisatiebeheerder nodigt medewerkers en leidinggevenden uit en activeert hun accounts nadat hun e-mailadres is bevestigd.

Aanvragen gaan van de toegewezen leidinggevende naar organisatiebeheer. Organisatiebeheer neemt het eindbesluit en kan het herzien. Organisatiebeheer kan vrije inschrijving voor organisatieleden instellen. Medewerkers zien hun eigen aanvragen, leidinggevenden hun toegewezen aanvragen en organisatiebeheer de eigen organisatie. Terugkoppeling kan schriftelijk of mondeling. De beheerder kan een vrijwillig voorgestelde spotlight na afstemming intern publiceren.

De optionele Sharon-overdracht bewaart na bevestiging een persoonlijke notitie van een eigen goedgekeurde deelname. De zelfstandige app gebruikt PHP 8.1+, privésessies en afgeschermde JSON-opslag. Zie het [contract voor evenementen, tijd en zichtbaarheid](server/EVENTS.md) voor het delen van openbare evenementinformatie vanuit Event Line.

## Installeren en testen

- Zie [installatie voor sharon.life](server/INSTALL.md) en [optionele Sharon-overdracht](integrations/sharon/INSTALL.md).
- `php tests/storage.php` controleert opslag en isolatie.
- `python3 tests/http_flows.py` test de app via een lokale PHP-server met een afgeschermde testmailbox. Zet `PHP` als de PHP-binary een afwijkend pad heeft.
- [Statische demo](demo.html) gebruikt fictieve lokale gegevens en kan los van de productieapp worden geopend.

## Naam en installatie

De productnaam is Event Line. De bestaande repository, URL `/eventlijn/`, integratiebestanden, `EVENTLIJN_*`-configuratie en sessiecookie behouden hun technische naam, zodat installaties en bestaande links blijven werken.
