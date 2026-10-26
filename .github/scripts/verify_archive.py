"""Verify that each dist archive contains the complete public package only."""

import sys
from pathlib import Path
from zipfile import ZipFile

root = Path(__file__).resolve().parents[2]
expected = {'composer.json', 'license.txt'} | {
    path.relative_to(root).as_posix() for path in (root / 'src').rglob('*') if path.is_file()
}

for filename in sys.argv[1:]:
    with ZipFile(filename) as archive:
        actual = {name for name in archive.namelist() if not name.endswith('/')}
    if actual != expected:
        raise SystemExit(
            f'{filename}: extra={sorted(actual - expected)}, missing={sorted(expected - actual)}'
        )
    print(f'{filename}: exact distribution allowlist verified.')
