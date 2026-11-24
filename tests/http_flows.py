"""Run isolated Eventlijn account, workflow, and authorization tests over HTTP."""
from contextlib import contextmanager
from datetime import datetime, timedelta
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlsplit
from urllib.request import HTTPCookieProcessor, Request, build_opener
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parent.parent
PASSWORD = 'test-password-for-eventlijn'
SHARON = 'https://sharon.life/log/eventlijn.html'
TODAY = datetime.now(ZoneInfo('Europe/Amsterdam')).date()
DAY = TODAY.isoformat()
TOMORROW = (TODAY + timedelta(days=1)).isoformat()


class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = CookieJar()
        self.opener = build_opener(HTTPCookieProcessor(self.cookies))

    def call(self, payload=None, *, method=None, headers=None, path='api/index.php'):
        data = json.dumps(payload).encode() if payload is not None else None
        request = Request(self.base + path, data=data, headers=headers or {}, method=method)
        try:
            response = self.opener.open(request, timeout=5)
        except HTTPError as caught:
            response = caught
        with response:
            raw = response.read()
            if 'application/json' in response.headers.get('Content-Type', ''):
                raw = json.loads(raw)
            return response.status, response.headers, raw

    def state(self):
        status, headers, data = self.call()
        assert status == 200 and isinstance(data, dict), (status, data)
        assert 'no-store' in headers.get('Cache-Control', ''), dict(headers)
        return data

    def post(self, action, *, allowed=True, expected_status=None, **fields):
        status, _, data = self.call(
            {'action': action, **fields},
            headers={'Content-Type': 'application/json', 'X-CSRF-Token': self.state()['csrf']},
        )
        if expected_status is not None:
            assert status == expected_status, (action, status, data)
        elif allowed:
            assert 200 <= status < 300, (action, status, data)
        else:
            assert 400 <= status < 500, (action, status, data)
        return data


@contextmanager
def server(sharon=True):
    with tempfile.TemporaryDirectory(prefix='eventlijn-http-') as work:
        work = Path(work)
        private = work / 'data'
        sessions = work / 'sessions'
        public = work / 'public'
        private.mkdir(mode=0o700)
        sessions.mkdir(mode=0o700)
        public.mkdir()
        (public / 'eventlijn').symlink_to(ROOT, target_is_directory=True)
        with socket.socket() as bound:
            bound.bind(('127.0.0.1', 0))
            port = bound.getsockname()[1]
        base = f'http://127.0.0.1:{port}/eventlijn/'
        environment = {
            **os.environ, 'EVENTLIJN_DATA_DIR': str(private), 'EVENTLIJN_BASE_URL': base,
            'EVENTLIJN_MAIL_FROM': 'eventlijn@example.test', 'EVENTLIJN_MAIL_MODE': 'file',
            'EVENTLIJN_SHARON_URL': SHARON if sharon else '',
        }
        with (work / 'php.log').open('wb') as log:
            process = subprocess.Popen([
                os.environ.get('PHP', 'php'), '-d', f'session.save_path={sessions}',
                '-S', f'127.0.0.1:{port}', '-t', str(public),
            ], cwd=ROOT, env=environment, stdout=log, stderr=log)
            try:
                for _ in range(50):
                    try:
                        if Client(base).call(path='index.html')[0] == 200:
                            break
                    except URLError:
                        if process.poll() is not None:
                            break
                    time.sleep(0.05)
                else:
                    raise AssertionError('PHP server did not start')
                assert process.poll() is None, 'PHP server exited prematurely'
                yield base, private
            except Exception:
                print((work / 'php.log').read_text())
                raise
            finally:
                process.terminate()
                process.wait(timeout=5)


def mail_for(directory, address):
    messages = []
    for path in (directory / 'mail').glob('*'):
        if path.is_file():
            message = json.loads(path.read_text())
            if address == message['email']:
                messages.append((path.stat().st_mtime_ns, message))
    return [message for _, message in sorted(messages)]


