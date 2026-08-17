"""
API d'extraction OCR — BRVM Insights.

Complète le backend PHP (brvm-api) sur le seul point où il est aveugle : les
rapports dont le PDF est un SCAN, sans couche texte. Pour ces fichiers,
l'extraction classique renvoie quelques caractères parasites ; ici on
rasterise chaque page et on la fait lire par tesseract, puis on reconstruit
un markdown à partir de la mise en page détectée.

Deux usages :
  1. « je joins un fichier » — POST /extract avec un PDF ou une image,
     réponse immédiate (fichiers courts) ou tâche de fond suivie par
     /jobs/{id} ;
  2. « re-traiter un rapport de la base » — POST /reports/{id}/extract, qui
     réécrit company_report_contents comme le ferait le pipeline PHP.

Lancement :
    ./run.sh              (voir README.md)
"""

from __future__ import annotations

import shutil
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Any

from fastapi import BackgroundTasks, FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import PlainTextResponse

from . import db, ocr

app = FastAPI(
    title="BRVM Insights — API OCR",
    description="Extraction de texte des rapports scannés et conversion en markdown",
    version="1.0.0",
)

# Le frontend et le backend PHP tournent sur d'autres ports : même politique
# permissive que les api_*.php du projet.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

STORAGE = Path(__file__).resolve().parent.parent / "storage"
STORAGE.mkdir(parents=True, exist_ok=True)

# Au-delà de ce nombre de pages, la réponse ne peut pas être synchrone : on
# bascule en tâche de fond pour ne pas laisser le client attendre plusieurs
# minutes sur une requête HTTP.
SYNC_PAGE_LIMIT = 3
ACCEPTED_SUFFIXES = {".pdf", ".png", ".jpg", ".jpeg", ".tif", ".tiff", ".bmp", ".webp"}

_jobs: dict[str, dict[str, Any]] = {}
_jobs_lock = threading.Lock()


class JobCancelled(Exception):
    """Levée depuis le callback de progression pour interrompre un OCR en cours."""


def _new_job(kind: str, label: str) -> str:
    job_id = uuid.uuid4().hex[:12]
    with _jobs_lock:
        _jobs[job_id] = {
            "id": job_id,
            "kind": kind,
            "label": label,
            "status": "en_attente",
            "progress": {"page": 0, "total": None},
            "started_at": time.time(),
            "finished_at": None,
            "result": None,
            "error": None,
            "cancel_requested": False,
        }
    return job_id


def _is_cancelled(job_id: str) -> bool:
    with _jobs_lock:
        return bool(_jobs.get(job_id, {}).get("cancel_requested"))


def _update_job(job_id: str, **fields) -> None:
    with _jobs_lock:
        if job_id in _jobs:
            _jobs[job_id].update(fields)


def _job_progress(job_id: str):
    def on_progress(page: int, total: int) -> None:
        _update_job(job_id, progress={"page": page, "total": total})

    return on_progress


def _run_ocr_job(job_id: str, pdf: Path, lang: str, dpi: int, max_pages: int | None,
                 report_id: int | None, cleanup: bool) -> None:
    _update_job(job_id, status="en_cours")
    try:
        results = ocr.ocr_pdf(
            pdf, lang=lang, dpi=dpi, max_pages=max_pages, on_progress=_job_progress(job_id)
        )
        payload = ocr.assemble(results)
        if report_id is not None:
            db.save_extraction(report_id, payload["text"], payload["markdown"])
            payload["saved_to_report_id"] = report_id
        _update_job(job_id, status="termine", result=payload, finished_at=time.time())
    except Exception as exc:
        _update_job(job_id, status="echec", error=str(exc)[:500], finished_at=time.time())
    finally:
        if cleanup:
            shutil.rmtree(pdf.parent, ignore_errors=True)


def _store_upload(upload: UploadFile) -> Path:
    suffix = Path(upload.filename or "document.pdf").suffix.lower()
    if suffix not in ACCEPTED_SUFFIXES:
        raise HTTPException(
            status_code=415,
            detail=f"Format non pris en charge : {suffix or 'inconnu'}. "
                   f"Formats acceptés : {', '.join(sorted(ACCEPTED_SUFFIXES))}",
        )
    tmpdir = Path(tempfile.mkdtemp(prefix="brvm_upload_", dir=STORAGE))
    target = tmpdir / f"document{suffix}"
    with target.open("wb") as fh:
        shutil.copyfileobj(upload.file, fh)
    if target.stat().st_size == 0:
        shutil.rmtree(tmpdir, ignore_errors=True)
        raise HTTPException(status_code=400, detail="Fichier vide")
    return target


