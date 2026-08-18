import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { callApi, callApiUpload, statementTemplateUrl } from '../lib/apiClient'
import type { Company } from '../lib/types'
import {
  Button,
  Card,
  ErrorState,
  InfoPanel,
  Input,
  LoadingState,
  Modal,
  SearchableSelect,
  Select,
  StatTile,
  Tabs,
} from '../components/ui'
import { IconButton, TrashIcon } from '../components/icons'

/**
 * États financiers saisis à la main, TOUS FORMATS (voir
 * api_financial_statements.php et class/FinancialStatementSchemas.php).
 *
 * Les émetteurs BRVM publient des documents de structures très différentes —
 * compte de résultat commercial, compte de résultat bancaire, bilan, flux de
 * trésorerie, tableau d'activité trimestriel — avec des conventions de signe
 * opposées. L'écran les compartimente par format : mélanger un bilan et un
 * compte de résultat dans une même liste les rendrait illisibles.
 */

interface TypeSummary {
  key: string
  label: string
  description: string
  sign_convention: string
  lines_count: number
  subtotals_count: number
}

interface SchemaLine { key: string; label: string; sign: string }
interface SchemaGroup { key: string; label: string; lines: SchemaLine[] }
interface Schema {
  key: string
  label: string
  description: string
  sign_convention: string
  sign_note: string
  headline: string[]
  groups: SchemaGroup[]
  subtotals: { key: string; label: string; formula: Record<string, number> }[]
}

interface Ratios {
  /** Renseignés uniquement pour le format « Dividendes versés ». */
  dividend_per_share: number | null
  dividend_per_share_net: number | null
  dividend_price: number | null
  total_paid: number | null
  total_paid_estimated: boolean
  yield_percent: number | null
  payout_percent: number | null
  payout_exceeds_profit: boolean
  revenue_base: number | null
  revenue_label: string
  net_income: number | null
  shares_outstanding: number | null
  price: number | null
  price_date: string | null
  eps: number | null
  book_value_per_share: number | null
  per: number | null
  pbr: number | null
  /** PER officiel BRVM (bulletin le plus proche de la clôture d'exercice) — calculé avec le nombre d'actions réel à cette date, contrairement à `per`. */
  per_brvm: number | null
  yield_net_brvm_percent: number | null
  per_brvm_date: string | null
  market_cap: number | null
  marge_nette_percent: number | null
  marge_exploitation_percent: number | null
  roe_percent: number | null
  roa_percent: number | null
  not_computable_reasons: string[]
}

interface Statement {
  id: number
  company_id: number
  statement_type: string
  statement_label: string
  period_end_date: string
  period_type: string
  fiscal_year: number
  unit_multiplier: number
  is_active: boolean
  deactivated_reason: string | null
  source_note: string | null
  raw_values: Record<string, number>
  values: Record<string, number>
  subtotals: Record<string, number>
  subtotal_labels: Record<string, string>
  headline: string[]
  ratios: Ratios
  lines_filled: number
  growth?: Record<string, number | null>
}

interface TypeGroup {
  type: string
  label: string
  description: string
  count: number
  active_count: number
  statements: Statement[]
}