def token_from_mail(directory, address, action):
    pattern = re.compile(r'#' + re.escape(action) + r'=([a-f0-9]{32}\.[a-f0-9]{32,128})(?![a-f0-9])')
    for message in reversed(mail_for(directory, address)):
        match = pattern.search(message['body'])
        if match:
            return match[1]
    raise AssertionError(f'Missing {action} mail for {address}')


def registration(client, address, organization, **fields):
    payload = {
        'name': address.split('@')[0].title(), 'email': address, 'organization': organization,
        'password': PASSWORD, **fields,
    }
    client.post('auth.register', **payload)
    return payload


def verification(client, directory, address):
    token = token_from_mail(directory, address, 'verify')
    client.post('auth.verify', token=token)
    client.post('auth.verify', token=token, allowed=False)
    return token


def member(admin, address):
    return next(row for row in admin.state()['members'] if row['email'] == address)


def invite_member(admin, client, directory, address, organization, role, manager=None):
    before = len(mail_for(directory, address))
    admin.post('member.invite', email=address, role=role)
    invitation = token_from_mail(directory, address, 'invite')
    assert len(mail_for(directory, address)) > before
    outsider = Client(client.base)
    outsider.post('auth.register', allowed=False, name='Ander adres', email='others@example.test',
                  organization=organization, invite=invitation, password=PASSWORD)
    registration(client, address, organization, invite=invitation,
                 **({'managerEmail': manager['email']} if manager else {}))
    verification(client, directory, address)
    client.post('auth.login', email=address, password=PASSWORD)
    pending = client.state()
    assert pending['actor']['status'] == 'pending'
    assert not pending['events'] and not pending['requests'] and not pending['members']
    client.post('event.create', allowed=False)
    row = member(admin, address)
    admin.post('member.approve', id=row['id'],
               **({'manager': manager['id']} if manager else {}))
    client.post('auth.login', email=address, password=PASSWORD)
    assert client.state()['actor']['role'] == role
    assert client.state()['actor']['status'] == 'active'
    return member(admin, address), invitation


def create_event(client, title, kind='external', registration_mode='approval'):
    client.post('event.create', title=title, start=DAY, end=TOMORROW, location='Utrecht',
                topic='Geveltechniek', kind=kind, registration=registration_mode)
    return next(row for row in client.state()['events'] if row['title'] == title)


def request_for(client, event, day=DAY, **fields):
    client.post('request.create', eventId=event['id'], day=day, **fields)
    return next(row for row in client.state()['requests']
                if row['eventId'] == event['id'] and row['day'] == day)


def request_by_id(client, identifier):
    return next(row for row in client.state()['requests'] if row['id'] == identifier)


