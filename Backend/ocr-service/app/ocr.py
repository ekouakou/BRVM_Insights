"""
Extraction de texte par OCR et conversion en markdown.

Sert les rapports d'entreprise dont le PDF est un SCAN (une image de page,
sans couche texte) : l'extraction classique du backend PHP y renvoie
quelques dizaines de caractères parasites ("Classification : Internal"...)
alors que la page contient un rapport financier complet.

Aucune dépendance Python tierce ici : on pilote directement `pdftoppm`
(poppler) pour rasteriser les pages et `tesseract` pour les lire. Les deux
sont des binaires système, ce qui évite tout problème de compatibilité de
paquets, et tesseract expose sa mise en page en TSV — c'est elle qui permet
de reconstruire des titres et des tableaux plutôt qu'un bloc de texte plat.
"""

from __future__ import annotations

import csv
import io
import re
import shutil
import subprocess
import tempfile
from dataclasses import dataclass, field
from pathlib import Path

# Résolution de rastérisation. 300 dpi est le minimum pour que tesseract lise
# correctement des chiffres serrés dans un tableau financier ; au-delà, le
# gain de qualité ne compense plus le temps de traitement.
DEFAULT_DPI = 300
DEFAULT_LANG = "fra"

# Un PDF qui contient déjà une couche texte de cette longueur par page n'a
# pas besoin d'OCR — on le signale à l'appelant au lieu de perdre plusieurs
# minutes à le rasteriser.
TEXT_LAYER_CHARS_PER_PAGE = 200


class OcrError(RuntimeError):
    pass


def tool_path(name: str) -> str | None:
    return shutil.which(name)


def check_tools() -> dict:
    """État des binaires requis — exposé par /health pour diagnostiquer vite."""
    tesseract = tool_path("tesseract")
    langs: list[str] = []
    if tesseract:
        try:
            out = subprocess.run(
                [tesseract, "--list-langs"], capture_output=True, text=True, timeout=30
            )
            langs = [l.strip() for l in out.stdout.splitlines()[1:] if l.strip()]
        except Exception:
            langs = []
    return {
        "tesseract": tesseract,
        "pdftoppm": tool_path("pdftoppm"),
        "pdfinfo": tool_path("pdfinfo"),
        "languages": langs,
        "ready": bool(tesseract and tool_path("pdftoppm")),
    }


def pdf_page_count(pdf: Path) -> int | None:
    exe = tool_path("pdfinfo")
    if not exe:
        return None
    try:
        out = subprocess.run([exe, str(pdf)], capture_output=True, text=True, timeout=60)
        m = re.search(r"^Pages:\s+(\d+)", out.stdout, re.MULTILINE)
        return int(m.group(1)) if m else None
    except Exception:
        return None


def existing_text_layer(pdf: Path) -> str:
    """Texte déjà présent dans le PDF (couche texte), via pdftotext si dispo."""
    exe = tool_path("pdftotext")
    if not exe:
        return ""
    try:
        out = subprocess.run(
            [exe, "-layout", str(pdf), "-"], capture_output=True, text=True, timeout=120
        )
        return out.stdout or ""
    except Exception:
        return ""


@dataclass
class Word:
    text: str
    left: int
    top: int
    width: int
    height: int
    conf: float

    @property
    def right(self) -> int:
        return self.left + self.width


@dataclass
class Line:
    words: list[Word] = field(default_factory=list)

    @property
    def text(self) -> str:
        return " ".join(w.text for w in self.words).strip()

    @property
    def height(self) -> float:
        return sum(w.height for w in self.words) / len(self.words) if self.words else 0

    @property
    def confidence(self) -> float:
        vals = [w.conf for w in self.words if w.conf >= 0]
        return sum(vals) / len(vals) if vals else 0.0


