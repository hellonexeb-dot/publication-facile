"""Construit dist/publication-facile-<version>.zip (chemins en / pour tous les serveurs)."""
import pathlib, re, zipfile

root = pathlib.Path(__file__).parent
src = root / "publication-facile"
version = re.search(r"Version:\s*([\d.]+)", (src / "publication-facile.php").read_text(encoding="utf8")).group(1)
dist = root / "dist"
dist.mkdir(exist_ok=True)
out = dist / f"publication-facile-{version}.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(src.rglob("*")):
        if f.is_file():
            z.write(f, "publication-facile/" + f.relative_to(src).as_posix())
print(out, out.stat().st_size, "octets")
