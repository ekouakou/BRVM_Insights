# API OCR — BRVM Insights

Second backend, en Python, dédié à un seul problème que le backend PHP ne
peut pas résoudre : les rapports d'entreprise dont le PDF est un **scan**
(une image de page, sans couche texte). Pour ces fichiers, l'extraction
classique renvoie quelques caractères parasites — le rapport 39 (BICI CI,
1er trimestre 2022) donnait 38 caractères : `,\n\n\n Classification :
Internal` — alors que la page contient un rapport financier complet.

Après OCR : **1 880 caractères**, bilan et compte de résultat lisibles,
accents corrects, confiance moyenne 91,6 %.

## Ce que fait le service

1. Rasterise chaque page du PDF en image 300 dpi (`pdftoppm`, poppler).
2. La fait lire par `tesseract` en français, en récupérant **la mise en page**
   (position et hauteur de chaque mot) et pas seulement le texte.
3. Reconstruit un **markdown** à partir de cette mise en page : les lignes
   nettement plus hautes que la moyenne deviennent des titres, celles qui
   présentent plusieurs grands espaces horizontaux deviennent des lignes de
   tableau, le reste devient du texte.

Aucune IA n'intervient : le markdown ne contient que ce que l'OCR a
réellement lu. Pour une restructuration plus fine, le pipeline IA existant
du backend PHP (`ReportMarkdownFormatterService`) peut ensuite reprendre ce
texte comme n'importe quel autre.

## Prérequis

Déjà présents sur cette machine :

```bash
tesseract --version     # 5.5.3, 163 langues dont fra
pdftoppm -v             # poppler
```

À réinstaller ailleurs : `brew install tesseract tesseract-lang poppler`
(ou `apt install tesseract-ocr tesseract-ocr-fra poppler-utils`).

## Démarrer

```bash
cd Backend/ocr-service
./run.sh
```

Le premier lancement crée l'environnement Python et installe les
dépendances ; les suivants démarrent directement. Service sur
**http://127.0.0.1:8077**, documentation interactive sur **/docs**.

Le port se change avec `PORT=9000 ./run.sh`.

## Les routes

### `POST /extract` — joindre un fichier, l'extraction démarre

Le cas d'usage principal. Accepte un PDF ou une image
(`.png .jpg .tif .bmp .webp`).

```bash
curl -X POST http://127.0.0.1:8077/extract \
     -F "file=@rapport_scanne.pdf"
```

Paramètres facultatifs : `lang` (défaut `fra`), `dpi` (défaut 300),
`max_pages`, `force`.

Réponse **synchrone** jusqu'à 3 pages : texte, markdown, nombre de
caractères, confiance moyenne. Au-delà, réponse **asynchrone** avec un
`job_id` à suivre (voir `/jobs`).

**Garde-fou `force`** : si le PDF possède déjà une couche texte suffisante,
le service la renvoie telle quelle en le signalant, plutôt que de passer
plusieurs minutes à relire en image ce que le fichier contenait en clair.
`force=true` impose l'OCR malgré tout.

### `GET /jobs/{id}` — suivre une extraction longue

Renvoie le statut (`en_attente`, `en_cours`, `termine`, `echec`), la
progression page par page, la durée et le résultat.

`GET /jobs/{id}/markdown` renvoie le markdown seul, en texte brut, prêt à
être enregistré en `.md` :

```bash
curl http://127.0.0.1:8077/jobs/0f1b478cab38/markdown > rapport.md
```

`GET /jobs` liste les tâches. Elles vivent en mémoire : un redémarrage du
service les efface (le texte déjà écrit en base, lui, est conservé).

### `GET /reports/candidates` — quels rapports méritent un OCR

Liste les rapports de la base sans texte extrait, ou dont le texte est
anormalement court (`max_chars`, défaut 500) — la signature d'un PDF
scanné. Indique pour chacun si le fichier est présent sur le disque.

### `POST /reports/extract-batch` — tout traiter, l'un après l'autre

Reprend les rapports en attente et les OCRise **séquentiellement**, en tâche
de fond. Paramètres : `limit` (nombre de rapports du lot, 50 par défaut,
500 maximum), `max_chars`, `lang`, `dpi`, `max_pages`, `save`.

```bash
curl -X POST http://127.0.0.1:8077/reports/extract-batch \
     -F "limit=20" -F "max_pages=5"
```

Le séquentiel est délibéré : tesseract sature déjà un cœur par page, et
paralléliser sur une machine de bureau ferait surtout ramer MAMP et le
navigateur. Chaque rapport est **enregistré dès qu'il est terminé** — un
arrêt en cours de route ne perd jamais le travail déjà fait, et relancer le
lot reprend là où il s'était interrompu (les rapports traités ne
ressortent plus des candidats).

Un rapport illisible n'interrompt pas le lot : l'erreur est consignée dans
le bilan et le traitement passe au suivant.

Le bilan renvoyé par `/jobs/{id}` se remplit **au fur et à mesure** :
`processed`, `succeeded`, `failed`, `total_chars`, et le détail par rapport.

### `POST /jobs/{id}/cancel` — arrêter une tâche

L'arrêt est pris en compte entre deux pages ou entre deux rapports. Le
statut passe alors à `annule` et tout ce qui a été extrait est conservé.

### `POST /reports/{id}/extract` — re-traiter un rapport de la base

OCR d'un rapport déjà enregistré, à partir de son PDF local. Le résultat
est écrit dans `company_report_contents` exactement comme le ferait le
pipeline PHP : `extracted_text`, `char_count`, `formatted_markdown`, et
`company_reports.extraction_method` passe à `ocr`. Les écrans existants
(consultation, analyse IA) en profitent sans aucune modification.

`save=false` permet de prévisualiser sans rien écrire.

### `GET /health` — diagnostic

Binaires trouvés, nombre de langues installées, disponibilité du français,
connexion à la base et nombre de rapports.

## Base de données

Les identifiants sont lus dans `Backend/brvm-api/.env` : aucune
configuration à maintenir en double, aucun mot de passe dans ce dossier.
Le connecteur MySQL est facultatif — sans lui, le mode « fichier joint »
fonctionne, seules les routes `/reports/*` deviennent indisponibles et le
disent clairement.

## Volumétrie constatée (17/08/2026)

Sur 2 056 rapports en base : **355 sans aucune extraction** et **12 avec un
texte anormalement court**. Ce sont les candidats de `/reports/candidates`.

## Performances mesurées

Rapport de 53 pages, 5 pages traitées : **15 secondes**, 10 661 caractères,
confiance moyenne 91,9 %. Soit environ **3 secondes par page** à 300 dpi.
Traitement par lot : 4 rapports (2 pages chacun) en **22 secondes**,
13 710 caractères, aucun échec.
Une page est rasterisée puis supprimée aussitôt lue — un rapport de 80 pages
représenterait sinon plusieurs gigaoctets d'images simultanées sur le
disque.

## Limites assumées

- La qualité dépend entièrement du scan : un document penché, taché ou en
  basse résolution donnera un texte dégradé. La **confiance moyenne** est
  renvoyée à chaque extraction pour le détecter (en dessous de 70 %, le
  texte mérite une relecture).
- La détection de tableaux repose sur les espaces entre colonnes. Elle
  fonctionne bien sur des tableaux financiers nets, moins bien sur des
  mises en page complexes ou des cellules fusionnées.
- L'OCR peut confondre certains caractères (`Le`/`La`, `Il`/`II`) ; les
  chiffres sont en revanche très fiables sur les documents testés.
- Les tâches sont en mémoire : pour un traitement massif et reprenable, il
  faudrait les persister en base.
