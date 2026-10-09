"""Build and verify a deterministic WordPress plugin ZIP from this repository.

Usage: python tools/build_release.py [output.zip]
The unused 1.8.0 PermalinkManagerIntegration.php is retained in Git history and
the working tree for audit, but is excluded from the 2.0.x runtime package.
"""

from __future__ import annotations

import hashlib
import sys
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
VERSION = "2.0.9"
OUTPUT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else ROOT / "dist" / f"headless-api-{VERSION}.zip"
ROOT_FILES = [ROOT / name for name in (".gitattributes", "CHANGELOG.md", "README.md", "headless-api.php")]
EXCLUDED = {"includes/Integrations/PermalinkManagerIntegration.php"}


def sources() -> list[Path]:
    files = ROOT_FILES + sorted(path for path in (ROOT / "includes").rglob("*") if path.is_file())
    result = [path for path in files if path.relative_to(ROOT).as_posix() not in EXCLUDED]
    missing = [str(path) for path in ROOT_FILES if not path.is_file()]
    if missing:
        raise SystemExit(f"Missing plugin source: {missing}")
    return result


def build(output: Path, files: list[Path]) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            name = "headless-api/" + path.relative_to(ROOT).as_posix()
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def verify(output: Path, files: list[Path]) -> None:
    with zipfile.ZipFile(output) as archive:
        expected = {"headless-api/" + path.relative_to(ROOT).as_posix(): path for path in files}
        actual = set(archive.namelist())
        if actual != set(expected):
            raise SystemExit(f"ZIP manifest mismatch: missing={set(expected) - actual}, extra={actual - set(expected)}")
        for name, path in expected.items():
            if archive.read(name) != path.read_bytes():
                raise SystemExit(f"ZIP/source mismatch: {name}")
        if archive.testzip() is not None:
            raise SystemExit("ZIP integrity failure")


plugin_files = sources()
build(OUTPUT, plugin_files)
verify(OUTPUT, plugin_files)
print(f"BUILD_RELEASE_ZIP PASS files={len(plugin_files)} sha256={hashlib.sha256(OUTPUT.read_bytes()).hexdigest()} output={OUTPUT}")
