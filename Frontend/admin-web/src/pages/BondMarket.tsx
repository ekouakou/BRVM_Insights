import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { BondCategory, BondMetric, BondMetricsListResult, BondSymbolOption } from '../lib/types'
import { Button, Card, ErrorState, InfoPanel, Input, LoadingState, SearchableSelect, Select, StatTile } from '../components/ui'

function fmtNum(value: number | string | null, digits = 2): string {
  if (value === null) return '—'
  const num = typeof value === 'string' ? parseFloat(value) : value
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}

const CATEGORIES: { value: BondCategory; label: string }[] = [
  { value: 'sovereign', label: 'Souveraines (États)' },
  { value: 'financial_institution', label: "Institutions financières régionales/internationales" },
  { value: 'corporate', label: "Entreprises" },
  { value: 'gss_financial', label: 'GSS — institutions financières' },
  { value: 'gss_corporate', label: 'GSS — entreprises' },
  { value: 'fctc_public', label: 'FCTC — État / institutions publiques' },
  { value: 'fctc_financial', label: 'FCTC — institutions financières' },
  { value: 'fctc_corporate', label: 'FCTC — entreprises' },
  { value: 'fctc_gss_corporate', label: 'FCTC GSS — entreprises' },
  { value: 'sukuk', label: 'Sukuk' },
  { value: 'convertible', label: 'Convertibles en actions' },
]

function categoryLabel(cat: string): string {
  return CATEGORIES.find((c) => c.value === cat)?.label ?? cat
}

function periodLabel(p: string | null): string {
  if (p === 'A') return 'Annuel'
  if (p === 'S') return 'Semestriel'
  if (p === 'T') return 'Trimestriel'
  return '—'
}

type HistoryPoint = {
  date: string
  reference_price: number | null
  accrued_coupon: number | null
  bulletin_title: string | null
}

function HistoryTooltip({ active, payload }: { active?: boolean; payload?: { payload: HistoryPoint }[] }) {
  if (!active || !payload?.length) return null
  const p = payload[0].payload
  return (
    <div className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
      <div className="mb-1 font-medium">{p.date}</div>
      <div className="grid grid-cols-2 gap-x-3 tabular-nums">
        <span className="text-gray-500 dark:text-gray-400">Cours de référence</span>
        <span className="text-right">{fmtNum(p.reference_price)}</span>
        <span className="text-gray-500 dark:text-gray-400">Coupon couru</span>
        <span className="text-right">{fmtNum(p.accrued_coupon)}</span>
      </div>
      {p.bulletin_title && <div className="mt-1 text-gray-500 dark:text-gray-400">{p.bulletin_title}</div>}
    </div>
  )
}

