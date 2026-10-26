"""Build local upload artifacts. This script never creates or modifies AWS resources."""

import argparse
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import zipfile


def add_file(archive, path, name):
    info = zipfile.ZipInfo(name)
    info.create_system = 3
    info.external_attr = (stat.S_IFREG | 0o644) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    archive.writestr(info, path.read_bytes())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--layer", action="store_true", help="Also download Linux/Python 3.13 Pillow and build its layer")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    source = root / "lambda" / "poster-thumbnail"
    output = root / "var" / "lambda"
    output.mkdir(parents=True, exist_ok=True)
    code_zip = output / "classicflix-miniaturas-code.zip"
    with zipfile.ZipFile(code_zip, "w") as archive:
        add_file(archive, source / "lambda_function.py", "lambda_function.py")
    print(code_zip)
    if not args.layer:
        return
    # Fresh, bounded workspace; never remove or modify the user's installed packages.
    with tempfile.TemporaryDirectory(prefix="pillow-build-", dir=output) as temporary:
        work = Path(temporary).resolve()
        if not work.is_relative_to(output.resolve()):
            raise RuntimeError("Unexpected layer build path")
        subprocess.run([
            sys.executable, "-m", "pip", "install",
            "--platform", "manylinux2014_x86_64",
            "--python-version", "3.13", "--implementation", "cp",
            "--only-binary=:all:", "--no-compile", "--disable-pip-version-check",
            "--target", str(work / "python"),
            "-r", str(source / "requirements-layer.txt"),
        ], check=True)
        layer_zip = output / "classicflix-pillow-python313-x86_64.zip"
        with zipfile.ZipFile(layer_zip, "w") as archive:
            for path in sorted((work / "python").rglob("*")):
                if path.is_file():
                    add_file(archive, path, path.relative_to(work).as_posix())
        print(layer_zip)


if __name__ == "__main__":
    main()
