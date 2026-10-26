import os
import sys
import unittest
import io
import json
from pathlib import Path
from urllib.error import HTTPError
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import packagist


class PackagistPublicationTest(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, {
            'PACKAGIST_USERNAME': 'test-user',
            'PACKAGIST_TOKEN': 'test-token',
            'GITHUB_REPOSITORY': 'noldor/phpcsfixer-rules',
            'GITHUB_SHA': 'a' * 40,
            'RELEASE_TAG': 'v0.0.1',
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def metadata(self, reference='a' * 40):
        return {'packages': {'noldors/phpcsfixer-rules': [{
            'version': 'v0.0.1', 'source': {'reference': reference},
        }]}}

    @patch.object(packagist, 'request_json')
    def testAuthenticatesAndVerifiesPublishedCommit(self, request):
        request.side_effect = [{'status': 'success'}, self.metadata()]
        packagist.publish_release('noldors/phpcsfixer-rules')
        update = request.call_args_list[0]
        self.assertEqual(update.args[0], 'https://packagist.org/api/update-package')
        self.assertEqual(update.kwargs['headers']['Authorization'], 'Bearer test-user:test-token')
        self.assertEqual(update.kwargs['data'], {'repository': 'https://github.com/noldor/phpcsfixer-rules'})

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testWaitsForTheQueuedUpdate(self, request, sleep):
        request.side_effect = [{'status': 'success'}, {'packages': {}}, self.metadata()]
        packagist.publish_release('noldors/phpcsfixer-rules')
        sleep.assert_called_once_with(5)

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testWaitsForInitialMetadataToBecomeAvailable(self, request, sleep):
        request.side_effect = [
            {'status': 'success'},
            HTTPError('https://repo.packagist.org', 404, 'Not found', None, None),
            self.metadata(),
        ]
        packagist.publish_release('noldors/phpcsfixer-rules')
        sleep.assert_called_once_with(5)

    @patch.object(packagist, 'request_json')
    def testRejectsAnUnsuccessfulUpdate(self, request):
        request.return_value = {'status': 'error'}
        with self.assertRaisesRegex(RuntimeError, 'accept'):
            packagist.publish_release('noldors/phpcsfixer-rules')
        self.assertEqual(request.call_count, 1)

    @patch.object(packagist, 'request_json')
    def testRejectsAVersionPointingAtAnotherCommit(self, request):
        request.side_effect = [{'status': 'success'}, self.metadata('b' * 40)]
        with self.assertRaisesRegex(RuntimeError, 'another commit'):
            packagist.publish_release('noldors/phpcsfixer-rules')

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testFailsWhenTheVersionNeverAppears(self, request, sleep):
        request.side_effect = [{'status': 'success'}] + [{'packages': {}}] * 36
        with self.assertRaisesRegex(RuntimeError, 'not visible'):
            packagist.publish_release('noldors/phpcsfixer-rules')


class PackagistRequestTest(unittest.TestCase):
    @patch.object(packagist, 'urlopen')
    def testSendsJsonAndKeepsCredentialsOutOfTheUrl(self, open_url):
        open_url.return_value.__enter__.return_value = io.BytesIO(b'{"status":"success"}')
        data = {'repository': 'https://github.com/noldor/phpcsfixer-rules'}
        result = packagist.request_json(
            'https://packagist.org/api/update-package',
            headers={'Authorization': 'Bearer test-user:test-token', 'Content-Type': 'application/json'},
            data=data,
        )
        request = open_url.call_args.args[0]
        self.assertEqual(request.full_url, 'https://packagist.org/api/update-package')
        self.assertEqual(request.get_method(), 'POST')
        self.assertEqual(request.get_header('Authorization'), 'Bearer test-user:test-token')
        self.assertEqual(json.loads(request.data), data)
        self.assertEqual(result, {'status': 'success'})


if __name__ == '__main__':
    unittest.main()