def run_flows(base, directory):
    admin, manager, employee, coworker, other_admin, other_employee, stranger = [
        Client(base) for _ in range(7)
    ]
    admin_mail = 'beheer@example.test'
    manager_mail = 'leiding@example.test'
    employee_mail = 'werknemer@example.test'
    organization = 'Geveltechniek testorganisatie'

    initial = stranger.state()
    assert initial['actor'] is None and not initial['events'] and not initial['requests']
    stranger.post('event.create', allowed=False)
    original_cookie = next(iter(stranger.cookies))
    assert original_cookie.has_nonstandard_attr('HttpOnly')
    assert original_cookie.path == '/eventlijn/'
    health, _, health_data = stranger.call(path='api/health.php')
    assert health == 200 and health_data['storage'] == 'configured'
    status, headers, _ = stranger.call(
        {'action': 'auth.forgot', 'email': employee_mail},
        headers={'Content-Type': 'application/json'},
    )
    assert status == 403 and 'Access-Control-Allow-Origin' not in headers
    status, headers, _ = stranger.call(method='PUT')
    assert status == 405 and 'POST' in headers.get('Allow', '')
    status, _, _ = stranger.call(
        {'action': 'auth.forgot', 'email': employee_mail},
        headers={'Content-Type': 'text/plain', 'X-CSRF-Token': initial['csrf']},
    )
    assert status == 415

    registration(admin, admin_mail, organization)
    admin.post('auth.login', email=admin_mail, password=PASSWORD, allowed=False)
    verification(admin, directory, admin_mail)
    before_login = next(iter(admin.cookies)).value
    admin.post('auth.login', email=admin_mail, password=PASSWORD)
    assert next(iter(admin.cookies)).value != before_login, 'Login did not rotate the session'
    assert admin.state()['actor']['role'] == 'admin'

    manager_row, _ = invite_member(admin, manager, directory, manager_mail, organization, 'manager')
    employee_row, invitation = invite_member(
        admin, employee, directory, employee_mail, organization, 'employee', manager_row)
    assert employee_row['role'] == 'employee'
    assert PASSWORD not in json.dumps(admin.state())
    stranger.post('auth.register', allowed=False, name='Ander adres', email='others@example.test',
                  organization=organization, invite=invitation, password=PASSWORD)
    employee.post('member.invite', email='abuse@example.test', role='admin', allowed=False)
    invite_member(admin, coworker, directory, 'collega@example.test', organization, 'employee', manager_row)
    print('OK organisation creation, verification, invitations, account approval and role limits')

    registration(other_admin, 'beheer-b@example.test', 'Andere organisatie')
    verification(other_admin, directory, 'beheer-b@example.test')
    other_admin.post('auth.login', email='beheer-b@example.test', password=PASSWORD)
    invite_member(other_admin, other_employee, directory, 'werknemer-b@example.test',
                  'Andere organisatie', 'employee', member(other_admin, 'beheer-b@example.test'))
    other_admin.post('member.approve', id=employee_row['id'], allowed=False)

    event = create_event(employee, 'Geveltechniek <bezoek>')
    employee.post('event.create', title=event['title'], start=DAY, end=TOMORROW,
                  kind='external', registration='approval', allowed=False)
    employee.post('request.create', eventId=event['id'], day='2020-01-01',
                  goal='Doel', benefit='Opbrengst', allowed=False)
    other_employee.post('request.create', eventId=event['id'], day=DAY,
                        goal='Doel', benefit='Opbrengst', allowed=False)
    assert not other_admin.state()['events'] and not other_admin.state()['requests']
    secret_goal = 'Vertrouwelijke aanvraagtekst voor deze organisatie'
    notifications_before = len(mail_for(directory, manager_mail))
    request = request_for(employee, event, goal=secret_goal, benefit='Voorbeeldopbrengst')
    assert request['status'] == 'manager_pending'
    request_id = request['id']
    assert request_by_id(manager, request_id)['goal'] == secret_goal
    assert request_by_id(admin, request_id)['status'] == 'manager_pending'
    assert secret_goal not in json.dumps(stranger.state())
    assert secret_goal not in json.dumps(other_admin.state())
    assert not coworker.state()['requests'], 'A coworker can see someone else’s private request'
    assert not coworker.state()['members']
    assert len(mail_for(directory, manager_mail)) > notifications_before, 'Expected workflow notification'
    assert all(secret_goal not in msg['body'] for msg in mail_for(directory, manager_mail))

    employee.post('request.create', eventId=event['id'], day=DAY,
                  goal='Dubbele aanvraag', benefit='Hetzelfde bezoek', allowed=False)
    employee.post('request.decide', requestId=request_id, decision='approve', allowed=False)
    coworker.post('request.decide', requestId=request_id, decision='approve', expected_status=404)
    other_admin.post('request.decide', requestId=request_id, decision='approve', allowed=False)
    admin.post('request.decide', requestId=request_id, decision='approve', allowed=False)
    employee.post('sharon.handoff', id=request_id, allowed=False)
    manager.post('request.decide', requestId=request_id, decision='approve')
    assert request_by_id(employee, request_id)['status'] == 'organization_pending'
    manager.post('request.decide', requestId=request_id, decision='approve', allowed=False)
    admin.post('request.decide', requestId=request_id, decision='approve')
    assert request_by_id(employee, request_id)['status'] == 'approved'
    assert next(row for row in employee.state()['events'] if row['id'] == event['id'])['represented']
    print('OK external event, two-stage approval, and organisation isolation')

    manager.post('sharon.handoff', id=request_id, allowed=False)
    admin.post('sharon.handoff', id=request_id, allowed=False)
    handoff = employee.post('sharon.handoff', id=request_id)
    url = urlsplit(handoff['url'])
    assert url.scheme == 'https' and url.hostname == 'sharon.life'
    assert not url.query, 'Handoff exposed data in the query string'
    fragment = parse_qs(url.fragment)['eventlijn'][0]
    assert json.loads(fragment) == {'title': event['title'], 'day': DAY, 'location': 'Utrecht'}

    employee.post('request.evaluate', requestId=request_id, outcome='Eigen evaluatie',
                  reference='Interne kennisbank')
    assert request_by_id(employee, request_id)['evaluation']['outcome'] == 'Eigen evaluatie'
    assert 'evaluation' not in request_by_id(manager, request_id)
    admin.post('request.close', requestId=request_id, result='Doel behaald')
    assert request_by_id(employee, request_id)['status'] == 'closed'
    employee.post('spot.propose', requestId=request_id, text='Vrijwillig gedeeld inzicht')
    assert not employee.state()['spotlights'] and not coworker.state()['spotlights']
    admin.post('spot.publish', requestId=request_id, confirmed=False, allowed=False)
    admin.post('spot.publish', requestId=request_id, confirmed=True)
    assert employee.state()['spotlights'][0]['text'] == 'Vrijwillig gedeeld inzicht'
    assert coworker.state()['spotlights'][0]['text'] == 'Vrijwillig gedeeld inzicht'
    assert secret_goal not in json.dumps(coworker.state()['spotlights'])
    assert not other_employee.state()['spotlights']
    employee.post('spot.withdraw', requestId=request_id)
    assert not employee.state()['spotlights']

    free_event = create_event(admin, 'Interne vrije sessie', 'internal', 'free')
    free_request = request_for(employee, free_event)
    assert free_request['status'] == 'approved'
    admin.post('request.close', requestId=free_request['id'],
               result='Mondeling afgehandeld', note='Mondeling gesproken')
    assert request_by_id(employee, free_request['id'])['status'] == 'closed'

    rejection = request_for(employee, event, day=TOMORROW, goal='Andere dag', benefit='Nieuwe kennis')
    manager.post('request.decide', requestId=rejection['id'], decision='reject', reason='Niet mogelijk')
    assert request_by_id(employee, rejection['id'])['status'] == 'rejected_manager'
    admin.post('request.decide', requestId=rejection['id'], decision='approve')
    employee.post('request.evaluate', requestId=rejection['id'], outcome='Te vroeg', allowed=False)
    admin.post('request.decide', requestId=rejection['id'], decision='revoke', reason='Planning gewijzigd')
    assert request_by_id(employee, rejection['id'])['status'] == 'revoked'
    employee.post('sharon.handoff', id=rejection['id'], allowed=False)
    print('OK limited Sharon handoff, evaluation, spotlight, free registration and rejection')

    employee.post('auth.forgot', email=employee_mail)
    reset = token_from_mail(directory, employee_mail, 'reset')
    new_password = 'brand-new-password-for-eventlijn'
    stranger.post('auth.reset', token=reset, password=new_password)
    stranger.post('auth.reset', token=reset, password=new_password, allowed=False)
    assert not employee.state()['actor'], 'Password reset did not invalidate an existing session'
    employee.post('auth.login', email=employee_mail, password=PASSWORD, allowed=False)
    employee.post('auth.login', email=employee_mail, password=new_password)
    employee.post('auth.logout')
    assert not employee.state()['actor']
    print('OK password reset replay, session invalidation and logout')


def main():
    with server() as (base, directory):
        run_flows(base, directory)
    with server(sharon=False) as (base, _):
        client = Client(base)
        assert not client.state()['sharonEnabled']
        client.post('sharon.handoff', id='0' * 32, allowed=False)
    print('OK standalone mode with Sharon disabled')


if __name__ == '__main__':
    main()
