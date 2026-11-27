'use strict';

const byId = id => document.getElementById(id);
const apiUrl = 'api/index.php';
const sharonHostOrigins = new Set(['https://sharon.life', 'https://stefan-van-dijk.github.io']);

if (window.parent !== window && document.referrer && new URLSearchParams(location.search).get('from') === 'sharon') {
  const hostOrigin = new URL(document.referrer).origin;
  if (sharonHostOrigins.has(hostOrigin)) {
    const back = byId('returnToSharon');
    back.addEventListener('click', () => window.parent.postMessage({ type: 'eventline:return' }, hostOrigin));
    back.hidden = false;
    window.parent.postMessage({ type: 'eventline:return-ready' }, hostOrigin);
  }
}
const access = byId('access');
const accessForm = byId('accessForm');
const dialog = byId('dialog');
const dialogForm = byId('dialogForm');
const status = byId('status');
const dialogStatus = byId('dialogStatus');
const accessModes = {
  login: { title: 'Inloggen', submit: 'Inloggen' },
  register: { title: 'Registreer je organisatie', submit: 'Organisatie registreren' },
  invite: { title: 'Uitnodiging accepteren', submit: 'Account aanmaken' },
  verify: { title: 'Bevestig je e-mailadres', submit: 'E-mailadres bevestigen' },
  forgot: { title: 'Vraag een nieuw wachtwoord aan', submit: 'Herstellink aanvragen' },
  reset: { title: 'Kies een nieuw wachtwoord', submit: 'Wachtwoord opslaan' },
};
const roleNames = { employee: 'Medewerker', manager: 'Leidinggevende', admin: 'Organisatiebeheerder' };
const memberStatuses = { active: 'Actief', pending: 'Wacht op goedkeuring', rejected: 'Geweigerd', unverified: 'E-mailadres nog onbevestigd' };
const requestStatuses = {
  manager_pending: 'Bij leidinggevende',
  organization_pending: 'Bij organisatiebeheer',
  rejected_manager: 'Afgewezen door leidinggevende',
  rejected_organization: 'Afgewezen door organisatiebeheer',
  approved: 'Goedgekeurd',
  revoked: 'Ingetrokken',
  closed: 'Afgesloten',
};
const shortDate = new Intl.DateTimeFormat('nl-NL', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const monthName = new Intl.DateTimeFormat('nl-NL', { month: 'long', year: 'numeric', timeZone: 'UTC' });
const date = value => new Date(value + 'T12:00:00Z');
const normalize = value => String(value ?? '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
const titleKey = value => normalize(value).replace(/[^\p{L}\p{N}]/gu, '');

let state;
let view = 'events';
let mode = 'login';
let dialogAction;
let busy = false;
let fieldSequence = 0;

const tokens = {};

function readAccessLink() {
  const url = new URL(location.href);
  const fragment = new URLSearchParams(url.hash.slice(1));
  let found = false;
  for (const name of ['verify', 'invite', 'reset']) {
    const token = fragment.get(name) || url.searchParams.get(name);
    if (!token) continue;
    tokens[name] = token;
    mode = name;
    url.searchParams.delete(name);
    found = true;
  }
  if (found) {
    url.hash = '';
    history.replaceState(null, '', url);
  }
  return found;
}

readAccessLink();
window.addEventListener('hashchange', () => {
  if (readAccessLink() && state) {
    message(status);
    render();
  }
});

function element(tag, value = '', className = '') {
  const node = document.createElement(tag);
  if (value) node.textContent = value;
  if (className) node.className = className;
  return node;
}

function button(label, onClick, className = 'secondary') {
  const node = element('button', label, className);
  node.type = 'button';
  node.addEventListener('click', onClick);
  return node;
}

function field(name, label, options = {}) {
  const wrapper = element('div', '', 'field');
  const type = options.type || 'text';
  const input = element(type === 'textarea' || type === 'select' ? type : 'input');
  if (input.tagName === 'INPUT') input.type = type;
  if (type === 'textarea') wrapper.classList.add('wide');
  if (type === 'select') {
    for (const [value, text] of options.choices) input.add(new Option(text, value));
  }
  input.name = name;
  input.required = options.required !== false;
  for (const key of ['min', 'max', 'minLength', 'maxLength', 'autocomplete', 'value']) {
    if (options[key] !== undefined) input[key] = options[key];
  }
  input.id = 'field-' + (++fieldSequence);
  const labelNode = element('label', label);
  labelNode.htmlFor = input.id;
  wrapper.append(labelNode, input);
  return wrapper;
}

function message(target, text = '', error = false) {
  target.hidden = !text;
  target.dataset.error = String(error);
  target.textContent = text;
}

async function api(payload) {
  const options = { credentials: 'same-origin', cache: 'no-store' };
  if (payload) {
    options.method = 'POST';
    options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf };
    options.body = JSON.stringify(payload);
  }
  const response = await fetch(apiUrl, options);
  const result = await response.json().catch(() => null);
  if (!response.ok || !result || (payload && result.ok !== true)) {
    throw new Error(result?.error || 'Event Line is tijdelijk onbereikbaar. Probeer het opnieuw.');
  }
  return result;
}

async function refresh() {
  state = await api();
  render();
}

async function mutate(payload, { trigger, feedback = status, success, after } = {}) {
  if (busy) return;
  busy = true;
  if (trigger) trigger.disabled = true;
  message(feedback);
  let result;
  try {
    result = await api(payload);
  } catch (error) {
    message(feedback, error.message, true);
  } finally {
    if (!result) {
      busy = false;
      if (trigger) trigger.disabled = false;
    }
  }
  if (!result) return;

  try {
    if (after) after(result);
    if (success) message(status, success);
    await refresh();
  } catch {
    message(status, 'De wijziging is opgeslagen. Vernieuw de pagina om de actuele status te zien.', true);
  } finally {
    busy = false;
    if (trigger) trigger.disabled = false;
  }
}

function showAccess(nextMode) {
  mode = nextMode;
  access.hidden = false;
  const config = accessModes[mode];
  const submit = byId('accessSubmit');
  const fields = byId('accessFields');
  const controls = byId('accessOptions');
  const text = byId('accessText');
  byId('accessHeading').textContent = config.title;
  submit.textContent = config.submit;
  submit.hidden = false;
  fields.replaceChildren();
  controls.replaceChildren();
  text.hidden = true;
  if (mode === 'verify') {
    text.textContent = 'Bevestig het e-mailadres dat je voor Event Line gebruikt.';
    text.hidden = false;
  }
  if (mode === 'register' || mode === 'invite') {
    fields.append(field('name', 'Je naam', { autocomplete: 'name', maxLength: 100 }));
  }
  if (['login', 'register', 'invite', 'forgot'].includes(mode)) {
    fields.append(field('email', 'E-mailadres', { type: 'email', autocomplete: 'username', maxLength: 254 }));
  }
  if (['login', 'register', 'invite', 'reset'].includes(mode)) {
    const creating = mode !== 'login';
    fields.append(field('password', creating ? 'Wachtwoord (minstens 12 tekens)' : 'Wachtwoord', {
      type: 'password', autocomplete: creating ? 'new-password' : 'current-password',
      ...(creating ? { minLength: 12, maxLength: 72 } : {}),
    }));
  }
  if (mode === 'register') fields.append(field('organization', 'Naam van je organisatie', { autocomplete: 'organization', maxLength: 150 }));
  if (mode === 'invite') fields.append(field('managerEmail', 'E-mailadres leidinggevende (optioneel)', { type: 'email', required: false }));
  if (mode !== 'login') controls.append(button(state.actor ? 'Naar mijn account' : 'Naar inloggen', () => { mode = 'login'; render(); }));
  if (mode === 'login') {
    controls.append(button('Organisatie registreren', () => showAccess('register')),
      button('Wachtwoord vergeten?', () => showAccess('forgot')));
    for (const name of Object.keys(tokens)) {
      controls.append(button(accessModes[name].title, () => showAccess(name)));
    }
  }
}

accessForm.addEventListener('submit', event => {
  event.preventDefault();
  const fields = Object.fromEntries(new FormData(accessForm));
  const action = mode === 'invite' ? 'register' : mode;
  if (mode === 'invite') fields.invite = tokens.invite;
  if (mode === 'verify' || mode === 'reset') fields.token = tokens[mode];
  const notices = {
    register: 'Controleer je inbox om je e-mailadres te bevestigen.',
    invite: 'Controleer je inbox. Na e-mailbevestiging beoordeelt organisatiebeheer je account.',
    verify: 'Je e-mailadres is bevestigd. Je kunt inloggen.',
    forgot: 'Als het adres bekend is bij Event Line, ontvang je een herstellink.',
    reset: 'Je wachtwoord is gewijzigd. Je kunt weer inloggen.',
  };
  const activeMode = mode;
  void mutate({ action: 'auth.' + action, ...fields }, {
    trigger: event.submitter,
    success: notices[activeMode],
    after: () => {
      delete tokens[activeMode];
      mode = 'login';
    },
  });
});

function pendingAccess() {
  access.hidden = false;
  accessForm.hidden = true;
  byId('accessOptions').replaceChildren();
  byId('accessHeading').textContent = memberStatuses[state.actor.status] || 'Toegang in behandeling';
  const explanation = byId('accessText');
  explanation.hidden = false;
  explanation.textContent = state.actor.status === 'rejected'
    ? 'Neem contact op met je organisatiebeheerder als je vragen hebt.'
    : 'Je organisatiebeheerder beoordeelt je account. Je kunt deze pagina later opnieuw openen.';
}

function render() {
  const actor = state.actor;
  const hasAccessLink = Boolean(tokens[mode]);
  const active = actor?.status === 'active' && !hasAccessLink;
  byId('accountBar').hidden = !actor;
  byId('identity').textContent = actor ? [actor.name, state.organization?.name, roleNames[actor.role]].filter(Boolean).join(' · ') : '';
  byId('workspace').hidden = !active;
  byId('addEvent').hidden = !active;
  byId('accessForm').hidden = false;
  access.hidden = Boolean(active);
  if (!actor || hasAccessLink) return showAccess(mode);
  if (!active) return pendingAccess();

  document.querySelector('[data-view="admin"]').hidden = actor.role !== 'admin';
  document.querySelector('[data-view="account"]').hidden = !state.sharonEnabled;
  if ((view === 'admin' && actor.role !== 'admin') || (view === 'account' && !state.sharonEnabled)) view = 'events';
  switchView(view);
  renderEvents();
  renderRequests();
  renderSpotlights();
  renderAdmin();
  renderSharon();
}

function switchView(next) {
  view = next;
  document.querySelectorAll('[data-view]').forEach(tab => {
    const selected = tab.dataset.view === view;
    tab.classList.toggle('active', selected);
    if (selected) tab.setAttribute('aria-current', 'page');
    else tab.removeAttribute('aria-current');
    byId(tab.dataset.view + 'View').hidden = !selected;
  });
}

function today() {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Amsterdam' }).format(new Date());
}

function similarTitle(left, right, limit) {
  if (Math.abs(left.length - right.length) > limit) return false;
  let row = Array.from({ length: right.length + 1 }, (_, index) => index);
  for (const [index, letter] of left.split('').entries()) {
    const next = [index + 1];
    for (let column = 1; column <= right.length; column++) {
      next[column] = Math.min(row[column] + 1, next[column - 1] + 1,
        row[column - 1] + Number(letter !== right[column - 1]));
    }
    if (Math.min(...next) > limit) return false;
    row = next;
  }
  return row[right.length] <= limit;
}

function eventFor(id) {
  return state.events.find(event => event.id === id);
}

function renderEvents() {
  const timeline = byId('timeline');
  const query = normalize(byId('search').value.trim());
  const searchTitle = titleKey(query);
  const filter = byId('filter').value;
  const now = today();
  const events = state.events.filter(event => {
    const matches = normalize([event.title, event.location, event.topic].join(' ')).includes(query) ||
      (searchTitle.length >= 4 && similarTitle(titleKey(event.title), searchTitle, 2));
    return matches && (filter === 'all' || (filter === 'upcoming' ? event.end >= now : event.end < now));
  }).sort((left, right) => left.start.localeCompare(right.start) || left.title.localeCompare(right.title, 'nl'));
  byId('countEvents').textContent = state.events.length;
  byId('countUpcoming').textContent = state.events.filter(event => event.end >= now).length;
  byId('countRequests').textContent = state.requests.length;
  timeline.replaceChildren();
  if (!events.length) timeline.append(element('p', 'Geen evenementen gevonden.', 'empty'));
  let lastMonth = '';
  for (const event of events) {
    const month = monthName.format(date(event.start));
    if (month !== lastMonth) timeline.append(element('h3', month, 'month'));
    lastMonth = month;
    const card = element('article', '', 'event');
    const when = element('div', shortDate.format(date(event.start)), 'date');
    const end = event.start === event.end ? event.start.slice(0, 4) : 't/m ' + shortDate.format(date(event.end));
    when.append(element('span', end));
    const details = element('div');
    const metadata = [event.topic, event.location, event.kind === 'internal' ? 'Intern' : 'Extern'].filter(Boolean);
    details.append(element('h3', event.title), element('p', metadata.join(' · ')),
      element('span', event.represented ? 'Organisatie vertegenwoordigd' : 'Nog geen deelname bekend', event.represented ? 'tag' : 'tag mutedtag'));
    card.append(when, details);
    if (event.end >= now) {
      card.append(button(event.registration === 'free' ? 'Inschrijven ↗' : 'Deelname aanvragen ↗', () => requestEvent(event)));
    }
    timeline.append(card);
  }
}

function openDialog({ title, explanation, fields = [], submit, action, after, success }) {
  byId('dialogTitle').textContent = title;
  byId('dialogInfo').textContent = explanation;
  byId('dialogFields').replaceChildren(...fields);
  byId('submit').textContent = submit || title;
  message(dialogStatus);
  dialogAction = { action, after, success };
  dialog.showModal();
}

dialogForm.addEventListener('submit', event => {
  event.preventDefault();
  const { action, after, success } = dialogAction;
  void mutate({ ...Object.fromEntries(new FormData(dialogForm)), ...action }, {
    trigger: event.submitter,
    feedback: dialogStatus,
    success,
    after: result => {
      dialog.close();
      if (after) after(result);
    },
  });
});

function addEvent() {
  const title = field('title', 'Naam evenement', { maxLength: 160 });
  const start = field('start', 'Startdatum', { type: 'date' });
  const end = field('end', 'Einddatum', { type: 'date' });
  const kind = field('kind', 'Soort', { type: 'select', choices: [['external', 'Extern'], ['internal', 'Intern']] });
  const registration = field('registration', 'Inschrijving', {
    type: 'select', choices: [['approval', 'Met goedkeuring'], ['free', 'Vrije inschrijving']],
  });
  registration.hidden = true;
  const fields = [title, start, end, field('location', 'Locatie (optioneel)', { required: false, maxLength: 180 }),
    field('topic', 'Onderwerp (optioneel)', { required: false, maxLength: 120 }), kind, registration];
  const original = 'Controleer of het evenement al in de tijdlijn staat.';
  openDialog({ title: 'Evenement toevoegen', explanation: original, fields,
    action: { action: 'event.create' }, success: 'Het evenement staat op de tijdlijn.' });
  title.querySelector('input').addEventListener('input', event => {
    const key = titleKey(event.target.value);
    const matches = key.length >= 4 ? state.events.filter(item => {
      const existing = titleKey(item.title);
      return existing.includes(key) || (existing.length >= 4 && (key.includes(existing) || similarTitle(key, existing, 2)));
    }).slice(0, 3) : [];
    byId('dialogInfo').textContent = matches.length ? 'Mogelijk al aanwezig · ' + matches.map(item => item.title).join(', ') : original;
  });
  start.querySelector('input').addEventListener('change', event => {
    const finalDate = end.querySelector('input');
    finalDate.min = event.target.value;
    if (!finalDate.value || finalDate.value < finalDate.min) finalDate.value = finalDate.min;
  });
  kind.querySelector('select').addEventListener('change', event => {
    registration.hidden = event.target.value !== 'internal' || state.actor.role !== 'admin';
    if (registration.hidden) registration.querySelector('select').value = 'approval';
  });
}

function requestEvent(event) {
  const free = event.registration === 'free';
  const firstDay = [event.start, today()].sort().at(-1);
  const fields = [field('day', 'Bezoekdag', { type: 'date', min: firstDay, max: event.end, value: firstDay })];
  if (!free) fields.push(field('goal', 'Wat wil je ontdekken?', { type: 'textarea', maxLength: 2000 }),
    field('benefit', 'Wat kan dit je organisatie brengen?', { type: 'textarea', maxLength: 2000 }));
  openDialog({
    title: free ? 'Inschrijven' : 'Deelname aanvragen',
    explanation: event.title, fields, action: { action: 'request.create', eventId: event.id },
    success: free ? 'Je bent ingeschreven.' : 'Je aanvraag is ingediend.',
    after: () => { view = 'requests'; },
  });
}

function actionsFor(request) {
  const actions = element('div', '', 'actions');
  const eligible = new Set(request.actions || []);
  const append = (action, label, callback, style) => {
    if (eligible.has(action)) actions.append(button(label, callback, style));
  };
  append('request.decide', request.status === 'manager_pending' ? 'Doorsturen naar organisatiebeheer' : 'Definitief goedkeuren',
    () => decision(request, 'approve', 'Aanvraag goedkeuren'));
  append('request.decide', 'Afwijzen', () => decision(request, 'reject', 'Aanvraag afwijzen'), 'secondary danger');
  append('request.revise', 'Alsnog goedkeuren', () => decision(request, 'approve', 'Besluit herzien'));
  append('request.revise', 'Afwijzing herzien', () => decision(request, 'reject', 'Afwijzing herzien'));
  append('request.revoke', 'Deelname intrekken', () => decision(request, 'revoke', 'Deelname intrekken'), 'secondary danger');
  append('request.evaluate', request.evaluation ? 'Terugkoppeling bewerken' : 'Terugkoppeling geven', () => evaluate(request));
  append('request.close', 'Deelname afsluiten', () => closeRequest(request));
  append('spot.propose', 'In de spotlights', () => proposeSpot(request));
  append('spot.publish', 'Publiceren na afstemming', () => publishSpot(request));
  append('spot.withdraw', 'Spotlight intrekken', () => withdrawSpot(request));
  if (state.sharonEnabled && request.userId === state.actor.id && ['approved', 'closed'].includes(request.status)) {
    actions.append(button('Open in Sharon ↗', () => sharonHandoff(request)));
  }
  return actions;
}

function renderRequests() {
  const list = byId('requestList');
  list.replaceChildren();
  byId('requestHeading').textContent = {
    employee: 'Mijn deelname', manager: 'Mijn deelname en toegewezen aanvragen', admin: 'Aanvragen in je organisatie',
  }[state.actor.role];
  if (!state.requests.length) list.append(element('p', 'Er zijn geen aanvragen om te tonen.', 'empty'));
  for (const request of state.requests) {
    const item = element('article', '', 'item');
    const details = element('div');
    const ownRequest = request.userId === state.actor.id;
    const title = eventFor(request.eventId)?.title || 'Evenement';
    details.append(element('h3', ownRequest ? title : title + ' · ' + request.name),
      element('small', request.day + ' · ' + (requestStatuses[request.status] || request.status)));
    if (request.goal) details.append(element('p', 'Doel · ' + request.goal));
    if (request.benefit) details.append(element('p', 'Opbrengst voor de organisatie · ' + request.benefit));
    if (request.evaluation) {
      details.append(element('p', 'Terugkoppeling · ' + request.evaluation.outcome));
      if (request.evaluation.reference) details.append(element('p', 'Verwijzing · ' + request.evaluation.reference));
    }
    if (request.closure) {
      details.append(element('p', 'Uitkomst · ' + request.closure.result));
      if (request.closure.note) details.append(element('p', request.closure.note));
      if (request.closure.reference) details.append(element('p', 'Verwijzing · ' + request.closure.reference));
    }
    if (request.spot?.text) {
      details.append(element('p', (request.spot.status === 'published' ? 'Spotlight gepubliceerd · ' : 'Spotlight · ') + request.spot.text));
    }
    if (request.history?.length) {
      const history = element('details');
      const entries = element('ul');
      for (const entry of request.history) {
        const at = new Date(entry.at);
        const time = Number.isNaN(at.valueOf()) ? '' : at.toLocaleString('nl-NL');
        entries.append(element('li', [time, entry.note, entry.reason].filter(Boolean).join(' · ')));
      }
      history.append(element('summary', 'Verloop'), entries);
      details.append(history);
    }
    item.append(details, actionsFor(request));
    list.append(item);
  }
}

function decision(request, choice, title) {
  openDialog({
    title,
    explanation: eventFor(request.eventId)?.title || 'Deelname',
    fields: choice === 'approve' ? [] : [field('reason', 'Reden', { type: 'textarea', maxLength: 2000 })],
    action: { action: 'request.decide', requestId: request.id, decision: choice },
    success: 'Het besluit is opgeslagen.',
  });
}

function evaluate(request) {
  openDialog({
    title: 'Terugkoppeling geven',
    explanation: 'Leg vast wat het evenement heeft opgeleverd.',
    fields: [
      field('outcome', 'Wat heb je opgehaald?', { type: 'textarea', value: request.evaluation?.outcome, maxLength: 4000 }),
      field('reference', 'Waar is de kennis opgeslagen? (optioneel)', { required: false, value: request.evaluation?.reference, maxLength: 500 }),
    ],
    action: { action: 'request.evaluate', requestId: request.id },
    success: 'Je terugkoppeling is opgeslagen.',
  });
}

function closeRequest(request) {
  const results = ['Doel behaald', 'Vervolgactie nodig', 'Doel niet behaald', 'Mondeling afgehandeld', 'Administratief afgesloten'];
  openDialog({
    title: 'Deelname afsluiten',
    explanation: eventFor(request.eventId)?.title || 'Deelname',
    fields: [
      field('result', 'Uitkomst', { type: 'select', choices: results.map(result => [result, result]) }),
      field('note', 'Toelichting (optioneel)', { type: 'textarea', required: false, maxLength: 2000 }),
      field('reference', 'Verwijzing naar kennis (optioneel)', { required: false, maxLength: 500 }),
    ],
    action: { action: 'request.close', requestId: request.id },
    success: 'De deelname is afgesloten.',
  });
}

function proposeSpot(request) {
  openDialog({
    title: 'Stel een spotlight voor',
    explanation: 'Je organisatiebeheerder publiceert je bijdrage na afstemming met jou.',
    fields: [field('text', 'Welke ontdekking wil je delen?', { type: 'textarea', maxLength: 3000 })],
    action: { action: 'spot.propose', requestId: request.id },
    success: 'Je bijdrage is klaar voor afstemming.',
  });
}

function publishSpot(request) {
  openDialog({
    title: 'Spotlight publiceren',
    explanation: 'Bevestig dat je deze tekst met de medewerker hebt afgestemd. De bijdrage wordt zichtbaar voor je organisatie.',
    submit: 'Afgestemd en publiceren',
    action: { action: 'spot.publish', requestId: request.id, confirmed: true },
    success: 'De spotlight is gepubliceerd.',
  });
}

function withdrawSpot(request) {
  openDialog({
    title: 'Spotlight intrekken',
    explanation: 'De bijdrage verdwijnt uit de tijdlijn van je organisatie.',
    action: { action: 'spot.withdraw', requestId: request.id },
    success: 'De spotlight is ingetrokken.',
  });
}

function renderSpotlights() {
  const list = byId('spotlights');
  list.replaceChildren();
  if (!state.spotlights.length) list.append(element('p', 'Er zijn nog geen gepubliceerde bijdragen.'));
  for (const spot of state.spotlights) {
    const card = element('article', '', 'spotitem');
    card.append(element('strong', [spot.name, eventFor(spot.eventId)?.title].filter(Boolean).join(' · ')),
      element('p', spot.text));
    list.append(card);
  }
}

function inviteMember() {
  openDialog({
    title: 'Medewerker uitnodigen',
    explanation: 'De ontvanger krijgt een persoonlijke link om een account aan te maken.',
    fields: [
      field('email', 'E-mailadres', { type: 'email', maxLength: 254 }),
      field('role', 'Rol', { type: 'select', choices: [['employee', 'Medewerker'], ['manager', 'Leidinggevende']] }),
    ],
    action: { action: 'member.invite' },
    success: 'De uitnodiging is klaargezet voor verzending.',
  });
}

function assignManager(member, approving) {
  const managers = state.members.filter(person => person.id !== member.id &&
    person.status === 'active' && ['manager', 'admin'].includes(person.role));
  const suggest = managers.find(person => person.email === member.managerEmail);
  const required = member.role === 'employee';
  openDialog({
    title: approving ? 'Account goedkeuren' : 'Leidinggevende instellen',
    explanation: [member.name, member.email, member.managerEmail && 'Voorgesteld · ' + member.managerEmail].filter(Boolean).join(' · '),
    fields: [field('manager', 'Leidinggevende', {
      type: 'select', choices: [['', required ? 'Kies een leidinggevende' : 'Geen leidinggevende'],
        ...managers.map(person => [person.id, person.name + ' · ' + person.email])],
      value: member.manager || suggest?.id || '', required,
    })],
    action: { action: approving ? 'member.approve' : 'member.manager', id: member.id },
    success: approving ? 'Het account is goedgekeurd.' : 'De leidinggevende is opgeslagen.',
  });
}

function rejectMember(member) {
  openDialog({
    title: 'Account weigeren',
    explanation: member.name + ' · ' + member.email,
    action: { action: 'member.reject', id: member.id },
    success: 'Het account is geweigerd.',
  });
}

function renderAdmin() {
  const list = byId('memberList');
  list.replaceChildren();
  if (state.actor.role !== 'admin') return;
  const members = [...state.members].sort((left, right) =>
    Number(right.status === 'pending') - Number(left.status === 'pending') || left.name.localeCompare(right.name, 'nl'));
  for (const member of members) {
    const row = element('article', '', 'item');
    const details = element('div');
    const actions = element('div', '', 'actions');
    const manager = state.members.find(person => person.id === member.manager);
    details.append(element('h3', member.name), element('small', [
      member.email, roleNames[member.role], memberStatuses[member.status], manager && 'Leidinggevende · ' + manager.name,
    ].filter(Boolean).join(' · ')));
    if (member.status === 'pending') {
      actions.append(button('Goedkeuren', () => assignManager(member, true)),
        button('Weigeren', () => rejectMember(member), 'secondary danger'));
    }
    if (member.status === 'active' && member.id !== state.actor.id && member.role !== 'admin') {
      actions.append(button('Leidinggevende instellen', () => assignManager(member, false)));
    }
    row.append(details, actions);
    list.append(row);
  }
}

function renderSharon() {
  const settings = byId('linkSettings');
  settings.replaceChildren();
  if (state.sharonEnabled) {
    settings.append(element('p', 'Open een goedgekeurde of afgesloten deelname vanuit Mijn deelname in Sharon. Daarna kun je daar een persoonlijke notitie bewaren.'));
  }
}

function sharonHandoff(request) {
  openDialog({
    title: 'Deelname openen in Sharon',
    explanation: 'Event Line geeft alleen de titel, bezoekdag en locatie door. In Sharon kies je of je de notitie lokaal bewaart.',
    submit: 'Open Sharon ↗',
    action: { action: 'sharon.handoff', id: request.id },
    after: result => {
      const destination = new URL(result.url);
      if (destination.protocol !== 'https:') throw new Error('Ongeldige Sharon-link');
      location.assign(destination.href);
    },
  });
}

document.querySelectorAll('[data-view]').forEach(tab => {
  tab.addEventListener('click', () => { message(status); switchView(tab.dataset.view); });
});
byId('search').addEventListener('input', renderEvents);
byId('filter').addEventListener('change', renderEvents);
byId('addEvent').addEventListener('click', addEvent);
byId('invite').addEventListener('click', inviteMember);
byId('cancel').addEventListener('click', () => { if (!busy) dialog.close(); });
dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
byId('logout').addEventListener('click', event => {
  void mutate({ action: 'auth.logout' }, { trigger: event.currentTarget, after: () => {
    view = 'events';
    byId('search').value = '';
    byId('filter').value = 'all';
  } });
});

void refresh().catch(error => message(status, error.message, true));
