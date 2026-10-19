# -*- coding: utf-8 -*-
"""
QA — vérification de l'impression des documents (devis / facture).

Génère 3 PDF via Chrome headless :
  - AVANT : CSS sans le bloc de fix (thème sombre)  -> anomalies attendues
  - APRÈS : thème sombre + thème clair              -> tout doit être correct

Vérifications (PyMuPDF : coordonnées et couleurs fiables) :
  1. une seule page, aucun chrome (navbar/sidebar/footer/modale),
  2. tout le contenu du document est imprimé,
  3. PAS de fond sombre plein papier (fuite du thème sombre),
  4. les textes du corps sont en ENCRE sombre (pas de blanc sur blanc),
  5. l'en-tête (FACTURE / n°) est aligné À DROITE (bords droits alignés),
  6. les blocs CLIENT / VÉHICULE sont côte à côte,
  7. le tableau occupe toute la largeur + bandeau d'en-tête sombre,
  8. le bandeau TOTAL TTC est bien imprimé.

Usage : python tests/qa_print_check.py   (nécessite Chrome + pymupdf)
"""
import base64
import json
import re
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

import pymupdf
import websocket

ROOT = Path(__file__).resolve().parent.parent
TESTS = ROOT / "tests"
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
MARKER = "19. IMPRESSION DOCUMENTS (devis / facture)"

failures = []


# ---------------------------------------------------------------- fixtures
def build_variants():
    """CSS/HTML « avant » (sans fix) + variante claire du fixture."""
    css = (ROOT / "assets" / "css" / "style.css").read_text(encoding="utf-8")
    idx = css.find(MARKER)
    cut = css.rfind("/* ==", 0, idx)
    (TESTS / "_qa_before.css").write_text(css[:cut], encoding="utf-8")

    html = (TESTS / "print_facture_fixture.html").read_text(encoding="utf-8")
    (TESTS / "_qa_before.html").write_text(
        html.replace("../assets/css/style.css", "_qa_before.css"), encoding="utf-8")
    (TESTS / "_qa_print_light.html").write_text(
        html.replace('data-theme="dark"', 'data-theme="light"'), encoding="utf-8")


def make_pdf(html_name, pdf_name, profile):
    out = TESTS / pdf_name
    if out.exists():
        out.unlink()
    cmd = [
        CHROME, "--headless=new", "--disable-gpu",
        "--user-data-dir=" + tempfile.gettempdir() + "\\chrome_" + profile,
        "--no-pdf-header-footer", "--virtual-time-budget=8000",
        "--print-to-pdf=" + str(out),
        "file:///" + (TESTS / html_name).as_posix(),
    ]
    subprocess.run(cmd, capture_output=True, timeout=60)
    return out


# ------------------------------------------- en-têtes/pieds navigateur
# Chrome injecte par défaut : titre + date (en-tête), lien URL + « 1/1 »
# (pied). On émule la case « En-têtes et pieds » COCHÉE via CDP.
MARKERS_NAV = ("QA impression", "file:", "1/1")


