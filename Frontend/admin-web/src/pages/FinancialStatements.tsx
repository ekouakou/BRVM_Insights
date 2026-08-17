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
import { callApi } from '../lib/apiClient'
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

export function FinancialStatements() {
  const queryClient = useQueryClient()
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [activeType, setActiveType] = useState<string | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Statement | null>(null)

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
    queryKey: ['fs-list', companyId],
    queryFn: () => callApi<{ groups: TypeGroup[]; total: number }>('api_financial_statements.php', 'list', { company_id: companyId }),
    enabled: companyId !== null,
  })

  const seriesQuery = useQuery({
    queryKey: ['fs-series', companyId, currentType],
    queryFn: () =>
      callApi<{ series: Statement[]; subtotals: { key: string; label: string }[]; headline: string[]; note: string }>(
        'api_financial_statements.php',
        'series',
        { company_id: companyId, statement_type: currentType },
      ),
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
            + Ajouter
          </Button>
        </div>
        {companyId === null && (
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Choisissez une entreprise pour consulter ses états financiers ou en saisir un.
          </p>
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
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Aucun état de ce format enregistré pour cette entreprise. Utilisez « + Ajouter » pour saisir le premier.
          </p>
        </Card>
      )}

      {last && (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {headlineKeys.map((key) => (
              <StatTile
                key={key}
                label={`${last.subtotal_labels[key] ?? key} (${last.period_end_date})`}
                value={fmtCompact(last.subtotals[key] ?? 0)}
                tone={(last.subtotals[key] ?? 0) >= 0 ? 'positive' : 'negative'}
              />
            ))}
            {/* Les ratios affichés dépendent du format : PER et PBR n'ont
                aucun sens devant un dividende, rendement et taux de
                distribution n'en ont aucun devant un bilan. */}
            {currentType === 'dividendes' ? (
              <>
                <StatTile
                  label="Rendement"
                  value={fmtPct(last.ratios.yield_percent)}
                  tone={last.ratios.yield_percent !== null && last.ratios.yield_percent >= 5 ? 'positive' : 'default'}
                />
                <StatTile
                  label="Taux de distribution"
                  value={fmtPct(last.ratios.payout_percent)}
                  tone={last.ratios.payout_exceeds_profit ? 'negative' : 'default'}
                />
                <StatTile label="Dividende net / action" value={fmtF(last.ratios.dividend_per_share_net)} />
                <StatTile
                  label="Cours de référence"
                  value={fmtF(last.ratios.dividend_price)}
                />
              </>
            ) : (
              <>
                <StatTile label="PER" value={last.ratios.per?.toFixed(2) ?? '—'} />
                <StatTile label="PBR" value={last.ratios.pbr?.toFixed(2) ?? '—'} />
                <StatTile label="ROE" value={fmtPct(last.ratios.roe_percent)} />
                <StatTile label="Marge nette" value={fmtPct(last.ratios.marge_nette_percent)} />
              </>
            )}
          </div>

          {last.ratios.not_computable_reasons.length > 0 && (
            <div className="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
              <strong>Ratios non calculés et pourquoi :</strong>
              <ul className="mt-0.5 list-disc pl-4">
                {last.ratios.not_computable_reasons.map((r, i) => <li key={i}>{r}</li>)}
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