export function BondMarket() {
  const queryClient = useQueryClient()
  const [selectedSymbol, setSelectedSymbol] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [symbolSearch, setSymbolSearch] = useState('')
  const [bulletinId, setBulletinId] = useState('')
  const [asOfDate, setAsOfDate] = useState('')
  const [extractingId, setExtractingId] = useState<number | null>(null)

  const symbolsQuery = useQuery({
    queryKey: ['bond-symbols'],
    queryFn: () => callApi<{ symbols: BondSymbolOption[] }>('api_bulletin_bond_metrics.php', 'symbols', {}),
  })
  const symbolOptions = symbolsQuery.data?.symbols ?? []

  // « Date de référence » : même principe que le PER des actions — filtre
  // les bulletins jusqu'à cette date incluse, triés par publish_date
  // décroissant côté API, la première ligne devient donc la séance connue
  // la plus proche de cette date sans jamais regarder après.
  const listFilters = {
    symbol: selectedSymbol || symbolSearch || undefined,
    category: categoryFilter || undefined,
    bulletin_id: bulletinId || undefined,
    end_date: bulletinId ? undefined : asOfDate || undefined,
  }

  const listQuery = useQuery({
    queryKey: ['bond-metrics-list', listFilters],
    queryFn: () => callApi<BondMetricsListResult>('api_bulletin_bond_metrics.php', 'list', listFilters),
  })

  const extractMutation = useMutation({
    mutationFn: (id: number) => callApi('api_bulletin_bond_metrics.php', 'extract', { bulletin_id: id }),
    onMutate: (id) => setExtractingId(id),
    onSettled: () => {
      setExtractingId(null)
      queryClient.invalidateQueries({ queryKey: ['bond-metrics-list'] })
      queryClient.invalidateQueries({ queryKey: ['bond-symbols'] })
      queryClient.invalidateQueries({ queryKey: ['bond-history'] })
    },
  })

  // Historique complet du titre choisi, TOUS bulletins confondus —
  // indépendant du bulletin/de la date de référence ci-dessus (qui ne
  // servent qu'à figer la fiche résumée sur une séance précise), même
  // principe que l'onglet PER des actions.
  const historyQuery = useQuery({
    queryKey: ['bond-history', selectedSymbol],
    queryFn: () => callApi<BondMetricsListResult>('api_bulletin_bond_metrics.php', 'list', { symbol: selectedSymbol, limit: 2000 }),
    enabled: !!selectedSymbol,
  })

  const historyChartData = useMemo(() => {
    const rows: BondMetric[] = (historyQuery.data?.bonds ?? []).filter((b) => b.symbol === selectedSymbol)
    return [...rows]
      .sort((a, b) => a.publish_date.localeCompare(b.publish_date))
      .map((r) => ({
        date: r.publish_date,
        reference_price: r.reference_price !== null ? Number(r.reference_price) : null,
        accrued_coupon: r.accrued_coupon !== null ? Number(r.accrued_coupon) : null,
        bulletin_title: r.bulletin_title ?? null,
      }))
  }, [historyQuery.data, selectedSymbol])

  const data = listQuery.data
  const rows = data?.bonds ?? []
  const latestForSelected = selectedSymbol ? rows.find((r) => r.symbol === selectedSymbol) ?? null : null

  const hasActiveFilters = categoryFilter !== '' || symbolSearch !== '' || bulletinId !== '' || asOfDate !== ''
  function resetFilters() {
    setCategoryFilter('')
    setSymbolSearch('')
    setBulletinId('')
    setAsOfDate('')
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Marché obligataire</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Cours, coupon couru et échéances des lignes obligataires (souveraines, entreprises, GSS, FCTC, Sukuk,
          convertibles), extraits par IA des Bulletins Officiels de la Cote déjà traités.
        </p>
      </div>

      <InfoPanel>
        <p>
          <strong>À quoi sert cet écran.</strong> Chaque Bulletin Officiel de la Cote publie, en plus du marché des
          actions, un marché obligataire de plus de 200 lignes réparties en plusieurs catégories : obligations
          souveraines (États), d'institutions financières, d'entreprises, vertes/sociales/durables (GSS), Fonds
          Communs de Titrisation de Créances (FCTC), Sukuk et convertibles. Cet écran extrait ce tableau bulletin par
          bulletin pour le rendre interrogeable et suivre un titre dans le temps.
        </p>
        <p>
          <strong>Cours du jour absent.</strong> Beaucoup de lignes obligataires ne s'échangent pas tous les jours :
          « NC » signifie non coté cette séance, « SP » suspendu — dans ces cas le cours de référence retenu est
          généralement celui de la veille, sans qu'il y ait eu de transaction.
        </p>
        <p>
          <strong>Aucun rattachement à une entreprise.</strong> Contrairement aux actions, la plupart des émetteurs
          obligataires sont des États ou des fonds de titrisation — le symbole et le titre tels qu'imprimés dans le
          bulletin identifient une ligne obligataire dans le temps.
        </p>
      </InfoPanel>

      {!!data?.pending_count && (
        <Card>
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
              {data.pending_count} bulletin(s) pas encore extrait(s)
            </h3>
          </div>
          <div className="flex flex-col gap-2">
            {data.pending_bulletins.map((b) => (
              <div key={b.id} className="flex items-center justify-between gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm dark:border-gray-800">
                <span className="text-gray-600 dark:text-gray-300">
                  {b.publish_date} — {b.title}
                </span>
                <Button
                  variant="secondary"
                  disabled={extractMutation.isPending && extractingId === b.id}
                  onClick={() => extractMutation.mutate(b.id)}
                >
                  {extractMutation.isPending && extractingId === b.id ? 'Extraction… (peut prendre plusieurs minutes)' : 'Extraire'}
                </Button>
              </div>
            ))}
          </div>
          {extractMutation.isError && (
            <p className="mt-2 text-xs text-red-600 dark:text-red-400">
              {(extractMutation.error as Error).message}
            </p>
          )}
        </Card>
      )}

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-64">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Titre obligataire</span>
            <SearchableSelect
              value={selectedSymbol}
              onChange={setSelectedSymbol}
              placeholder="Tous"
              options={symbolOptions.map((s) => ({ value: s.symbol, label: `${s.symbol} — ${s.title ?? ''}` }))}
            />
          </label>
          <label className="w-56">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Catégorie</span>
            <Select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
              <option value="">Toutes</option>
              {CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </Select>
          </label>
          <label className="w-48">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Recherche libre</span>
            <Input
              type="text"
              placeholder="Symbole ou titre…"
              value={symbolSearch}
              onChange={(e) => setSymbolSearch(e.target.value)}
              disabled={selectedSymbol !== ''}
            />
          </label>
          <label className="w-64">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Bulletin</span>
            <Select
              value={bulletinId}
              onChange={(e) => {
                setBulletinId(e.target.value)
                if (e.target.value) setAsOfDate('')
              }}
            >
              <option value="">Automatique (le plus récent, ou par date)</option>
              {(data?.bulletins ?? []).map((b) => (
                <option key={b.id} value={String(b.id)}>{b.publish_date} — {b.title}</option>
              ))}
            </Select>
          </label>
          <label className="w-48">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de référence</span>
            <Input
              type="date"
              value={asOfDate}
              onChange={(e) => {
                setAsOfDate(e.target.value)
                if (e.target.value) setBulletinId('')
              }}
              disabled={bulletinId !== ''}
            />
          </label>
          {(hasActiveFilters || selectedSymbol !== '') && (
            <button
              type="button"
              onClick={() => {
                resetFilters()
                setSelectedSymbol('')
              }}
              className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
              Réinitialiser les filtres
            </button>
          )}
        </div>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
          Choisis un bulletin précis pour voir exactement sa séance, ou une date de référence pour prendre
          automatiquement le bulletin le plus proche de cette date (sans jamais regarder après) — les deux sont
          exclusifs. La recherche libre est désactivée quand un titre précis est sélectionné.
        </p>
      </Card>

      {latestForSelected && (
        <div>
          <h3 className="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
            {bulletinId
              ? 'Fiche du bulletin sélectionné'
              : asOfDate
              ? `Fiche au ${asOfDate} (ou séance connue la plus proche avant)`
              : 'Fiche du jour de cotation (dernier bulletin traité)'}
            {' — '}{latestForSelected.symbol}
          </h3>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile
              label="Cours de référence"
              value={fmtNum(latestForSelected.reference_price)}
              tooltip="Cours de référence retenu pour la séance — reste au dernier cours connu si le titre n'a pas été traité ce jour (NC/SP)."
            />
            <StatTile
              label="Cours du jour"
              value={
                latestForSelected.day_price_status
                  ? latestForSelected.day_price_status === 'NC' ? 'Non coté' : 'Suspendu'
                  : fmtNum(latestForSelected.day_price)
              }
            />
            <StatTile
              label="Coupon couru"
              value={fmtNum(latestForSelected.accrued_coupon)}
              tooltip={`Période : ${periodLabel(latestForSelected.period_type)}. Montant net du prochain coupon : ${fmtNum(latestForSelected.net_amount)}.`}
            />
            <StatTile
              label="Échéance du prochain coupon"
              value={latestForSelected.maturity_date ?? '—'}
              tooltip={`Type d'amortissement : ${latestForSelected.amortization_type ?? '—'}.`}
            />
          </div>
        </div>
      )}

      {selectedSymbol && (
        <Card title={`Évolution — ${selectedSymbol}`}>
          {historyQuery.isLoading && <LoadingState label="Chargement de l'historique…" />}
          {historyQuery.error && <ErrorState message={(historyQuery.error as Error).message} />}
          {!historyQuery.isLoading && historyChartData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucun bulletin déjà extrait ne contient ce titre.</p>
          )}
          {historyChartData.length === 1 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Un seul bulletin extrait contient ce titre pour l'instant — pas assez de points pour tracer une
              évolution.
            </p>
          )}
          {historyChartData.length > 1 && (
            <div className="flex flex-col gap-6">
              <div>
                <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  Cours de référence (par bulletin)
                </h4>
                <ResponsiveContainer width="100%" height={180}>
                  <LineChart data={historyChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                    <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                    <YAxis tick={{ fontSize: 10 }} width={70} domain={['auto', 'auto']} tickFormatter={(v) => fmtNum(v, 0)} />
                    <Tooltip content={<HistoryTooltip />} />
                    <Line
                      type="monotone"
                      dataKey="reference_price"
                      name="Cours de référence"
                      stroke="var(--chart-1)"
                      strokeWidth={2}
                      dot={{ r: 3 }}
                      connectNulls
                      isAnimationActive={false}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>

              <div>
                <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  Coupon couru (par bulletin)
                </h4>
                <p className="mb-1 text-xs text-gray-500 dark:text-gray-400">
                  Le coupon couru grandit entre deux paiements puis retombe brutalement à chaque détachement — une
                  dent de scie normale, pas une anomalie.
                </p>
                <ResponsiveContainer width="100%" height={180}>
                  <LineChart data={historyChartData} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-muted)" strokeOpacity={0.3} />
                    <XAxis dataKey="date" tick={{ fontSize: 10 }} minTickGap={20} />
                    <YAxis tick={{ fontSize: 10 }} width={70} domain={['auto', 'auto']} tickFormatter={(v) => fmtNum(v, 1)} />
                    <Tooltip content={<HistoryTooltip />} />
                    <Line
                      type="monotone"
                      dataKey="accrued_coupon"
                      name="Coupon couru"
                      stroke="var(--chart-2)"
                      strokeWidth={2}
                      dot={{ r: 3 }}
                      connectNulls
                      isAnimationActive={false}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>
          )}
        </Card>
      )}

      {listQuery.isLoading && <LoadingState label="Chargement du marché obligataire…" />}
      {listQuery.error && <ErrorState message={(listQuery.error as Error).message} />}

      {data && rows.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune ligne obligataire ne correspond à ces critères.</p>
      )}

      {rows.length > 0 && (
        <Card>
          <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">{rows.length} ligne(s)</div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">Date</th>
                  <th className="pb-2 pr-3">Symbole</th>
                  <th className="pb-2 pr-3">Titre</th>
                  <th className="pb-2 pr-3">Catégorie</th>
                  <th className="pb-2 pr-3 text-right">Cours de référence</th>
                  <th className="pb-2 pr-3 text-right">Coupon couru</th>
                  <th className="pb-2 pr-3">Période</th>
                  <th className="pb-2 pr-3">Échéance</th>
                  <th className="pb-2 pr-3">Bulletin source</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-gray-100 align-top dark:border-gray-800">
                    <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{r.publish_date}</td>
                    <td className="py-2 pr-3 whitespace-nowrap font-medium">{r.symbol}</td>
                    <td className="py-2 pr-3 max-w-xs text-gray-600 dark:text-gray-300">{r.title ?? '—'}</td>
                    <td className="py-2 pr-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{categoryLabel(r.category)}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">
                      {fmtNum(r.reference_price)}
                      {r.day_price_status && (
                        <span className="ml-1 text-[10px] font-semibold text-amber-600 dark:text-amber-400">{r.day_price_status}</span>
                      )}
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">{fmtNum(r.accrued_coupon)}</td>
                    <td className="py-2 pr-3 whitespace-nowrap">{periodLabel(r.period_type)}</td>
                    <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{r.maturity_date ?? '—'}</td>
                    <td className="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{r.bulletin_title}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}
