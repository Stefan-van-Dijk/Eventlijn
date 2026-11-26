const fragment = window.location.hash;
if (fragment.startsWith('#eventlijn=')) {
  const current = new URL(window.location.href);
  current.hash = '';
  window.history.replaceState(null, '', current);
}

const status = document.querySelector('#status');
const event = readHandoff(fragment);

if (event) {
  const details = [`Bezoekdag: ${event.day}`];
  if (event.location) details.push(`Locatie: ${event.location}`);
  document.querySelector('#title').textContent = event.title;
  document.querySelector('#details').textContent = details.join(' · ');
  document.querySelector('#event').hidden = false;
  const save = document.querySelector('#save');
  save.addEventListener('click', async () => {
    save.disabled = true;
    try {
      const { ObjectStore } = await import('./src/core/storage/ObjectStore.js');
      const { IdentifierService } = await import('./src/core/ids/IdentifierService.js');
      const store = await new ObjectStore().open();
      let id;
      do {
        id = IdentifierService.create();
      } while (await store.get('objects', id));
      const now = Date.now();
      await store.put('objects', {
        id,
        type: 'note',
        name: event.title,
        createdAt: now,
        updatedAt: now,
        data: { kind: 'note', body: details.join('\n'), items: [] },
      });
      status.textContent = 'Bewaard in Notities en lijsten. Zet dit onderdeel zo nodig aan via Mijn Sharon.';
      save.hidden = true;
    } catch {
      status.textContent = 'Opslaan in Sharon is niet gelukt. Probeer het opnieuw in dezelfde browser als Sharon.';
      save.disabled = false;
    }
  });
} else {
  status.textContent = 'Open vanuit Event Line een eigen goedgekeurde deelname om die hier te bewaren.';
}

function readHandoff(fragment) {
  const prefix = '#eventlijn=';
  if (!fragment.startsWith(prefix) || fragment.length > 24000) return null;
  try {
    const data = JSON.parse(decodeURIComponent(fragment.slice(prefix.length)));
    if (data === null || typeof data !== 'object' || Array.isArray(data)
        || Object.keys(data).length !== 3
        || typeof data.title !== 'string' || !data.title.trim() || data.title.length > 1000
        || typeof data.location !== 'string' || data.location.length > 1000
        || typeof data.day !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(data.day)) return null;
    const timestamp = Date.parse(data.day + 'T00:00:00Z');
    if (!Number.isFinite(timestamp) || new Date(timestamp).toISOString().slice(0, 10) !== data.day) return null;
    return data;
  } catch {
    return null;
  }
}
