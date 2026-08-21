#!/usr/bin/env python3
"""Skleja samodzielna makiete: szablon + fonty base64 + zdjecia + dane + skrypt."""
import json, pathlib

S = pathlib.Path("/tmp/claude-1000/-home-damian-Workspace-Vitesse/"
                 "bdf3180e-44cb-47e5-a8c4-6c4d5405a949/scratchpad/design")

html = pathlib.Path("design/kierunki.tpl.html").read_text()
html = html.replace("/*FONTS*/", (S / "fonts.css").read_text())

for key, uri in json.loads((S / "images.json").read_text()).items():
    html = html.replace(f"__IMG_{key}__", uri)

html = html.replace("__CATALOG__", json.dumps(
    json.loads((S / "catalog-demo.json").read_text()), ensure_ascii=False, separators=(",", ":")))
html = html.replace("__SCRIPT__", pathlib.Path("design/kierunki.js").read_text())

# wersja do publikacji: sama tresc, doctype i <head> dokleja host
art = pathlib.Path("design/kierunki-artifact.html")
art.write_text(html, encoding="utf-8")

# wersja samodzielna: pelny dokument z deklaracja kodowania.
# Bez <meta charset> przegladarka zgaduje - pierwsze ~600 KB to ASCII-owe base64
# fontow, wiec typuje windows-1252 i rozsypuje polskie znaki.
doc = ('<!doctype html>\n<html lang="pl">\n<head>\n<meta charset="utf-8">\n'
       '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
       + html + "\n</html>\n")
out = pathlib.Path("design/kierunki-wizualne.html")
out.write_text(doc, encoding="utf-8")
print(f"{out}  {len(doc)/1024/1024:.2f} MB")
print(f"{art}  {len(html)/1024/1024:.2f} MB")
for leftover in ("__IMG_", "__CATALOG__", "__SCRIPT__", "/*FONTS*/"):
    if leftover in html:
        print(f"  UWAGA: niepodmieniony placeholder {leftover}")