def nav_headers_pdf(html_name, profile):
    """PDF via CDP avec displayHeaderFooter=True (case cochée)."""
    port = 9348
    proc = subprocess.Popen(
        [CHROME, "--headless=new", "--disable-gpu", "--no-first-run",
         "--remote-debugging-port=%d" % port, "--remote-allow-origins=*",
         "--user-data-dir=" + tempfile.gettempdir() + "\\chrome_" + profile,
         "about:blank"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        base = "http://127.0.0.1:%d" % port
        for _ in range(50):
            try:
                urllib.request.urlopen(base + "/json/version", timeout=1)
                break
            except Exception:
                time.sleep(0.2)
        targets = json.loads(urllib.request.urlopen(base + "/json/list").read())
        target = next(t for t in targets if t["type"] == "page")
        ws = websocket.create_connection(target["webSocketDebuggerUrl"], timeout=30)
        seq = [0]

        def send(method, params=None):
            seq[0] += 1
            ws.send(json.dumps({"id": seq[0], "method": method,
                                "params": params or {}}))
            while True:
                msg = json.loads(ws.recv())
                if msg.get("id") == seq[0]:
                    return msg.get("result", {})

        send("Page.enable")
        send("Page.navigate", {"url": (TESTS / html_name).as_uri()})
        time.sleep(2.5)                       # CSS + polices
        res = send("Page.printToPDF", {"printBackground": True,
                                       "displayHeaderFooter": True,
                                       "preferCSSPageSize": True})
        ws.close()
        return base64.b64decode(res["data"])
    finally:
        proc.terminate()


def check_browser_headers(pdf_bytes, expect_present, label):
    doc = pymupdf.open(stream=pdf_bytes, filetype="pdf")
    text = " ".join(p.get_text() for p in doc)
    found = [m for m in MARKERS_NAV if m in text]
    check(label, bool(found) == expect_present,
          ("present : " + ", ".join(found)) if found else "aucun")


# ---------------------------------------------------------------- analyse
def load(path):
    doc = pymupdf.open(str(path))
    page = doc[0]
    words = page.get_text("words")           # x0, y0, x1, y1, mot, ...
    text = re.sub(r"\s+", " ", page.get_text())
    spans = []                               # (texte, RGB, x0, y0)
    for b in page.get_text("dict")["blocks"]:
        for l in b.get("lines", []):
            for s in l["spans"]:
                c = s["color"]
                spans.append((s["text"], ((c >> 16) & 255, (c >> 8) & 255, c & 255),
                              s["bbox"][0], s["bbox"][1]))
    fills = []                               # (rect, (r, v, b))
    for d in page.get_drawings():
        if d.get("fill") is not None:
            fills.append((d["rect"], d["fill"]))
    return doc, page, words, text, spans, fills


def word(words, s):
    """Premier mot contenant s -> (x0, y0, x1, y1)."""
    for w in words:
        if s in w[4]:
            return w[0], w[1], w[2], w[3]
    return None


def check(label, cond, detail=""):
    print(("  OK  " if cond else "  KO  ") + label + ("  [" + detail + "]" if detail else ""))
    if not cond:
        failures.append(label + " " + detail)


# ---------------------------------------------------------------- contrôle
def verify(pdf, theme, expect_ok):
    """expect_ok=True : état « après fix » ; False : état « avant fix »."""
    print("\n=== " + pdf.name + " (theme " + theme + ") ===")
    doc, page, words, text, spans, fills = load(pdf)
    pw, ph = page.rect.width, page.rect.height
    margin = 33.75                     # 12 mm
    content_w = pw - 2 * margin

    # --- contraintes absolues (vraies avant ET après) ---
    check("une seule page imprimee", len(doc) == 1, str(len(doc)) + " page(s)")

    for bad in ("Tableau de bord", "Imprimer", "Modale"):
        check("chrome masque : \u00ab " + bad + " \u00bb absent", bad not in text,
              "PRESENT" if bad in text else "absent")

    for good in ("FACTURE", "FAC-2026-0005", "CLIENT", "HICULE",
                 "SIGNATION", "TTC", "Signature"):
        check("contenu present : \u00ab " + good + " \u00bb", good in text,
              "present" if good in text else "ABSENT")

    ttc = [r for r, f in fills
           if 150 < r.width < 300 and 20 < r.height < 45 and sum(f) < 0.6]
    check("bandeau TOTAL TTC imprime", bool(ttc), str(len(ttc)) + " bande(s)")

    # --- contraintes différenciatrices (état attendu selon expect_ok) ---
    # 3. pas de fond sombre plein papier (fuite du thème sombre)
    full_dark = [r for r, f in fills
                 if sum(f) < 0.6 and r.width * r.height >= 0.75 * pw * ph]
    check("pas de fond sombre plein papier", (not full_dark) == expect_ok,
          str(len(full_dark)) + " fond(s) sombre(s) plein papier")

    # 4. textes du corps en encre sombre (jamais gris clair sur blanc)
    markers = ("Vidange", "Karim", "Merci", "17 400")
    light = [(t, f) for t, f, _, _ in spans
             if any(m in t for m in markers) and max(f) > 0x70]
    check("textes du corps en encre sombre", (not light) == expect_ok,
          str(len(light)) + " texte(s) clair(s) illisible(s)")

    # 5. en-tête du document aligné à droite (bords droits cohérents)
    t1, t2 = word(words, "FACTURE"), word(words, "DEV-2026")
    if t1 and t2:
        right_ok = abs(t1[2] - t2[2]) <= 6 and t1[2] >= pw - margin - 16
        check("en-tete (FACTURE) aligne a droite", right_ok == expect_ok,
              "bord droit FACTURE=%.0f / REF=%.0f" % (t1[2], t2[2]))
    else:
        check("en-tete (FACTURE) aligne a droite", not expect_ok, "texte absent")

    # 6. CLIENT / VÉHICULE côte à côte
    c, v = word(words, "CLIENT"), word(words, "HICULE")
    if c and v:
        side = abs(c[1] - v[1]) < 40 and v[0] > c[0] + 150
        check("blocs CLIENT / VEHICULE cote a cote", side == expect_ok,
              "CLIENT=(%.0f,%.0f) VEHICULE=(%.0f,%.0f)" % (c[0], c[1], v[0], v[1]))
    else:
        check("blocs CLIENT / VEHICULE cote a cote", not expect_ok, "bloc(s) absent(s)")

    # 7. tableau pleine largeur (bandeau d'en-tête sombre cumulé)
    band = {}
    for r, f in fills:
        if 15 < r.height < 60 and sum(f) < 0.6:
            band[round(r.y0)] = band.get(round(r.y0), 0) + r.width
    th_full = any(w >= 0.8 * content_w for w in band.values())
    check("tableau pleine largeur + bandeau d'en-tete", th_full == expect_ok,
          "max=%.0f / %.0f pt" % (max(band.values()) if band else 0, content_w))

    # 8. colonnes : en-tête et corps alignés (qualité, toujours vrai)
    d0, v0 = word(words, "SIGNATION"), word(words, "Vidange")
    if d0 and v0:
        check("colonne DESIGNATION alignee en-tete/corps", abs(d0[0] - v0[0]) <= 8,
              "%.0f vs %.0f" % (d0[0], v0[0]))


def main():
    build_variants()
    before = make_pdf("_qa_before.html", "_qa_print_before.pdf", "before")
    dark = make_pdf("print_facture_fixture.html", "_qa_print_dark.pdf", "dark")
    light = make_pdf("_qa_print_light.html", "_qa_print_light.pdf", "light")

    print("---------- AVANT le fix (CSS d'origine) : anomalies attendues ----------")
    verify(before, "dark", expect_ok=False)
    print("\n---------- APRES le fix ----------")
    verify(dark, "dark", expect_ok=True)
    verify(light, "light", expect_ok=True)

    print("\n---------- En-tetes / pieds du navigateur (case cochee) ----------")
    check_browser_headers(nav_headers_pdf("_qa_before.html", "navbefore"), True,
                          "AVANT : en-tetes/pieds navigateur affiches (titre, URL, 1/1)")
    check_browser_headers(nav_headers_pdf("print_facture_fixture.html", "navdark"), False,
                          "APRES dark : aucun en-tete ni pied de page")
    check_browser_headers(nav_headers_pdf("_qa_print_light.html", "navlight"), False,
                          "APRES light : aucun en-tete ni pied de page")

    print("\n========== RESULTAT ==========")
    if failures:
        print(str(len(failures)) + " echec(s) :")
        for f in failures:
            print("  - " + f)
    else:
        print("Toutes les verifications ont reussi :")
        print(" - les anomalies sont bien presentes AVANT le fix,")
        print(" - et absentes APRES le fix, dans les deux themes.")

    # nettoyage des fichiers generes (le fixture et le script restent)
    for name in ("_qa_before.css", "_qa_before.html", "_qa_print_light.html",
                 "_qa_print_before.pdf", "_qa_print_dark.pdf", "_qa_print_light.pdf"):
        p = TESTS / name
        if p.exists():
            p.unlink()

    if failures:
        sys.exit(1)


if __name__ == "__main__":
    main()

