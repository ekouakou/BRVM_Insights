"""
Accès à la base MySQL de BRVM Insights, en lecture des rapports et en
écriture du texte extrait — les mêmes tables que le backend PHP
(company_reports / company_report_contents).

Le connecteur mysql-connector-python est OPTIONNEL : sans lui, le service
fonctionne parfaitement en mode « fichier joint » (on envoie un PDF, on
récupère texte et markdown). Seules les routes qui lisent ou réécrivent un
rapport de la base sont alors indisponibles, et elles le disent clairement
plutôt que d'échouer avec une erreur d'import.
"""

from __future__ import annotations

import os
from pathlib import Path

try:
    import mysql.connector  # type: ignore

    DRIVER_AVAILABLE = True
    DRIVER_ERROR = None
except Exception as exc:  # pragma: no cover - dépend de l'installation
    DRIVER_AVAILABLE = False
    DRIVER_ERROR = str(exc)


def _env_file_values() -> dict:
    """
    Lit le .env du backend PHP pour partager exactement les mêmes
    identifiants : pas de configuration à maintenir en double, et aucun mot
    de passe en dur dans ce dépôt.
    """
    values: dict[str, str] = {}
    env_path = Path(__file__).resolve().parents[2] / "brvm-api" / ".env"
    if not env_path.exists():
        return values
    for raw in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def config() -> dict:
    env = _env_file_values()

    def pick(name: str, default: str) -> str:
        return os.environ.get(name) or env.get(name) or default

    return {
        "host": pick("DB_HOST", "localhost"),
        "port": int(pick("DB_PORT", "3306")),
        "database": pick("DB_NAME", "brvm_trading_app"),
        "user": pick("DB_USER", "root"),
        "password": pick("DB_PASSWORD", "root"),
    }


def connect():
    if not DRIVER_AVAILABLE:
        raise RuntimeError(
            "Le connecteur MySQL n'est pas installé : pip install mysql-connector-python. "
            f"Détail : {DRIVER_ERROR}"
        )
    cfg = config()
    return mysql.connector.connect(charset="utf8mb4", use_unicode=True, **cfg)


def ping() -> dict:
    """Diagnostic de connexion, exposé par /health."""
    if not DRIVER_AVAILABLE:
        return {"available": False, "reason": "mysql-connector-python non installé"}
    try:
        conn = connect()
        cur = conn.cursor()
        cur.execute("SELECT COUNT(*) FROM company_reports")
        (count,) = cur.fetchone()
        cur.close()
        conn.close()
        return {"available": True, "reports": int(count), "database": config()["database"]}
    except Exception as exc:
        return {"available": False, "reason": str(exc)[:300]}


def get_report(report_id: int) -> dict | None:
    conn = connect()
    cur = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT r.id, r.title, r.local_path, r.file_url, r.text_extracted,
               r.extraction_method, c.char_count, c.markdown_status
        FROM company_reports r
        LEFT JOIN company_report_contents c ON c.report_id = r.id
        WHERE r.id = %s
        """,
        (report_id,),
    )
    row = cur.fetchone()
    cur.close()
    conn.close()
    return row


def list_candidates(max_chars: int = 500, limit: int = 100) -> list[dict]:
    """
    Rapports à re-traiter en OCR : ceux dont l'extraction classique n'a rien
    donné (aucun contenu) ou presque rien (texte anormalement court, signe
    d'un PDF scanné). Le fichier doit être présent sur le disque.
    """
    conn = connect()
    cur = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT r.id, r.title, r.local_path, c.char_count
        FROM company_reports r
        LEFT JOIN company_report_contents c ON c.report_id = r.id
        WHERE r.local_path IS NOT NULL
          AND (c.report_id IS NULL OR c.char_count IS NULL OR c.char_count < %s)
        ORDER BY c.char_count IS NULL DESC, c.char_count ASC, r.id
        LIMIT %s
        """,
        (max_chars, limit),
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()
    return rows


def save_extraction(report_id: int, text: str, markdown: str | None) -> None:
    """
    Écrit le résultat OCR dans les mêmes colonnes que le pipeline PHP, pour
    que les écrans existants (consultation, analyse IA) en profitent sans
    aucune modification. `extraction_method` passe à 'ocr' : c'est la seule
    trace qui distingue ensuite un texte lu par OCR d'un texte natif.
    """
    conn = connect()
    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO company_report_contents (report_id, extracted_text, char_count, formatted_markdown,
                                             markdown_status, markdown_provider, markdown_model)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            extracted_text = VALUES(extracted_text),
            char_count = VALUES(char_count),
            formatted_markdown = COALESCE(VALUES(formatted_markdown), formatted_markdown),
            markdown_status = VALUES(markdown_status),
            markdown_provider = VALUES(markdown_provider),
            markdown_model = VALUES(markdown_model)
        """,
        (
            report_id,
            text,
            len(text),
            markdown,
            "success" if markdown else None,
            "ocr" if markdown else None,
            "tesseract" if markdown else None,
        ),
    )
    cur.execute(
        """
        UPDATE company_reports
        SET text_extracted = 1, extraction_method = 'ocr', extraction_error = NULL
        WHERE id = %s
        """,
        (report_id,),
    )
    conn.commit()
    cur.close()
    conn.close()