@app.get("/health")
def health() -> dict:
    """Diagnostic complet : binaires OCR, langues installées, base de données."""
    tools = ocr.check_tools()
    return {
        "service": "brvm-ocr",
        "ready": tools["ready"],
        "tools": {
            "tesseract": tools["tesseract"],
            "pdftoppm": tools["pdftoppm"],
            "pdfinfo": tools["pdfinfo"],
        },
        "languages_installed": len(tools["languages"]),
        "french_available": "fra" in tools["languages"],
        "database": db.ping(),
        "jobs_in_memory": len(_jobs),
    }


@app.post("/extract")
async def extract(
    background: BackgroundTasks,
    file: UploadFile = File(..., description="PDF scanné ou image à lire"),
    lang: str = Form(ocr.DEFAULT_LANG),
    dpi: int = Form(ocr.DEFAULT_DPI),
    max_pages: int | None = Form(None),
    force: bool = Form(False),
) -> dict:
    """
    Point d'entrée principal : on joint un fichier, l'extraction démarre.

    Les documents courts sont traités immédiatement ; au-delà de
    SYNC_PAGE_LIMIT pages, une tâche de fond est créée et son identifiant
    renvoyé (à suivre via /jobs/{id}).

    `force=false` protège d'un OCR inutile : si le PDF possède déjà une
    couche texte suffisante, on la renvoie telle quelle en le signalant,
    plutôt que de passer plusieurs minutes à relire une image de ce que le
    fichier contenait déjà en clair.
    """
    tools = ocr.check_tools()
    if not tools["ready"]:
        raise HTTPException(status_code=503, detail="tesseract ou pdftoppm absent de ce serveur (voir /health)")

    source = _store_upload(file)
    is_pdf = source.suffix == ".pdf"

    if is_pdf and not force:
        layer = ocr.existing_text_layer(source)
        pages = ocr.pdf_page_count(source) or 1
        if len(layer.strip()) >= ocr.TEXT_LAYER_CHARS_PER_PAGE * pages:
            shutil.rmtree(source.parent, ignore_errors=True)
            return {
                "mode": "couche_texte_existante",
                "ocr_effectue": False,
                "message": "Ce PDF contient déjà du texte : l'OCR n'apporterait rien. "
                           "Relancez avec force=true pour l'imposer.",
                "text": layer,
                "char_count": len(layer),
                "pages": pages,
            }

    if not is_pdf:
        try:
            text, lines = ocr.ocr_image(source, lang=lang)
        except ocr.OcrError as exc:
            shutil.rmtree(source.parent, ignore_errors=True)
            raise HTTPException(status_code=500, detail=str(exc))
        markdown = ocr.lines_to_markdown(lines)
        shutil.rmtree(source.parent, ignore_errors=True)
        return {
            "mode": "image",
            "ocr_effectue": True,
            "text": text,
            "markdown": markdown,
            "char_count": len(text),
            "mean_confidence": round(sum(l.confidence for l in lines) / len(lines), 1) if lines else None,
        }

    pages = ocr.pdf_page_count(source) or 1
    effective = pages if max_pages is None else min(pages, max_pages)

    if effective <= SYNC_PAGE_LIMIT:
        try:
            results = ocr.ocr_pdf(source, lang=lang, dpi=dpi, max_pages=max_pages)
        except ocr.OcrError as exc:
            raise HTTPException(status_code=500, detail=str(exc))
        finally:
            shutil.rmtree(source.parent, ignore_errors=True)
        payload = ocr.assemble(results)
        payload.update({"mode": "synchrone", "ocr_effectue": True, "total_pages": pages})
        return payload

    job_id = _new_job("upload", file.filename or source.name)
    _update_job(job_id, progress={"page": 0, "total": effective})
    background.add_task(_run_ocr_job, job_id, source, lang, dpi, max_pages, None, True)
    return {
        "mode": "asynchrone",
        "job_id": job_id,
        "total_pages": pages,
        "pages_a_traiter": effective,
        "suivi": f"/jobs/{job_id}",
        "message": f"Document de {pages} pages : traitement lancé en tâche de fond.",
    }