def _parse_tsv(tsv: str) -> list[Line]:
    """
    Regroupe la sortie TSV de tesseract en lignes.

    Le TSV donne une position et une hauteur par mot : ce sont ces deux
    informations qui permettent ensuite de distinguer un titre (police plus
    grande) d'un paragraphe, et de repérer les colonnes d'un tableau grâce
    aux espaces horizontaux entre mots.
    """
    lines: dict[tuple, Line] = {}
    reader = csv.DictReader(io.StringIO(tsv), delimiter="\t", quoting=csv.QUOTE_NONE)
    for row in reader:
        try:
            if int(row.get("level", 0)) != 5:  # niveau 5 = un mot
                continue
            text = (row.get("text") or "").strip()
            if not text:
                continue
            key = (row["page_num"], row["block_num"], row["par_num"], row["line_num"])
            lines.setdefault(key, Line()).words.append(
                Word(
                    text=text,
                    left=int(row["left"]),
                    top=int(row["top"]),
                    width=int(row["width"]),
                    height=int(row["height"]),
                    conf=float(row.get("conf") or -1),
                )
            )
        except (KeyError, ValueError):
            continue
    return [lines[k] for k in sorted(lines.keys(), key=lambda k: tuple(int(x) for x in k))]


def _looks_like_table_row(line: Line, gap_threshold: int) -> list[str] | None:
    """
    Découpe une ligne en colonnes si elle présente au moins deux grands
    espaces horizontaux. C'est le motif d'un tableau financier scanné, où
    les colonnes sont séparées par du blanc et non par des séparateurs.
    """
    if len(line.words) < 3:
        return None
    cells: list[list[str]] = [[line.words[0].text]]
    for prev, word in zip(line.words, line.words[1:]):
        if word.left - prev.right >= gap_threshold:
            cells.append([word.text])
        else:
            cells[-1].append(word.text)
    if len(cells) < 3:
        return None
    return [" ".join(c).strip() for c in cells]


