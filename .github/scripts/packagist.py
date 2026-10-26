"""Refresh an existing Packagist package and verify the released commit."""

import json
import os
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def request_json(url, *, headers, data=None):
    body = None if data is None else json.dumps(data).encode()
    request = Request(url, data=body, headers=headers)
    with urlopen(request, timeout=30) as response:
        return json.load(response)


def publish_release(package):
    username = os.environ['PACKAGIST_USERNAME']
    token = os.environ['PACKAGIST_TOKEN']
    repository = os.environ['GITHUB_REPOSITORY']
    version = os.environ['RELEASE_TAG'].removeprefix('v')
    commit = os.environ['GITHUB_SHA']
    headers = {'User-Agent': f'{repository} release automation', 'Content-Type': 'application/json'}
    result = request_json(
        'https://packagist.org/api/update-package',
        headers={**headers, 'Authorization': f'Bearer {username}:{token}'},
        data={'repository': f'https://github.com/{repository}'},
    )
    if result.get('status') != 'success':
        raise RuntimeError('Packagist did not accept the refresh request.')

    for attempt in range(36):
        try:
            metadata = request_json(
                f'https://repo.packagist.org/p2/{package}.json',
                headers={'User-Agent': headers['User-Agent'], 'Cache-Control': 'no-cache'},
            )
        except HTTPError as error:
            if error.code != 404:
                raise
            metadata = {}
        except URLError:
            metadata = {}

        for release in metadata.get('packages', {}).get(package, []):
            if release.get('version', '').removeprefix('v') != version:
                continue
            if release.get('source', {}).get('reference') != commit:
                raise RuntimeError('The Packagist version points to another commit.')
            print(f'{package} {version} is visible on Packagist at {commit}.')
            return

        if attempt < 35:
            time.sleep(5)

    raise RuntimeError('The released version is not visible on Packagist; retry the release job.')


if __name__ == '__main__':
    with open('composer.json') as manifest:
        publish_release(json.load(manifest)['name'])
