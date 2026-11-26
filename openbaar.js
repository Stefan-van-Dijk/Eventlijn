'use strict';

async function showPublicEvent() {
  const period = document.getElementById('event-period');
  const key = new URLSearchParams(location.search).get('event');
  if (!key || !/^[a-f0-9]{32}:[a-f0-9]{32}$/.test(key)) {
    period.textContent = 'Het evenement is niet gevonden.';
    return;
  }
  try {
    const response = await fetch('api/index.php?publicEvent=' + encodeURIComponent(key), { credentials: 'omit' });
    if (response.status === 404) {
      period.textContent = 'Het evenement is niet gevonden.';
      return;
    }
    if (!response.ok) throw new Error('Event request failed');
    const { event } = await response.json();
    const formatter = new Intl.DateTimeFormat('nl-NL', { dateStyle: 'long', timeZone: 'UTC' });
    const start = formatter.format(new Date(event.start + 'T12:00:00Z'));
    const end = formatter.format(new Date(event.end + 'T12:00:00Z'));
    document.getElementById('event-title').textContent = event.title;
    period.textContent = event.start === event.end ? start : start + ' t/m ' + end;
    const locationField = document.getElementById('event-location');
    locationField.textContent = event.location;
    locationField.hidden = !event.location;
    const topicField = document.getElementById('event-topic');
    topicField.textContent = event.topic;
    topicField.hidden = !event.topic;
  } catch {
    period.textContent = 'Het evenement is tijdelijk onbereikbaar. Probeer het opnieuw.';
  }
}

void showPublicEvent();
