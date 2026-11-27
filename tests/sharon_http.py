"""Exercise Event Line app sessions over HTTP without browser cookies."""
import json
import os
import subprocess
from pathlib import Path
from http_flows import Client, ROOT, server, registration, verification, PASSWORD, DAY, TOMORROW


def app_fixture(base, directory):
    script = r'''
require 'server/bootstrap.php';
$config=new Config();$store=new EventStore($config->directory,$_SERVER['DOCUMENT_ROOT']);$accounts=new Accounts($store,$config);
$account=$accounts->loginSharon(['id'=>'SharonAcct01','personId'=>'LocalUser001','email'=>'shared@example.test','name'=>'Sharon User']);
echo json_encode(['account'=>$account,'token'=>$accounts->createAppSession($account)]);
'''
    env = {**os.environ, 'EVENTLIJN_DATA_DIR': str(directory), 'EVENTLIJN_BASE_URL': base,
           'EVENTLIJN_MAIL_FROM': 'eventline@example.test', 'EVENTLIJN_MAIL_MODE': 'file'}
    script = "$_SERVER['DOCUMENT_ROOT']=" + json.dumps(str(directory.parent / 'public')) + ';' + script
    return json.loads(subprocess.check_output([os.environ.get('PHP', 'php'), '-r', script], cwd=ROOT, env=env))


def main():
    with server() as (base, directory):
        fixture = app_fixture(base, directory)
        token = fixture['token']
        headers = {'Authorization': 'Bearer ' + token}
        # This client has no session cookies. The app token alone resolves its identity.
        client = Client(base)
        status, response_headers, state = client.call(headers=headers)
        assert status == 200 and state['actor']['email'] == 'shared@example.test', state
        assert not response_headers.get('Set-Cookie') and state['csrf'] == ''
        assert list(client.cookies) == []
        payload = {'action': 'event.create', 'title': 'Without cookies', 'start': DAY, 'end': TOMORROW,
                   'location': 'Utrecht', 'topic': 'Digital', 'kind': 'external', 'registration': 'approval'}
        code, response_headers, result = client.call(payload, headers={**headers, 'Content-Type': 'application/json'})
        assert code == 200 and result['ok'] is True, result
        assert not response_headers.get('Set-Cookie')
        code, _, state = client.call(headers=headers)
        assert code == 200 and state['events'][0]['title'] == 'Without cookies'
        # A normal cookie session belongs to a different organization and cannot override the app identity.
        standalone = Client(base)
        registration(standalone, 'standalone@example.test', 'Cookie organization')
        verification(standalone, directory, 'standalone@example.test')
        standalone.post('auth.login', email='standalone@example.test', password=PASSWORD)
        assert standalone.state()['actor']['email'] == 'standalone@example.test'
        code, _, state = standalone.call(headers=headers)
        assert code == 200 and state['actor']['email'] == 'shared@example.test'
        assert standalone.state()['actor']['email'] == 'standalone@example.test'
        assert client.call(headers={'Authorization': 'Bearer malformed'})[0] == 401
        assert client.call(headers={'Authorization': 'Bearer ' + 'x' * 43})[0] == 401
        code, _, result = client.call({'action': 'auth.logout'}, headers={**headers, 'Content-Type': 'application/json'})
        assert code == 200 and result['ok'] is True
        assert client.call(headers=headers)[0] == 401
        # Embedded SSO cannot bypass the configured server trust key, even with a plausible code.
        code, response_headers, _ = client.call({'action': 'auth.sharon', 'embedded': True, 'code': 'x' * 43},
                                               headers={'Content-Type': 'application/json'})
        assert code == 503 and not response_headers.get('Set-Cookie')
        # Version changes and absolute expiry invalidate an otherwise well-formed app token.
        fixture = app_fixture(base, directory)
        registry_path = directory / ('0' * 32 + '.json')
        registry = json.loads(registry_path.read_text())
        for item in registry['appSessions'].values():
            item['until'] = 1
        registry_path.write_text(json.dumps(registry))
        assert client.call(headers={'Authorization': 'Bearer ' + fixture['token']})[0] == 401
        fixture = app_fixture(base, directory)
        organization_path = directory / (fixture['account']['org'] + '.json')
        organization = json.loads(organization_path.read_text())
        organization['members'][fixture['account']['id']]['sessionVersion'] += 1
        organization_path.write_text(json.dumps(organization))
        assert client.call(headers={'Authorization': 'Bearer ' + fixture['token']})[0] == 401
    print('OK no-cookie app sessions, CSRF isolation, cookie identity isolation, expiry, logout and missing trust key')


if __name__ == '__main__':
    main()
