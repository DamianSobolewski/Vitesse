#!/usr/bin/env python3
"""Parser strony generacji ze starego katalogu Vitesse.
Zalazek scrapera z fazy 1 - tu uzywany do wyciagniecia danych demonstracyjnych."""
import re, sys, json, html, urllib.request

UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
FUEL = {"benzynowe": "benzyna", "diesel": "diesel", "hybrydowe": "hybryda", "elektryczne": "elektryk"}


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=40) as r:
        return r.read().decode("utf-8", "replace")


def clean(s):
    return re.sub(r"\s+", " ", html.unescape(s).replace("\xa0", " ")).strip()


def num(s):
    m = re.search(r"(\d+)", s.replace("\xa0", " "))
    return int(m.group(1)) if m else None


def parse(page_html):
    engines = []
    for tbl in re.findall(r"<table class=\"alt\">.*?</table>", page_html, re.S):
        head = clean(re.sub(r"<[^>]+>", " ", tbl[:tbl.find("</thead>")] if "</thead>" in tbl else tbl))
        fuel = next((v for k, v in FUEL.items() if k in head.lower()), "nieznane")

        body = tbl[tbl.find("<tbody>"):] if "<tbody>" in tbl else tbl
        for row in re.findall(r"<tr>(.*?)</tr>", body, re.S):
            cells = re.findall(r"<td[^>]*>(.*?)</td>", row, re.S)
            if len(cells) < 3:
                continue
            link = re.search(r'href="([^"]+)"', cells[0])
            vals = [num(clean(re.sub(r"<[^>]+>", " ", c))) for c in cells[1:]]

            engines.append({
                "name": clean(re.sub(r"<[^>]+>", " ", cells[0])),
                "legacy_key": html.unescape(link.group(1)).split("auto=", 1)[-1].replace("\xa0", " ") if link else None,
                "fuel": fuel,
                "stock_hp": vals[0] if len(vals) > 0 else None,
                "stock_nm": vals[1] if len(vals) > 1 else None,
                "chip_hp":  vals[2] if len(vals) > 2 else None,
                "chip_nm":  vals[3] if len(vals) > 3 else None,
                "box_hp":   vals[4] if len(vals) > 4 else None,
                "box_nm":   vals[5] if len(vals) > 5 else None,
            })
    return engines


if __name__ == "__main__":
    key = sys.argv[1]
    doc = fetch("https://vitesse.auto.pl/chiptuning_lodz.php?auto=" + urllib.parse.quote(key))
    title = clean(re.search(r"<title>(.*?)</title>", doc, re.S).group(1))
    rows = parse(doc)
    print(json.dumps({"key": key, "title": title, "engines": rows}, ensure_ascii=False, indent=1))