@app.get("/jobs/{job_id}")
def job_status(job_id: str) -> dict:
    with _jobs_lock:
        job = _jobs.get(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Tâche inconnue (les tâches sont perdues au redémarrage du service)")
    out = dict(job)
    if out["finished_at"]:
        out["duree_secondes"] = round(out["finished_at"] - out["started_at"], 1)
    return out


@app.get("/jobs/{job_id}/markdown", response_class=PlainTextResponse)
def job_markdown(job_id: str) -> str:
    """Markdown seul, prêt à être enregistré en .md."""
    with _jobs_lock:
        job = _jobs.get(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Tâche inconnue")
    if job["status"] != "termine":
        raise HTTPException(status_code=409, detail=f"Tâche encore au statut « {job['status']} »")
    return job["result"]["markdown"]


@app.get("/jobs")
def jobs() -> dict:
    with _jobs_lock:
        return {
            "jobs": [
                {k: v for k, v in job.items() if k != "result"}
                for job in sorted(_jobs.values(), key=lambda j: j["started_at"], reverse=True)
            ]
        }


def _run_batch_job(job_id: str, reports: list[dict], lang: str, dpi: int,
                   max_pages: int | None, save: bool) -> None:
    """
    Traite les rapports UN PAR UN, séquentiellement.

    Le séquentiel est délibéré : tesseract sature déjà un cœur par page, et
    paralléliser sur une machine de bureau ferait surtout ramer le reste
    (MAMP, le navigateur). Chaque rapport est enregistré dès qu'il est
    terminé — un arrêt en cours de route ne perd donc jamais le travail
    déjà accompli.
    """
    _update_job(job_id, status="en_cours")
    done: list[dict] = []
    total_chars = 0

    try:
        for index, report in enumerate(reports, start=1):
            if _is_cancelled(job_id):
                break

            report_id = int(report["id"])
            title = (report.get("title") or "")[:80]
            _update_job(
                job_id,
                progress={
                    "report_index": index,
                    "report_total": len(reports),
                    "report_id": report_id,
                    "report_title": title,
                    "page": 0,
                    "total": None,
                },
            )

            def on_progress(page: int, total: int, _idx=index, _rid=report_id, _t=title) -> None:
                if _is_cancelled(job_id):
                    raise JobCancelled()
                _update_job(
                    job_id,
                    progress={
                        "report_index": _idx,
                        "report_total": len(reports),
                        "report_id": _rid,
                        "report_title": _t,
                        "page": page,
                        "total": total,
                    },
                )

            path = Path(report.get("local_path") or "")
            if not path.exists():
                done.append({"id": report_id, "title": title, "status": "fichier_absent",
                             "chars": None, "confidence": None,
                             "error": "PDF absent du disque"})
                _update_job(job_id, result={"reports": done, "processed": len(done),
                                            "total_chars": total_chars})
                continue

            try:
                results = ocr.ocr_pdf(path, lang=lang, dpi=dpi, max_pages=max_pages,
                                      on_progress=on_progress)
                payload = ocr.assemble(results)
                if save:
                    db.save_extraction(report_id, payload["text"], payload["markdown"])
                total_chars += payload["char_count"]
                done.append({
                    "id": report_id, "title": title, "status": "ok",
                    "chars": payload["char_count"], "pages": payload["pages"],
                    "confidence": payload["mean_confidence"], "error": None,
                })
            except JobCancelled:
                raise
            except Exception as exc:
                # Un rapport illisible ne doit pas interrompre le lot entier.
                done.append({"id": report_id, "title": title, "status": "echec",
                             "chars": None, "confidence": None, "error": str(exc)[:300]})

            _update_job(job_id, result={"reports": done, "processed": len(done),
                                        "total_chars": total_chars})

        cancelled = _is_cancelled(job_id)
        _update_job(
            job_id,
            status="annule" if cancelled else "termine",
            result={
                "reports": done,
                "processed": len(done),
                "requested": len(reports),
                "succeeded": sum(1 for r in done if r["status"] == "ok"),
                "failed": sum(1 for r in done if r["status"] != "ok"),
                "total_chars": total_chars,
                "saved": save,
            },
            finished_at=time.time(),
        )
    except JobCancelled:
        _update_job(
            job_id,
            status="annule",
            result={
                "reports": done,
                "processed": len(done),
                "requested": len(reports),
                "succeeded": sum(1 for r in done if r["status"] == "ok"),
                "failed": sum(1 for r in done if r["status"] != "ok"),
                "total_chars": total_chars,
                "saved": save,
            },
            finished_at=time.time(),
        )
    except Exception as exc:
        _update_job(job_id, status="echec", error=str(exc)[:500], finished_at=time.time())


@app.post("/reports/extract-batch")
def extract_batch(background: BackgroundTasks,
                  max_chars: int = Form(500), limit: int = Form(50),
                  lang: str = Form(ocr.DEFAULT_LANG), dpi: int = Form(ocr.DEFAULT_DPI),
                  max_pages: int | None = Form(None), save: bool = Form(True)) -> dict:
    """
    Traitement par lot : reprend tous les rapports en attente et les OCRise
    les uns à la suite des autres, en tâche de fond.

    `limit` borne volontairement le lot : avec plusieurs centaines de
    rapports à quelques secondes par page, un « tout traiter » sans limite
    tournerait des heures. Relancer le lot reprend simplement là où il en
    est, puisque les rapports traités ne ressortent plus des candidats.
    """
    tools = ocr.check_tools()
    if not tools["ready"]:
        raise HTTPException(status_code=503, detail="tesseract ou pdftoppm absent de ce serveur (voir /health)")

    try:
        reports = db.list_candidates(max_chars=max_chars, limit=max(1, min(500, limit)))
    except Exception as exc:
        raise HTTPException(status_code=503, detail=str(exc)[:300])

    reports = [r for r in reports if r.get("local_path") and Path(r["local_path"]).exists()]
    if not reports:
        return {"mode": "rien_a_faire", "count": 0,
                "message": "Aucun rapport à traiter : soit tout est déjà extrait, soit les PDF sont absents du disque."}

    job_id = _new_job("batch", f"{len(reports)} rapport(s) en attente")
    _update_job(job_id, progress={"report_index": 0, "report_total": len(reports),
                                  "report_id": None, "report_title": None,
                                  "page": 0, "total": None})
    background.add_task(_run_batch_job, job_id, reports, lang, dpi, max_pages, save)
    return {
        "mode": "asynchrone",
        "job_id": job_id,
        "count": len(reports),
        "suivi": f"/jobs/{job_id}",
        "message": f"{len(reports)} rapport(s) seront traités l'un après l'autre.",
    }


@app.post("/jobs/{job_id}/cancel")
def cancel_job(job_id: str) -> dict:
    """
    Demande l'arrêt d'une tâche. L'arrêt est pris en compte entre deux pages
    ou entre deux rapports : ce qui a déjà été extrait et enregistré est
    conservé.
    """
    with _jobs_lock:
        job = _jobs.get(job_id)
        if not job:
            raise HTTPException(status_code=404, detail="Tâche inconnue")
        if job["status"] in ("termine", "echec", "annule"):
            return {"job_id": job_id, "status": job["status"], "message": "Tâche déjà terminée."}
        job["cancel_requested"] = True
    return {"job_id": job_id, "status": "annulation_demandee",
            "message": "Arrêt demandé : la tâche s'arrêtera après la page en cours."}


@app.get("/reports/candidates")
def report_candidates(max_chars: int = 500, limit: int = 100) -> dict:
    """
    Rapports de la base qui méritent un OCR : aucun texte extrait, ou texte
    anormalement court — la signature d'un PDF scanné.
    """
    try:
        rows = db.list_candidates(max_chars=max_chars, limit=limit)
    except Exception as exc:
        raise HTTPException(status_code=503, detail=str(exc)[:300])
    for row in rows:
        path = row.get("local_path")
        row["fichier_present"] = bool(path and Path(path).exists())
    return {"count": len(rows), "max_chars": max_chars, "reports": rows}


@app.post("/reports/{report_id}/extract")
def extract_report(report_id: int, background: BackgroundTasks,
                   lang: str = Form(ocr.DEFAULT_LANG), dpi: int = Form(ocr.DEFAULT_DPI),
                   max_pages: int | None = Form(None), save: bool = Form(True)) -> dict:
    """
    OCR d'un rapport déjà présent en base, à partir de son PDF local. Le
    résultat est réécrit dans company_report_contents (sauf save=false), ce
    qui le rend immédiatement visible dans les écrans existants.
    """
    try:
        report = db.get_report(report_id)
    except Exception as exc:
        raise HTTPException(status_code=503, detail=str(exc)[:300])
    if not report:
        raise HTTPException(status_code=404, detail=f"Rapport {report_id} introuvable")

    local = report.get("local_path")
    if not local or not Path(local).exists():
        raise HTTPException(
            status_code=404,
            detail=f"PDF absent du disque pour le rapport {report_id} : {local or 'aucun chemin enregistré'}",
        )

    pdf = Path(local)
    pages = ocr.pdf_page_count(pdf) or 1
    effective = pages if max_pages is None else min(pages, max_pages)

    if effective <= SYNC_PAGE_LIMIT:
        try:
            results = ocr.ocr_pdf(pdf, lang=lang, dpi=dpi, max_pages=max_pages)
        except ocr.OcrError as exc:
            raise HTTPException(status_code=500, detail=str(exc))
        payload = ocr.assemble(results)
        if save:
            db.save_extraction(report_id, payload["text"], payload["markdown"])
            payload["saved_to_report_id"] = report_id
        payload.update({"mode": "synchrone", "report": report, "total_pages": pages})
        return payload

    job_id = _new_job("report", f"#{report_id} — {report.get('title') or ''}")
    _update_job(job_id, progress={"page": 0, "total": effective})
    background.add_task(
        _run_ocr_job, job_id, pdf, lang, dpi, max_pages, report_id if save else None, False
    )
    return {
        "mode": "asynchrone",
        "job_id": job_id,
        "report_id": report_id,
        "total_pages": pages,
        "suivi": f"/jobs/{job_id}",
    }