def lines_to_markdown(lines: list[Line], page_number: int | None = None) -> str:
    """
    Reconstruit un markdown lisible à partir des lignes détectées.

    Règles volontairement simples et prévisibles : une ligne nettement plus
    haute que la moyenne et courte devient un titre, une ligne à trois
    colonnes ou plus devient une ligne de tableau, le reste est du texte.
    Rien n'est inventé — aucune IA n'intervient à ce stade, le markdown ne
    contient que ce que l'OCR a lu.
    """
    if not lines:
        return ""

    heights = sorted(l.height for l in lines if l.height > 0)
    median_h = heights[len(heights) // 2] if heights else 0
    gap_threshold = max(20, int(median_h * 1.6)) if median_h else 40

    out: list[str] = []
    if page_number is not None:
        out.append(f"<!-- page {page_number} -->")

    table_buffer: list[list[str]] = []

    def flush_table() -> None:
        if not table_buffer:
            return
        # Un bloc d'UNE seule ligne ne fait pas un tableau : le rendre en
        # markdown produirait un en-tête suivi d'un corps vide, ce qui
        # s'affiche mal. On le restitue en texte, colonnes séparées.
        if len(table_buffer) == 1:
            out.append(" · ".join(c for c in table_buffer[0] if c))
            out.append("")
            table_buffer.clear()
            return
        width = max(len(r) for r in table_buffer)
        rows = [r + [""] * (width - len(r)) for r in table_buffer]
        header, body = rows[0], rows[1:]
        out.append("| " + " | ".join(header) + " |")
        out.append("|" + "|".join([" --- "] * width) + "|")
        for r in body:
            out.append("| " + " | ".join(r) + " |")
        out.append("")
        table_buffer.clear()

    for line in lines:
        text = line.text
        if not text:
            continue

        cells = _looks_like_table_row(line, gap_threshold)
        if cells:
            table_buffer.append(cells)
            continue
        flush_table()

        is_big = median_h and line.height >= median_h * 1.35
        is_short = len(text) <= 90
        is_upper = text.upper() == text and len(re.sub(r"[^A-ZÀ-Ý]", "", text)) >= 4

        if is_big and is_short:
            out.append(f"## {text}")
            out.append("")
        elif is_upper and is_short:
            out.append(f"### {text}")
            out.append("")
        else:
            out.append(text)

    flush_table()
    return "\n".join(out).strip()


@dataclass
class PageResult:
    page: int
    text: str
    markdown: str
    confidence: float
    words: int


def ocr_image(image: Path, lang: str = DEFAULT_LANG, psm: int = 1) -> tuple[str, list[Line]]:
    """Passe une image dans tesseract et renvoie (texte brut, lignes TSV)."""
    exe = tool_path("tesseract")
    if not exe:
        raise OcrError("tesseract introuvable sur ce serveur")

    base = ["-l", lang, "--psm", str(psm)]
    txt = subprocess.run(
        [exe, str(image), "stdout", *base], capture_output=True, text=True, timeout=600
    )
    if txt.returncode != 0:
        raise OcrError(f"tesseract a échoué : {txt.stderr.strip()[:400]}")

    tsv = subprocess.run(
        [exe, str(image), "stdout", *base, "tsv"], capture_output=True, text=True, timeout=600
    )
    lines = _parse_tsv(tsv.stdout) if tsv.returncode == 0 else []
    return txt.stdout, lines


def ocr_pdf(
    pdf: Path,
    lang: str = DEFAULT_LANG,
    dpi: int = DEFAULT_DPI,
    max_pages: int | None = None,
    first_page: int = 1,
    on_progress=None,
) -> list[PageResult]:
    """
    OCR page par page. Chaque page est rasterisée puis supprimée aussitôt
    lue : un rapport de 80 pages en 300 dpi représenterait sinon plusieurs
    gigaoctets d'images simultanément sur le disque.
    """
    if not pdf.exists():
        raise OcrError(f"Fichier introuvable : {pdf}")
    exe = tool_path("pdftoppm")
    if not exe:
        raise OcrError("pdftoppm (poppler) introuvable sur ce serveur")

    total = pdf_page_count(pdf) or 1
    last_page = total if max_pages is None else min(total, first_page + max_pages - 1)

    results: list[PageResult] = []
    with tempfile.TemporaryDirectory(prefix="brvm_ocr_") as tmp:
        tmpdir = Path(tmp)
        for page in range(first_page, last_page + 1):
            prefix = tmpdir / f"p{page}"
            render = subprocess.run(
                [exe, "-r", str(dpi), "-f", str(page), "-l", str(page), "-png",
                 str(pdf), str(prefix)],
                capture_output=True, text=True, timeout=600,
            )
            if render.returncode != 0:
                raise OcrError(f"Rastérisation de la page {page} impossible : {render.stderr.strip()[:300]}")

            images = sorted(tmpdir.glob(f"p{page}-*.png")) or sorted(tmpdir.glob(f"p{page}.png"))
            if not images:
                continue
            image = images[0]

            text, lines = ocr_image(image, lang=lang)
            markdown = lines_to_markdown(lines, page_number=page)
            confidence = (
                sum(l.confidence for l in lines) / len(lines) if lines else 0.0
            )
            results.append(
                PageResult(
                    page=page,
                    text=text,
                    markdown=markdown,
                    confidence=round(confidence, 1),
                    words=sum(len(l.words) for l in lines),
                )
            )
            image.unlink(missing_ok=True)
            if on_progress:
                on_progress(page, last_page)

    return results


def assemble(results: list[PageResult]) -> dict:
    """Assemble le résultat des pages en un texte et un markdown uniques."""
    text = "\n\n".join(r.text.strip() for r in results if r.text.strip())
    markdown = "\n\n".join(r.markdown.strip() for r in results if r.markdown.strip())
    confidences = [r.confidence for r in results if r.confidence > 0]
    return {
        "text": text,
        "markdown": markdown,
        "pages": len(results),
        "char_count": len(text),
        "word_count": sum(r.words for r in results),
        "mean_confidence": round(sum(confidences) / len(confidences), 1) if confidences else None,
        "per_page": [
            {"page": r.page, "chars": len(r.text), "words": r.words, "confidence": r.confidence}
            for r in results
        ],
    }