const nf = new Intl.NumberFormat('fr-FR')
const fmtF = (v: number | null | undefined) => (v === null || v === undefined ? '—' : nf.format(Math.round(v)))
const fmtPct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v.toFixed(2)}%`)

function fmtCompact(v: number): string {
  const abs = Math.abs(v)
  if (abs >= 1e9) return `${(v / 1e9).toFixed(1)} Md`
  if (abs >= 1e6) return `${(v / 1e6).toFixed(0)} M`
  if (abs >= 1e3) return `${(v / 1e3).toFixed(0)} k`
  return String(Math.round(v))
}

/** Rappel de la convention de signe propre au format en cours de saisie. */
function SignBanner({ schema }: { schema: Schema }) {
  return (
    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
      <strong>Convention de signe de ce format.</strong> {schema.sign_note} Choisissez aussi la bonne{' '}
      <strong>unité</strong> : un document « en millions » saisi en francs donnerait un résultat un million de fois
      trop petit.
    </div>
  )
}

interface ImportRow {
  source_row: number
  label: string
  values: Record<string, number | null>
  kind: 'line' | 'subtotal'
  matched_key: string | null
  matched_label: string | null
  matched_group: string | null
  subtotal_key: string | null
  subtotal_label: string | null
  score: number
  confidence: 'certaine' | 'probable' | 'a_verifier' | 'aucune' | 'sous_total'
}

interface UniversalStatement {
  column?: number
  source_row?: number
  column_label?: string
  statement_type: string
  statement_label?: string
  period_end_date?: string
  period_type?: string
  unit_multiplier?: number
  values?: Record<string, number>
  document_subtotals?: Record<string, number>
  lines_count?: number
  subtotals_count?: number
  unknown_labels?: string[]
  error?: string | null
}

interface UniversalPreview {
  /** Libellé renvoyé par le serveur ; la détection se fait sur la FORME, pas sur ce nom. */
  mode?: string
  statements: UniversalStatement[]
  ready: number
  file: { name: string; format: string; sheet: string | null; rows_read: number }
  note: string
}

interface BatchResult {
  saved: number
  failed: number
  results: {
    label: string
    success: boolean
    lines_filled?: number
    error?: string
    verification?: { all_match: boolean; message: string } | null
  }[]
}

interface ImportPreview {
  statement_type: string
  statement_label: string
  file: { name: string; format: string; sheet: string | null; rows_read: number }
  columns: { index: number; header: string; filled: number }[]
  rows: ImportRow[]
  matched: number
  unmatched: number
  subtotal_rows: number
  duplicates: string[]
  available_lines: { key: string; label: string; group: string }[]
  sign_note: string
  note: string
}

const CONFIDENCE_STYLES: Record<string, { label: string; cls: string }> = {
  certaine: { label: 'certaine', cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
  probable: { label: 'probable', cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' },
  a_verifier: { label: 'à vérifier', cls: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
  aucune: { label: 'non reconnu', cls: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' },
  sous_total: { label: 'sous-total', cls: 'bg-gray-200 text-gray-600 dark:bg-gray-800 dark:text-gray-300' },
}

/**
 * Import d'un fichier Excel ou CSV. Le MÊME fichier convient à tous les
 * formats : c'est l'onglet actif qui détermine les postes cibles, et les
 * libellés du document y sont rapprochés automatiquement.
 *
 * Rien n'est enregistré avant validation : l'écran montre le rapprochement
 * proposé ligne par ligne, avec son degré de confiance, et permet de le
 * corriger. Un libellé mal reconnu deviendrait sinon une fausse donnée
 * impossible à repérer ensuite.
 */
function ImportModal({
  schema,
  companyId,
  onClose,
  onSaved,
}: {
  schema: Schema
  companyId: number
  onClose: () => void
  onSaved: () => void
}) {
  const [file, setFile] = useState<File | null>(null)
  // Paramètres du modèle à télécharger : périmètre (ce format seul ou les
  // six) et nombre d'états — dix colonnes pour dix exercices.
  const [templateRows, setTemplateRows] = useState(5)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [universal, setUniversal] = useState<UniversalPreview | null>(null)
  const [batch, setBatch] = useState<BatchResult | null>(null)
  const [column, setColumn] = useState<number | null>(null)
  const [overrides, setOverrides] = useState<Record<number, string>>({})
  const [periodEnd, setPeriodEnd] = useState('')
  const [periodType, setPeriodType] = useState('annuel')
  const [unit, setUnit] = useState('1')
  const [verification, setVerification] = useState<
    { checks: { label: string; document: number; computed: number; matches: boolean }[]; all_match: boolean; message: string } | null
  >(null)

  const previewMutation = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error('Aucun fichier sélectionné')
      const result = await callApiUpload<ImportPreview | UniversalPreview>(
        'api_financial_statements.php',
        'import_preview',
        file,
        { statement_type: schema.key },
      )
      // Le serveur renvoie DEUX formes très différentes selon le fichier
      // déposé. On refuse ici tout ce qui n'est ni l'une ni l'autre :
      // laisser passer une réponse incomplète poserait le composant sur des
      // tableaux inexistants et viderait l'écran au rendu suivant.
      const estTabulaire = Array.isArray((result as UniversalPreview).statements)
      const estRapprochement =
        Array.isArray((result as ImportPreview).rows) && Array.isArray((result as ImportPreview).columns)
      if (!estTabulaire && !estRapprochement) {
        throw new Error(
          "Réponse d'aperçu inattendue : ni modèle tabulaire, ni rapprochement ligne à ligne. " +
            'Le backend est probablement dans une version différente de celle de cet écran.',
        )
      }
      return result
    },
    onSuccess: (result) => {
      setVerification(null)
      setBatch(null)
      // Le modèle tabulaire porte lui-même le format de chaque ligne : le
      // serveur renvoie alors une liste d'états, pas un rapprochement. On le
      // reconnaît à la présence de `statements` et NON au libellé de `mode` :
      // les deux extrémités ont déjà divergé sur ce nom (« tableau » côté
      // serveur, « modele_unique » côté écran), ce qui vidait la fenêtre.
      if (Array.isArray((result as UniversalPreview).statements)) {
        setUniversal(result as UniversalPreview)
        setPreview(null)
        setColumn(null)
        return
      }
      setUniversal(null)
      const p = result as ImportPreview
      setPreview(p)
      // Par défaut la DERNIÈRE colonne : dans un document N / N-1, c'est
      // l'exercice le plus récent, celui qu'on veut presque toujours.
      setColumn(p.columns.length > 0 ? p.columns[p.columns.length - 1].index : null)
    },
  })

  const batchMutation = useMutation({
    mutationFn: () => {
      const ready = (universal?.statements ?? []).filter((st) => !st.error)
      if (ready.length === 0) throw new Error('Aucun état prêt à importer')
      return callApi<BatchResult>('api_financial_statements.php', 'save_many', {
        company_id: companyId,
        statements: ready.map((st) => ({ ...st, source_note: `Import ${universal?.file.name ?? ''}` })),
      })
    },
    onSuccess: (r) => {
      setBatch(r)
      onSaved()
    },
  })

  /** Clé retenue pour une ligne : correction manuelle si elle existe, sinon proposition. */
  const keyFor = (row: ImportRow, index: number): string =>
    overrides[index] !== undefined ? overrides[index] : (row.matched_key ?? '')

  const saveMutation = useMutation({
    mutationFn: () => {
      if (!preview || column === null) throw new Error('Aucune colonne sélectionnée')
      const values: Record<string, number> = {}
      const documentSubtotals: Record<string, number> = {}
      preview.rows.forEach((row, index) => {
        const value = row.values[String(column)] ?? row.values[column]
        if (value === null || value === undefined) return
        if (row.kind === 'subtotal' && row.subtotal_key) {
          documentSubtotals[row.subtotal_key] = value
          return
        }
        const key = keyFor(row, index)
        if (key) values[key] = value
      })
      return callApi<{ subtotal_verification: typeof verification }>('api_financial_statements.php', 'save', {
        company_id: companyId,
        statement_type: schema.key,
        period_end_date: periodEnd,
        period_type: periodType,
        unit_multiplier: Number(unit),
        source_note: `Import ${preview.file.name}`,
        values,
        document_subtotals: documentSubtotals,
      })
    },
    onSuccess: (result) => {
      onSaved()
      // On garde la fenêtre ouverte pour montrer la vérification : c'est
      // l'information la plus utile de tout l'import.
      setVerification(result.subtotal_verification ?? null)
    },
  })

  const selectedCount = preview
    ? preview.rows.filter((r, i) => r.kind === 'line' && keyFor(r, i) !== '' &&
        (r.values[String(column ?? '')] ?? r.values[column ?? -1]) !== null).length
    : 0
  const canSave = /^\d{4}-\d{2}-\d{2}$/.test(periodEnd) && column !== null && selectedCount > 0

  return (
    <Modal title={`Importer un fichier — ${schema.label}`} onClose={onClose}>
      <div className="flex flex-col gap-4">
        <InfoPanel title="Comment ça marche">
          <p>
            Déposez le <strong>même fichier</strong> quel que soit l'onglet : un tableur avec une colonne de
            libellés et une ou plusieurs colonnes de montants. L'application rapproche automatiquement chaque
            libellé des postes du format <strong>{schema.label}</strong>, et vous montre le résultat avant tout
            enregistrement.
          </p>
          <p>
            Les <strong>sous-totaux</strong> présents dans votre document (chiffre d'affaires, résultat net…) ne
            sont pas importés — ils sont recalculés — mais ils servent à <strong>vérifier</strong> l'import : si le
            calcul les retrouve, c'est qu'aucun poste n'a été oublié ni mal rapproché.
          </p>
          <p>
            <strong>Le modèle unique</strong> ci-dessous couvre les <strong>six formats</strong> dans un seul
            fichier, et permet d'importer <strong>plusieurs états d'un coup</strong> : chaque colonne de montants
            devient un état, décrit par les lignes @format, @date, @periode et @unite en tête. Vous pouvez ainsi
            déposer en une fois le compte de résultat, le bilan et les dividendes d'une entreprise, ou deux
            exercices du même document.
          </p>
        </InfoPanel>

        <div className="flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
          <label className="w-52">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
              Lignes vierges à préparer
            </span>
            <Select value={String(templateRows)} onChange={(e) => setTemplateRows(Number(e.target.value))}>
              <option value="1">1 état</option>
              <option value="2">2 (exercice N et N-1)</option>
              <option value="5">5 exercices</option>
              <option value="10">10 exercices</option>
              <option value="20">20 exercices</option>
            </Select>
          </label>
          <Button
            variant="secondary"
            onClick={() => window.open(statementTemplateUrl(schema.key, templateRows), '_blank', 'noopener,noreferrer')}
          >
            ⭱ Modèle vierge
          </Button>
          {/* Départ depuis l'existant : les chiffres déjà extraits des
              rapports par l'IA remplissent le fichier, qu'il reste à
              vérifier avant de le réimporter. */}
          <Button
            variant="secondary"
            onClick={() =>
              window.open(statementTemplateUrl(null, templateRows, { companyId }), '_blank', 'noopener,noreferrer')
            }
          >
            ⭱ Modèle pré-rempli depuis les rapports
          </Button>
          <p className="w-full text-xs text-gray-500 dark:text-gray-400">
            Le modèle est un tableau classique : <strong>une ligne = un état financier</strong>, les champs en
            colonnes. Pour dix ans de bilans, vous remplissez dix lignes. La première ligne porte les noms
            techniques et ne doit pas être modifiée ; la deuxième rappelle les libellés lisibles et est ignorée à
            l'import. Les colonnes <code>controle_*</code> servent à recopier les totaux de votre document : ils ne
            sont pas importés, ils vérifient le calcul.
          </p>
          <p className="w-full text-xs text-gray-500 dark:text-gray-400">
            <strong>Le modèle pré-rempli</strong> reprend les chiffres déjà extraits par l'IA de tous les rapports
            analysés de cette entreprise — une ligne par rapport. C'est un point de départ, pas une vérité :
            l'extraction se trompe régulièrement d'unité, et les dates proposées sont celles de <em>publication</em>
            et non de clôture. Vérifiez dans Excel, corrigez, puis redéposez le fichier ici.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <input
            type="file"
            accept=".csv,.xlsx,.xlsm,.txt,.tsv"
            onChange={(e) => {
              setFile(e.target.files?.[0] ?? null)
              setPreview(null)
              setUniversal(null)
              setBatch(null)
              setVerification(null)
            }}
            className="text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white dark:text-gray-300 dark:file:bg-gray-100 dark:file:text-gray-900"
          />
          <Button disabled={!file || previewMutation.isPending} onClick={() => previewMutation.mutate()}>
            {previewMutation.isPending ? 'Analyse…' : 'Analyser le fichier'}
          </Button>
        </div>
        {previewMutation.isError && <ErrorState message={(previewMutation.error as Error).message} />}

        {universal && (
          <>
            <div className="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
              <strong>Modèle tabulaire reconnu.</strong> {universal.note} Chaque ligne remplie devient un état, avec
              son propre format, sa date et son unité — vous pouvez donc importer dix exercices, ou plusieurs
              formats, en un seul dépôt.
            </div>

            <div className="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <th className="p-2">Ligne</th>
                    <th className="p-2">Format</th>
                    <th className="p-2">Clôture</th>
                    <th className="p-2">Période</th>
                    <th className="p-2 text-right">Unité</th>
                    <th className="p-2 text-right">Postes</th>
                    <th className="p-2">État</th>
                  </tr>
                </thead>
                <tbody>
                  {universal.statements.map((st) => (
                    <tr key={st.source_row ?? st.column} className={`border-t border-gray-100 dark:border-gray-800 ${st.error ? 'opacity-70' : ''}`}>
                      <td className="p-2 whitespace-nowrap">{st.column_label ?? `Ligne ${st.source_row ?? '?'}`}</td>
                      <td className="p-2 max-w-xs">{st.statement_label ?? st.statement_type ?? '—'}</td>
                      <td className="p-2 whitespace-nowrap tabular-nums">{st.period_end_date || '—'}</td>
                      <td className="p-2">{st.period_type ?? '—'}</td>
                      <td className="p-2 text-right tabular-nums">
                        {st.unit_multiplier === 1000000 ? 'millions' : st.unit_multiplier === 1000 ? 'milliers' : 'francs'}
                      </td>
                      <td className="p-2 text-right tabular-nums">
                        {st.lines_count ?? 0}
                        {(st.subtotals_count ?? 0) > 0 && (
                          <span className="ml-1 text-xs text-gray-400">+{st.subtotals_count} contrôle</span>
                        )}
                      </td>
                      <td className="p-2 text-xs">
                        {st.error ? (
                          <span className="text-red-600 dark:text-red-400">{st.error}</span>
                        ) : (
                          <span className="text-emerald-600 dark:text-emerald-400">prêt</span>
                        )}
                        {(st.unknown_labels?.length ?? 0) > 0 && (
                          <span className="ml-1 text-amber-600 dark:text-amber-400">
                            · {st.unknown_labels?.length} libellé(s) ignoré(s)
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {batch && (
              <div
                className={`rounded-md border px-3 py-2 text-sm ${
                  batch.failed === 0
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300'
                    : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300'
                }`}
              >
                <strong>{batch.saved} état(s) enregistré(s)</strong>
                {batch.failed > 0 && `, ${batch.failed} en échec`}.
                <ul className="mt-1 list-disc pl-4 text-xs">
                  {batch.results.map((r, i) => (
                    <li key={i}>
                      {r.success ? '✓' : '✗'} {r.label}
                      {r.success && ` — ${r.lines_filled} poste(s)`}
                      {r.verification && (r.verification.all_match
                        ? ' · sous-totaux vérifiés'
                        : ' · écart sur les sous-totaux, à contrôler')}
                      {r.error && ` — ${r.error}`}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {batchMutation.isError && <ErrorState message={(batchMutation.error as Error).message} />}

            <div className="flex items-center gap-2">
              <Button disabled={universal.ready === 0 || batchMutation.isPending} onClick={() => batchMutation.mutate()}>
                {batchMutation.isPending ? 'Import en cours…' : `Importer les ${universal.ready} état(s)`}
              </Button>
              <Button variant="secondary" onClick={onClose}>{batch ? 'Fermer' : 'Annuler'}</Button>
            </div>
          </>
        )}

        {preview && (
          <>
            <div className="flex flex-wrap items-center gap-3 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs dark:border-gray-800 dark:bg-gray-900">
              <span>
                <strong>{preview.file.name}</strong> ({preview.file.format}
                {preview.file.sheet ? ` · feuille « ${preview.file.sheet} »` : ''}, {preview.file.rows_read} lignes)
              </span>
              <span className="text-emerald-600 dark:text-emerald-400">{preview.matched} poste(s) reconnu(s)</span>
              {preview.unmatched > 0 && (
                <span className="text-red-600 dark:text-red-400">{preview.unmatched} non reconnu(s)</span>
              )}
              {preview.subtotal_rows > 0 && (
                <span className="text-gray-500 dark:text-gray-400">{preview.subtotal_rows} sous-total(aux) détecté(s)</span>
              )}
            </div>

            {preview.duplicates.length > 0 && (
              <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
                <strong>Attention :</strong> plusieurs lignes du fichier pointent vers le même poste (
                {preview.duplicates.join(', ')}). Corrigez ci-dessous, sinon une seule valeur sera conservée.
              </div>
            )}

            <div className="flex flex-wrap items-end gap-3">
              <label className="w-64">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Colonne à importer</span>
                <Select value={String(column ?? '')} onChange={(e) => setColumn(Number(e.target.value))}>
                  {preview.columns.map((c) => (
                    <option key={c.index} value={String(c.index)}>{c.header} ({c.filled} valeurs)</option>
                  ))}
                </Select>
              </label>
              <label className="w-44">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Date de clôture</span>
                <Input type="date" value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} />
              </label>
              <label className="w-40">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Période</span>
                <Select value={periodType} onChange={(e) => setPeriodType(e.target.value)}>
                  <option value="annuel">annuel</option>
                  <option value="semestriel">semestriel</option>
                  <option value="trimestriel">trimestriel</option>
                </Select>
              </label>
              <label className="w-52">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Unité du document</span>
                <Select value={unit} onChange={(e) => setUnit(e.target.value)}>
                  <option value="1">Francs CFA</option>
                  <option value="1000">Milliers de FCFA</option>
                  <option value="1000000">Millions de FCFA</option>
                </Select>
              </label>
            </div>
            <p className="-mt-2 text-xs text-gray-500 dark:text-gray-400">
              Un document présentant deux exercices donne deux colonnes : importez-en une, puis recommencez avec
              l'autre en changeant la date de clôture. {schema.sign_note}
            </p>

            <div className="max-h-96 overflow-y-auto overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
              <table className="w-full text-left text-sm">
                <thead className="sticky top-0 bg-white dark:bg-gray-950">
                  <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    <th className="p-2">Libellé du fichier</th>
                    <th className="p-2 text-right">Valeur</th>
                    <th className="p-2">Confiance</th>
                    <th className="p-2">Poste cible</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.rows.map((row, index) => {
                    const value = row.values[String(column ?? '')] ?? row.values[column ?? -1]
                    const style = CONFIDENCE_STYLES[row.confidence] ?? CONFIDENCE_STYLES.aucune
                    const isSubtotal = row.kind === 'subtotal'
                    return (
                      <tr
                        key={index}
                        className={`border-t border-gray-100 dark:border-gray-800 ${isSubtotal ? 'bg-gray-50 dark:bg-gray-900' : ''}`}
                      >
                        <td className="p-2 max-w-xs text-gray-700 dark:text-gray-300">{row.label}</td>
                        <td className="p-2 text-right tabular-nums">
                          {value === null || value === undefined ? '—' : nf.format(value)}
                        </td>
                        <td className="p-2">
                          <span className={`rounded px-1.5 py-0.5 text-xs font-semibold ${style.cls}`}>{style.label}</span>
                        </td>
                        <td className="p-2">
                          {isSubtotal ? (
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                              {row.subtotal_label} — recalculé, sert de contrôle
                            </span>
                          ) : (
                            <select
                              value={keyFor(row, index)}
                              onChange={(e) => setOverrides((prev) => ({ ...prev, [index]: e.target.value }))}
                              className="w-full rounded-md border border-gray-300 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                            >
                              <option value="">— ignorer cette ligne —</option>
                              {preview.available_lines.map((l) => (
                                <option key={l.key} value={l.key}>{l.label}</option>
                              ))}
                            </select>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {verification && (
              <div
                className={`rounded-md border px-3 py-2 text-sm ${
                  verification.all_match
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300'
                    : 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300'
                }`}
              >
                <strong>{verification.all_match ? '✓ Import vérifié.' : '✗ Écart détecté.'}</strong>{' '}
                {verification.message}
                <ul className="mt-1 list-disc pl-4 text-xs">
                  {verification.checks.map((c, i) => (
                    <li key={i}>
                      {c.label} — document : {fmtF(c.document)} · recalculé : {fmtF(c.computed)}
                      {!c.matches && ` (écart ${fmtF(c.computed - c.document)})`}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {saveMutation.isError && <ErrorState message={(saveMutation.error as Error).message} />}

            <div className="flex items-center gap-2">
              <Button disabled={!canSave || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
                {saveMutation.isPending ? 'Enregistrement…' : `Importer ${selectedCount} poste(s)`}
              </Button>
              <Button variant="secondary" onClick={onClose}>{verification ? 'Fermer' : 'Annuler'}</Button>
              {!canSave && (
                <span className="text-xs text-gray-500">
                  {periodEnd === '' ? 'Renseignez la date de clôture.' : 'Aucun poste à importer.'}
                </span>
              )}
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}

function StatementForm({
  schema,
  companyId,
  existing,
  onClose,
  onSaved,
}: {
  schema: Schema
  companyId: number
  existing: Statement | null
  onClose: () => void
  onSaved: () => void
}) {
  const [periodEnd, setPeriodEnd] = useState(existing?.period_end_date ?? '')
  const [periodType, setPeriodType] = useState(existing?.period_type ?? 'annuel')
  const [unit, setUnit] = useState(String(existing?.unit_multiplier ?? 1))
  const [sourceNote, setSourceNote] = useState(existing?.source_note ?? '')
  const [values, setValues] = useState<Record<string, string>>(() => {
    const initial: Record<string, string> = {}
    for (const group of schema.groups) {
      for (const line of group.lines) {
        const raw = existing?.raw_values?.[line.key]
        initial[line.key] = raw === undefined || raw === null ? '' : String(Number(raw))
      }
    }
    return initial
  })

  /**
   * Sous-totaux calculés pendant la frappe, avec les MÊMES formules que le
   * serveur (coefficients fournis par le schéma) : aucune règle n'est
   * réécrite ici, elles ne peuvent donc pas diverger.
   */
  const preview = useMemo(() => {
    const computed: Record<string, number> = {}
    for (const subtotal of schema.subtotals) {
      let sum = 0
      for (const [key, coefficient] of Object.entries(subtotal.formula)) {
        const base =
          key in computed ? computed[key] : (() => {
            const v = parseFloat(values[key] ?? '')
            return Number.isFinite(v) ? v : 0
          })()
        sum += coefficient * base
      }
      computed[subtotal.key] = sum
    }
    return computed
  }, [values, schema])

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload: Record<string, number | null> = {}
      for (const group of schema.groups) {
        for (const line of group.lines) {
          const raw = values[line.key]?.trim()
          payload[line.key] = raw === '' || raw === undefined ? null : Number(raw)
        }
      }
      return callApi('api_financial_statements.php', 'save', {
        company_id: companyId,
        statement_type: schema.key,
        period_end_date: periodEnd,
        period_type: periodType,
        unit_multiplier: Number(unit),
        source_note: sourceNote || undefined,
        values: payload,
      })
    },
    onSuccess: () => {
      onSaved()
      onClose()
    },
  })

  const canSave = /^\d{4}-\d{2}-\d{2}$/.test(periodEnd)

  return (
    <Modal title={`${existing ? 'Modifier' : 'Ajouter'} — ${schema.label}`} onClose={onClose}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end gap-3">
          <label className="w-44">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Date de clôture</span>
            <Input type="date" value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} />
          </label>
          <label className="w-40">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Période</span>
            <Select value={periodType} onChange={(e) => setPeriodType(e.target.value)}>
              <option value="annuel">annuel</option>
              <option value="semestriel">semestriel</option>
              <option value="trimestriel">trimestriel</option>
            </Select>
          </label>
          <label className="w-56">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Unité du document</span>
            <Select value={unit} onChange={(e) => setUnit(e.target.value)}>
              <option value="1">Francs CFA (tels quels)</option>
              <option value="1000">Milliers de FCFA</option>
              <option value="1000000">Millions de FCFA</option>
            </Select>
          </label>
          <label className="min-w-[220px] flex-1">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Source (facultatif)</span>
            <Input placeholder="ex : États financiers au 31 décembre 2023, page 4" value={sourceNote} onChange={(e) => setSourceNote(e.target.value)} />
          </label>
        </div>

        <SignBanner schema={schema} />

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="flex flex-col gap-4">
            {schema.groups.map((group) => (
              <div key={group.key}>
                <h4 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  {group.label}
                </h4>
                <div className="flex flex-col gap-1.5">
                  {group.lines.map((line) => (
                    <label key={line.key} className="flex items-center gap-2">
                      <span className="flex-1 text-xs text-gray-700 dark:text-gray-300">
                        {line.label}
                        {line.sign === 'charge' && <span className="ml-1 text-amber-600 dark:text-amber-400">(négatif)</span>}
                      </span>
                      <input
                        type="number"
                        step="any"
                        value={values[line.key] ?? ''}
                        onChange={(e) => setValues((prev) => ({ ...prev, [line.key]: e.target.value }))}
                        className="w-40 rounded-md border border-gray-300 px-2 py-1 text-right text-xs tabular-nums focus:border-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-gray-300"
                      />
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </div>

          <div className="lg:sticky lg:top-2 lg:self-start">
            <h4 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
              Sous-totaux calculés — à comparer au document
            </h4>
            <div className="rounded-md border border-gray-200 dark:border-gray-800">
              {schema.subtotals.map((s, i) => {
                // L'écart d'équilibre du bilan doit valoir zéro : on le
                // signale en vert ou en rouge, c'est le contrôle de saisie
                // le plus efficace de tout le formulaire.
                const isBalanceCheck = s.key === 'ecart_equilibre'
                const value = preview[s.key] ?? 0
                const balanced = Math.abs(value) < 0.5
                return (
                  <div
                    key={s.key}
                    className={`flex items-center justify-between px-3 py-1.5 text-sm ${i % 2 ? 'bg-gray-50 dark:bg-gray-900' : ''}`}
                  >
                    <span className="font-medium text-gray-700 dark:text-gray-300">{s.label}</span>
                    <span
                      className={`tabular-nums font-semibold ${
                        isBalanceCheck
                          ? balanced
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-red-600 dark:text-red-400'
                          : value < 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-gray-900 dark:text-gray-100'
                      }`}
                    >
                      {fmtF(value)}
                      {isBalanceCheck && (balanced ? ' ✓' : ' ✗')}
                    </span>
                  </div>
                )
              })}
            </div>
            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
              Montants exprimés dans l'unité choisie. S'ils correspondent aux totaux imprimés sur votre document, la
              saisie est bonne — c'est le contrôle le plus sûr, puisque ces totaux ne sont jamais saisis mais
              recalculés.
            </p>
          </div>
        </div>

        {saveMutation.isError && <ErrorState message={(saveMutation.error as Error).message} />}

        <div className="flex items-center gap-2">
          <Button disabled={!canSave || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
            {saveMutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
          </Button>
          <Button variant="secondary" onClick={onClose}>Annuler</Button>
          {!canSave && <span className="text-xs text-gray-500">Renseignez la date de clôture pour enregistrer.</span>}
        </div>
      </div>
    </Modal>
  )
}

const PERIOD_TYPE_OPTIONS = [
  { value: 'annuel', label: 'Annuel' },
  { value: 'semestriel', label: 'Semestriel' },
  { value: 'trimestriel', label: 'Trimestriel' },
  { value: '', label: 'Toutes périodicités' },
]

/** Dix années civiles glissantes, année en cours comprise. */
const DEFAUT_FIN = new Date().toISOString().slice(0, 10)
const DEFAUT_DEBUT = `${new Date().getFullYear() - 9}-01-01`

export function FinancialStatements() {
  const queryClient = useQueryClient()
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [activeType, setActiveType] = useState<string | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const [editing, setEditing] = useState<Statement | null>(null)
  // État choisi pour le résumé (StatTile) — null = automatique, le plus
  // récent de la période filtrée. Se retrouve automatiquement à "aucun"
  // (repli sur le plus récent) si l'entreprise/le format/le filtre change
  // et que l'état choisi n'existe plus dans la liste filtrée : pas besoin
  // d'effet de réinitialisation explicite, `series.find` renvoie déjà
  // undefined dans ce cas.
  const [selectedStatementId, setSelectedStatementId] = useState<number | null>(null)

  // Filtres de lecture. Par défaut : les dix dernières années et les seuls
  // états ANNUELS. Un émetteur publie couramment un annuel, deux semestriels
  // et quatre trimestriels par exercice ; tout afficher ensemble donne des
  // barres incomparables — un trimestre à côté d'une année — et une courbe
  // de ratios illisible, exactement le désordre visible sans ce filtre.
  const [periodType, setPeriodType] = useState<string>('annuel')
  const [dateFrom, setDateFrom] = useState<string>(DEFAUT_DEBUT)
  const [dateTo, setDateTo] = useState<string>(DEFAUT_FIN)
  const filtresParDefaut = periodType === 'annuel' && dateFrom === DEFAUT_DEBUT && dateTo === DEFAUT_FIN
  /** Envoyé aux deux requêtes : une chaîne vide signifie « aucun filtre ». */
  const periodParams = {
    period_type: periodType || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  }

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })
  const companies = useMemo(
    () => [...(companiesQuery.data ?? [])].sort((a, b) => a.symbol.localeCompare(b.symbol)),
    [companiesQuery.data],
  )

  const typesQuery = useQuery({
    queryKey: ['fs-types'],
    queryFn: () => callApi<{ types: TypeSummary[] }>('api_financial_statements.php', 'types', {}),
  })
  const types = typesQuery.data?.types ?? []
  const currentType = activeType ?? types[0]?.key ?? null

  const schemaQuery = useQuery({
    queryKey: ['fs-schema', currentType],
    queryFn: () => callApi<Schema>('api_financial_statements.php', 'schema', { statement_type: currentType }),
    enabled: currentType !== null,
  })

  const listQuery = useQuery({
    queryKey: ['fs-list', companyId, periodType, dateFrom, dateTo],
    queryFn: () =>
      callApi<{ groups: TypeGroup[]; total: number; total_unfiltered: number; hidden_by_filter: number }>(
        'api_financial_statements.php',
        'list',
        { company_id: companyId, ...periodParams },
      ),
    enabled: companyId !== null,
  })

  const seriesQuery = useQuery({
    queryKey: ['fs-series', companyId, currentType, periodType, dateFrom, dateTo],
    queryFn: () =>
      callApi<{
        series: Statement[]
        subtotals: { key: string; label: string }[]
        headline: string[]
        note: string
        count_unfiltered: number
      }>('api_financial_statements.php', 'series', {
        company_id: companyId,
        statement_type: currentType,
        ...periodParams,
      }),
    enabled: companyId !== null && currentType !== null,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['fs-list'] })
    queryClient.invalidateQueries({ queryKey: ['fs-series'] })
  }
  const activeMutation = useMutation({
    mutationFn: (v: { id: number; is_active: boolean; reason?: string }) =>
      callApi('api_financial_statements.php', 'set_active', v),
    onSuccess: invalidate,
  })
  const deleteMutation = useMutation({
    mutationFn: (id: number) => callApi('api_financial_statements.php', 'delete', { id }),
    onSuccess: invalidate,
  })

  const group = listQuery.data?.groups.find((g) => g.type === currentType)
  const series = seriesQuery.data?.series ?? []
  const last = series.length > 0 ? series[series.length - 1] : null
  const displayed = (selectedStatementId !== null ? series.find((s) => s.id === selectedStatementId) : undefined) ?? last
  const headlineKeys = seriesQuery.data?.headline ?? []

  const chartData = series.map((s) => {
    const row: Record<string, string | number | null> = { periode: s.period_end_date }
    for (const [key, value] of Object.entries(s.subtotals)) row[key] = value
    row.per = s.ratios.per
    row.pbr = s.ratios.pbr
    row.roe = s.ratios.roe_percent
    row.marge_nette = s.ratios.marge_nette_percent
    row.rendement = s.ratios.yield_percent
    row.payout = s.ratios.payout_percent
    return row
  })

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">États financiers</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Saisie directe des documents publiés, compartimentée par format, avec sous-totaux recalculés et ratios de
          valorisation.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> Les chiffres extraits automatiquement des rapports par l'IA sont
          pratiques mais faillibles : elle se trompe régulièrement d'<strong>unité</strong> (un résultat de
          11 405 000 000 FCFA lu « 11 405 » sur un document libellé en millions). Ici vous saisissez les états
          financiers tels qu'ils sont publiés, une fois, proprement.
        </p>
        <p>
          <strong>Un onglet par format.</strong> Une banque ne publie pas le même document qu'une entreprise
          industrielle : le compte de résultat bancaire s'articule autour du produit net bancaire et du coût du
          risque, là où le format SYSCOHADA commercial passe par la marge commerciale et la valeur ajoutée. Les
          conventions de signe diffèrent elles aussi — les charges sont <em>négatives</em> en SYSCOHADA,{' '}
          <em>positives</em> en bancaire. Chaque onglet applique la convention de son format et vous la rappelle.
        </p>
        <p>
          <strong>Les sous-totaux ne se saisissent jamais</strong> : ils sont recalculés à partir des postes et
          comparés à ce qu'imprime votre document. Pour un bilan, l'écart actif − passif doit valoir zéro : c'est un
          contrôle de saisie immédiat. Un état erroné peut être <strong>désactivé</strong> plutôt que supprimé — il
          disparaît des graphes mais reste consultable, ce qui compte quand un document est remplacé par une
          version certifiée.
        </p>
      </InfoPanel>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="min-w-[260px] flex-1">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Entreprise</span>
            <SearchableSelect
              value={companyId !== null ? String(companyId) : ''}
              onChange={(v) => setCompanyId(v ? Number(v) : null)}
              options={companies.map((c) => ({ value: String(c.company_id), label: `${c.symbol} — ${c.name}` }))}
            />
          </label>
          <Button
            disabled={companyId === null || !schemaQuery.data}
            onClick={() => {
              setEditing(null)
              setFormOpen(true)
            }}
          >
            + Saisir à la main
          </Button>
          <Button
            variant="secondary"
            disabled={companyId === null || !schemaQuery.data}
            onClick={() => setImportOpen(true)}
          >
            ⭳ Importer un fichier Excel / CSV
          </Button>
        </div>
        {companyId === null && (
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Choisissez une entreprise pour consulter ses états financiers ou en saisir un.
          </p>
        )}

        {companyId !== null && (
          <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
            <div className="flex flex-wrap items-end gap-4">
              <label className="min-w-[200px] flex-1">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Périodicité</span>
                <SearchableSelect
                  value={periodType}
                  onChange={setPeriodType}
                  options={PERIOD_TYPE_OPTIONS}
                  isClearable={false}
                  placeholder="Toutes périodicités"
                />
              </label>
              <label className="min-w-[150px]">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                  Clôture à partir du
                </span>
                <Input type="date" value={dateFrom} max={dateTo || undefined} onChange={(e) => setDateFrom(e.target.value)} />
              </label>
              <label className="min-w-[150px]">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Jusqu'au</span>
                <Input type="date" value={dateTo} min={dateFrom || undefined} onChange={(e) => setDateTo(e.target.value)} />
              </label>
              <Button
                variant="secondary"
                disabled={filtresParDefaut}
                onClick={() => {
                  setPeriodType('annuel')
                  setDateFrom(DEFAUT_DEBUT)
                  setDateTo(DEFAUT_FIN)
                }}
              >
                Réinitialiser
              </Button>
              <Button
                variant="secondary"
                disabled={periodType === '' && dateFrom === '' && dateTo === ''}
                onClick={() => {
                  setPeriodType('')
                  setDateFrom('')
                  setDateTo('')
                }}
              >
                Tout afficher
              </Button>
            </div>

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
              Le filtre s'applique aux tableaux comme aux graphes. Par défaut, les{' '}
              <strong>dix dernières années</strong> et les seuls états <strong>annuels</strong> : mêler des
              trimestres, des semestres et des exercices sur un même graphe compare des durées différentes et rend
              les évolutions fausses.
            </p>

            {/* Un état saisi hors de la période affichée paraîtrait n'avoir
                jamais été enregistré — et serait ressaisi en double. */}
            {(listQuery.data?.hidden_by_filter ?? 0) > 0 && (
              <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                {listQuery.data?.hidden_by_filter} état(s) enregistré(s) sont masqués par ce filtre (sur{' '}
                {listQuery.data?.total_unfiltered} au total pour cette entreprise). « Tout afficher » les fait
                réapparaître.
              </p>
            )}
          </div>
        )}
      </Card>

      {companyId !== null && types.length > 0 && (
        <>
          <Tabs
            tabs={types.map((t) => {
              const g = listQuery.data?.groups.find((x) => x.type === t.key)
              return { id: t.key, label: g && g.count > 0 ? `${t.label.split(' (')[0]} (${g.count})` : t.label.split(' (')[0] }
            })}
            active={currentType ?? ''}
            onChange={(id) => setActiveType(id)}
          />

          {schemaQuery.data && (
            <p className="-mt-3 text-xs text-gray-500 dark:text-gray-400">{schemaQuery.data.description}</p>
          )}
        </>
      )}

      {listQuery.isLoading && <LoadingState />}
      {listQuery.error && <ErrorState message={(listQuery.error as Error).message} />}

      {companyId !== null && group && group.count === 0 && (
        <Card>
          {/* Distinguer « rien de saisi » de « rien dans la fenêtre choisie » :
              confondre les deux pousse à ressaisir un état déjà enregistré. */}
          {(listQuery.data?.hidden_by_filter ?? 0) > 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucun état de ce format sur la période sélectionnée
              {periodType ? ` en périodicité « ${periodType} »` : ''}. Cette entreprise en compte pourtant{' '}
              {listQuery.data?.total_unfiltered} au total : élargissez l'intervalle ou cliquez « Tout afficher ».
            </p>
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucun état de ce format enregistré pour cette entreprise. Utilisez « + Ajouter » pour saisir le premier.
            </p>
          )}
        </Card>
      )}

      {displayed && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-semibold text-gray-500 dark:text-gray-400">
              Résumé {selectedStatementId === null ? '— dernier état de la période filtrée' : '— état choisi'}
            </h3>
            <label className="w-64">
              <Select
                value={selectedStatementId !== null ? String(selectedStatementId) : ''}
                onChange={(e) => setSelectedStatementId(e.target.value ? Number(e.target.value) : null)}
              >
                <option value="">Automatique (dernier de la période)</option>
                {[...series].reverse().map((s) => (
                  <option key={s.id} value={s.id}>{s.period_end_date} ({s.period_type})</option>
                ))}
              </Select>
            </label>
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {headlineKeys.map((key) => (
              <StatTile
                key={key}
                label={`${displayed.subtotal_labels[key] ?? key} (${displayed.period_end_date})`}
                value={fmtCompact(displayed.subtotals[key] ?? 0)}
                tone={(displayed.subtotals[key] ?? 0) >= 0 ? 'positive' : 'negative'}
                tooltip="Sous-total recalculé à partir des postes saisis pour cet état (pas une valeur saisie directement) — voir le détail dans le tableau plus bas."
              />
            ))}
            {/* Les ratios affichés dépendent du format : PER et PBR n'ont
                aucun sens devant un dividende, rendement et taux de
                distribution n'en ont aucun devant un bilan. */}
            {currentType === 'dividendes' ? (
              <>
                <StatTile
                  label="Rendement"
                  value={fmtPct(displayed.ratios.yield_percent)}
                  tone={displayed.ratios.yield_percent !== null && displayed.ratios.yield_percent >= 5 ? 'positive' : 'default'}
                  tooltip={`Rendement = Dividende net par action ÷ Cours de référence × 100.${
                    displayed.ratios.dividend_per_share_net !== null && displayed.ratios.dividend_price !== null
                      ? `\n\nCalcul ici : ${fmtF(displayed.ratios.dividend_per_share_net)} ÷ ${fmtF(displayed.ratios.dividend_price)} × 100 = ${fmtPct(displayed.ratios.yield_percent)}`
                      : ''
                  }`}
                />
                <StatTile
                  label="Taux de distribution"
                  value={fmtPct(displayed.ratios.payout_percent)}
                  tone={displayed.ratios.payout_exceeds_profit ? 'negative' : 'default'}
                  tooltip={`Taux de distribution = Total des dividendes versés ÷ Résultat net de l'exercice × 100. Au-delà de 100 %, l'entreprise puise dans ses réserves.${
                    displayed.ratios.total_paid !== null
                      ? `\n\nTotal versé retenu : ${fmtF(displayed.ratios.total_paid)}${displayed.ratios.total_paid_estimated ? ' (estimé : dividende par action × actions rémunérées, non saisi directement)' : ''}`
                      : ''
                  }`}
                />
                <StatTile
                  label="Dividende net / action"
                  value={fmtF(displayed.ratios.dividend_per_share_net)}
                  tooltip="Montant net par action tel que saisi dans l'état financier (dividende_net_par_action), ou déduit du dividende brut selon le document source."
                />
                <StatTile
                  label="Cours de référence"
                  value={fmtF(displayed.ratios.dividend_price)}
                  tooltip="Cours saisi par l'émetteur pour ce dividende (cours_reference) si renseigné, sinon la clôture BRVM la plus proche de la date de clôture de l'exercice."
                />
              </>
            ) : (
              <>
                <StatTile
                  label="PER"
                  value={displayed.ratios.per?.toFixed(2) ?? '—'}
                  tooltip={`PER = Cours de l'action ÷ Bénéfice net par action (BPA).\nBPA = Résultat net (de cet état) ÷ Nombre d'actions en circulation ACTUEL (pas celui de la date de l'état — non suivi dans le temps ; faussé en cas d'augmentation de capital, rachat ou split depuis).\nCours retenu : dernière clôture BRVM connue à la date de clôture de l'exercice — jamais le cours du jour.${
                    displayed.ratios.price !== null && displayed.ratios.eps !== null
                      ? `\n\nCalcul ici : ${fmtF(displayed.ratios.price)} ÷ ${displayed.ratios.eps.toFixed(2)} = ${displayed.ratios.per?.toFixed(2) ?? '—'}${displayed.ratios.price_date ? ` (cours du ${displayed.ratios.price_date})` : ''}${displayed.ratios.shares_outstanding !== null ? ` — actions en circulation (actuel) : ${fmtF(displayed.ratios.shares_outstanding)}` : ''}`
                      : ''
                  }\n\nVoir aussi « PER (officiel BRVM) » ci-contre, qui utilise le nombre d'actions réel à la date de l'état plutôt que le nombre actuel.`}
                />
                <StatTile
                  label="PER (officiel BRVM)"
                  value={displayed.ratios.per_brvm?.toFixed(2) ?? '—'}
                  tooltip={`PER tel que publié par la BRVM dans le Bulletin Officiel de la Cote (BOC) le plus proche (à la date de clôture de l'exercice ou avant) — calculé par la BRVM avec le nombre d'actions en circulation réel à cette date, contrairement au PER ci-contre qui utilise le nombre d'actions ACTUEL de l'entreprise (non historisé).${
                    displayed.ratios.per_brvm !== null
                      ? `\n\nBulletin du ${displayed.ratios.per_brvm_date ?? '—'}.${displayed.ratios.yield_net_brvm_percent !== null ? ` Rendement net officiel : ${fmtPct(displayed.ratios.yield_net_brvm_percent)}.` : ''}`
                      : `\n\nAucun bulletin traité pour cette entreprise à cette date — lance l'extraction PER/rendement depuis l'écran Opérations sur titres.`
                  }`}
                />
                <StatTile
                  label="PBR"
                  value={displayed.ratios.pbr?.toFixed(2) ?? '—'}
                  tooltip={`PBR = Cours de l'action ÷ Valeur comptable par action.\nValeur comptable par action = Capitaux propres (de cet état) ÷ Nombre d'actions en circulation ACTUEL (même limite que pour le PER, voir ci-dessus).${
                    displayed.ratios.price !== null && displayed.ratios.book_value_per_share !== null
                      ? `\n\nCalcul ici : ${fmtF(displayed.ratios.price)} ÷ ${displayed.ratios.book_value_per_share.toFixed(2)} = ${displayed.ratios.pbr?.toFixed(2) ?? '—'}`
                      : ''
                  }`}
                />
                <StatTile
                  label="ROE"
                  value={fmtPct(displayed.ratios.roe_percent)}
                  tooltip={`ROE (rentabilité des capitaux propres) = Résultat net ÷ Capitaux propres × 100.${
                    displayed.ratios.net_income !== null
                      ? `\n\nCalcul ici : ${fmtF(displayed.ratios.net_income)} ÷ capitaux propres × 100 = ${fmtPct(displayed.ratios.roe_percent)}`
                      : ''
                  }`}
                />
                <StatTile
                  label="Marge nette"
                  value={fmtPct(displayed.ratios.marge_nette_percent)}
                  tooltip={`Marge nette = Résultat net ÷ ${displayed.ratios.revenue_label || 'Chiffre d’affaires'} × 100.${
                    displayed.ratios.net_income !== null && displayed.ratios.revenue_base !== null
                      ? `\n\nCalcul ici : ${fmtF(displayed.ratios.net_income)} ÷ ${fmtF(displayed.ratios.revenue_base)} × 100 = ${fmtPct(displayed.ratios.marge_nette_percent)}`
                      : ''
                  }`}
                />
              </>
            )}
          </div>

          {displayed.ratios.not_computable_reasons.length > 0 && (
            <div className="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
              <strong>Ratios non calculés et pourquoi :</strong>
              <ul className="mt-0.5 list-disc pl-4">
                {displayed.ratios.not_computable_reasons.map((r, i) => <li key={i}>{r}</li>)}
              </ul>
            </div>
          )}

          {series.length >= 1 && (
            <Card title="Soldes du format, période par période">
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={chartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                  <XAxis dataKey="periode" tick={{ fontSize: 10 }} />
                  <YAxis tick={{ fontSize: 10 }} width={70} tickFormatter={fmtCompact} />
                  <ReferenceLine y={0} stroke="var(--chart-muted)" />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Tooltip formatter={(v) => fmtF(Number(v))} />
                  {(seriesQuery.data?.subtotals ?? [])
                    .filter((s) => s.key !== 'ecart_equilibre')
                    .slice(0, 5)
                    .map((s, i) => (
                      <Bar key={s.key} dataKey={s.key} name={s.label} fill={`var(--chart-${i + 1})`}>
                        {chartData.map((d, j) => (
                          <Cell
                            key={j}
                            fill={
                              Number(d[s.key]) < 0 ? 'var(--chart-negative)' : `var(--chart-${i + 1})`
                            }
                          />
                        ))}
                      </Bar>
                    ))}
                </BarChart>
              </ResponsiveContainer>
              <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Chaque barre est un solde du format : voir fondre l'un au suivant montre où part l'argent. Les
                montants négatifs apparaissent en rouge. {seriesQuery.data?.note}
              </p>
            </Card>
          )}

          {series.length >= 2 && (
            <Card title="Ratios dans le temps">
              <ResponsiveContainer width="100%" height={230}>
                <LineChart data={chartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                  <XAxis dataKey="periode" tick={{ fontSize: 10 }} />
                  <YAxis tick={{ fontSize: 10 }} width={50} />
                  <ReferenceLine y={0} stroke="var(--chart-muted)" />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Tooltip formatter={(v) => (v === null ? '—' : Number(v).toFixed(2))} />
                  {currentType === 'dividendes' ? (
                    <>
                      <Line dataKey="rendement" name="Rendement %" stroke="var(--chart-positive)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
                      <Line dataKey="payout" name="Taux de distribution %" stroke="var(--chart-2)" strokeWidth={2} strokeDasharray="4 3" dot={{ r: 3 }} connectNulls />
                    </>
                  ) : (
                    <>
                      <Line dataKey="per" name="PER" stroke="var(--chart-1)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
                      <Line dataKey="pbr" name="PBR" stroke="var(--chart-5)" strokeWidth={2} dot={{ r: 3 }} connectNulls />
                      <Line dataKey="roe" name="ROE %" stroke="var(--chart-positive)" strokeWidth={2} strokeDasharray="4 3" dot={{ r: 3 }} connectNulls />
                    </>
                  )}
                </LineChart>
              </ResponsiveContainer>
              <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {currentType === 'dividendes' ? (
                  <>
                    Le <strong>rendement</strong> dit ce que le dividende rapporte au prix de l'action : 5 % signifie
                    50 000 F bruts pour 1 000 000 F investis. Le <strong>taux de distribution</strong> est la part du
                    bénéfice reversée aux actionnaires — au-dessus de 100 %, l'entreprise puise dans ses réserves, ce
                    qui est rarement tenable plusieurs années de suite.
                  </>
                ) : (
                  <>
                    Le <strong>PER</strong> indique combien d'années de bénéfice le marché accepte de payer ; le{' '}
                    <strong>PBR</strong> compare le cours aux capitaux propres par action ; le <strong>ROE</strong>{' '}
                    dit ce que rapporte l'argent des actionnaires. Chaque ratio utilise le cours le plus proche de la
                    date de clôture, jamais le cours du jour.
                  </>
                )}
              </p>
            </Card>
          )}
        </>
      )}

      {group && group.count > 0 && (
        <Card title={`${group.label} — ${group.count} état(s), ${group.active_count} actif(s)`}>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Clôture</th>
                  <th className="pb-2 pr-3">Période</th>
                  <th className="pb-2 pr-3 text-right">Postes</th>
                  {(seriesQuery.data?.headline ?? []).map((k) => (
                    <th key={k} className="pb-2 pr-3 text-right">{group.statements[0]?.subtotal_labels[k] ?? k}</th>
                  ))}
                  <th className="pb-2 pr-3">État</th>
                  <th className="pb-2 pr-3">Source</th>
                  <th className="pb-2">Actions</th>
                </tr>
              </thead>
              <tbody>
                {group.statements.map((s) => (
                  <tr
                    key={s.id}
                    className={`border-t border-gray-100 align-top dark:border-gray-800 ${
                      s.is_active ? '' : 'opacity-60'
                    }`}
                  >
                    <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{s.period_end_date}</td>
                    <td className="py-1.5 pr-3 text-xs text-gray-500 dark:text-gray-400">{s.period_type}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums">{s.lines_filled}</td>
                    {(seriesQuery.data?.headline ?? []).map((k) => (
                      <td key={k} className="py-1.5 pr-3 text-right tabular-nums">{fmtCompact(s.subtotals[k] ?? 0)}</td>
                    ))}
                    <td className="py-1.5 pr-3 whitespace-nowrap">
                      {s.is_active ? (
                        <span className="rounded px-1.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                          actif
                        </span>
                      ) : (
                        <span
                          className="rounded px-1.5 py-0.5 text-xs font-semibold bg-gray-200 text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                          title={s.deactivated_reason ?? 'Désactivé'}
                        >
                          désactivé
                        </span>
                      )}
                    </td>
                    <td className="py-1.5 pr-3 max-w-xs text-xs text-gray-500 dark:text-gray-400">{s.source_note ?? '—'}</td>
                    <td className="py-1.5 whitespace-nowrap">
                      <button
                        type="button"
                        onClick={() => {
                          setEditing(s)
                          setFormOpen(true)
                        }}
                        className="mr-2 text-xs text-gray-700 underline underline-offset-2 hover:text-black dark:text-gray-200"
                      >
                        Modifier
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          if (s.is_active) {
                            const reason = window.prompt(
                              "Désactiver cet état ? Il disparaîtra des graphes mais restera consultable.\n\nRaison (facultative) :",
                              '',
                            )
                            if (reason !== null) {
                              activeMutation.mutate({ id: s.id, is_active: false, reason: reason || undefined })
                            }
                          } else {
                            activeMutation.mutate({ id: s.id, is_active: true })
                          }
                        }}
                        className="mr-2 text-xs text-gray-700 underline underline-offset-2 hover:text-black dark:text-gray-200"
                      >
                        {s.is_active ? 'Désactiver' : 'Réactiver'}
                      </button>
                      <IconButton
                        title="Supprimer définitivement"
                        tone="danger"
                        onClick={() => {
                          if (
                            window.confirm(
                              `Supprimer DÉFINITIVEMENT l'état au ${s.period_end_date} et ses ${s.lines_filled} postes ?\n\nPour le retirer des graphes sans perdre la saisie, préférez « Désactiver ».`,
                            )
                          ) {
                            deleteMutation.mutate(s.id)
                          }
                        }}
                      >
                        <TrashIcon />
                      </IconButton>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Les états désactivés restent listés ici (en grisé) mais sont exclus des graphes et des ratios. Survolez
            le badge « désactivé » pour en voir la raison.
          </p>
        </Card>
      )}

      {importOpen && schemaQuery.data && companyId !== null && (
        <ImportModal
          schema={{ ...schemaQuery.data, key: currentType as string }}
          companyId={companyId}
          onClose={() => setImportOpen(false)}
          onSaved={invalidate}
        />
      )}

      {formOpen && schemaQuery.data && companyId !== null && (
        <StatementForm
          schema={{ ...schemaQuery.data, key: currentType as string }}
          companyId={companyId}
          existing={editing}
          onClose={() => {
            setFormOpen(false)
            setEditing(null)
          }}
          onSaved={invalidate}
        />
      )}
    </div>
  )
}
