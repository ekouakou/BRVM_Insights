import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { Company, CompositeScoreRow, ScreenerRow } from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState, SearchableSelect, Select, Tabs } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

const LIQUIDITY_LEVELS = ['Élevée', 'Moyenne', 'Faible', 'Illiquide'] as const

function signalBadgeClass(score: number | null) {
  if (score === null) return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  if (score >= 2) return 'bg-emerald-600 text-white'
  if (score === 1) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
  if (score === 0) return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
  if (score === -1) return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
  return 'bg-red-600 text-white'
}

function liquidityBadgeClass(liquidity: string | null) {
  switch (liquidity) {
    case 'Élevée':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
    case 'Moyenne':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
    case 'Faible':
      return 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300'
    case 'Illiquide':
      return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
    default:
      return 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
  }
}

function compositeScoreClass(score: number | null) {
  if (score === null) return 'text-gray-400 dark:text-gray-500'
  if (score >= 70) return 'text-emerald-600 dark:text-emerald-400'
  if (score >= 50) return 'text-amber-600 dark:text-amber-400'
  return 'text-red-600 dark:text-red-400'
}

function fmt(n: string | number | null | undefined, digits = 0): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function fmtSub(n: number | null): string {
  return n !== null ? n.toFixed(0) : '—'
}

export function Screener() {
  const [activeTab, setActiveTab] = useState<'filters' | 'composite_score'>('filters')
  const [startDate, setStartDate] = useState(daysAgoIso(30))
  const [endDate, setEndDate] = useState(todayIso())
  const [sectorId, setSectorId] = useState('')
  const [minScore, setMinScore] = useState('')
  const [maxScore, setMaxScore] = useState('')
  const [minRsi, setMinRsi] = useState('')
  const [maxRsi, setMaxRsi] = useState('')
  const [minVariation, setMinVariation] = useState('')
  const [maxVariation, setMaxVariation] = useState('')
  const [minVolume, setMinVolume] = useState('')
  const [liquidity, setLiquidity] = useState<string[]>([])
  const [selected, setSelected] = useState<number[]>([])

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const sectorOptions = useMemo(() => {
    const byId = new Map<number, string>()
    for (const c of companiesQuery.data ?? []) {
      if (c.sector_id != null && c.sector_name) {
        byId.set(c.sector_id, c.sector_name)
      }
    }
    return Array.from(byId.entries()).sort((a, b) => a[1].localeCompare(b[1]))
  }, [companiesQuery.data])

  const filters = {
    start_date: startDate,
    end_date: endDate,
    sector_id: sectorId || undefined,
    min_score: minScore || undefined,
    max_score: maxScore || undefined,
    min_rsi: minRsi || undefined,
    max_rsi: maxRsi || undefined,
    min_variation_percent: minVariation || undefined,
    max_variation_percent: maxVariation || undefined,
    min_volume: minVolume || undefined,
    liquidity,
  }

  const screenerQuery = useQuery({
    queryKey: ['screener', filters],
    queryFn: () => callApi<ScreenerRow[]>('api_screener.php', 'screen', filters),
    enabled: activeTab === 'filters' && startDate <= endDate,
  })

  const compositeParams = { start_date: startDate, end_date: endDate, sector_id: sectorId || undefined }
  const compositeQuery = useQuery({
    queryKey: ['composite-score', compositeParams],
    queryFn: () => callApi<CompositeScoreRow[]>('api_composite_score.php', 'compute', compositeParams),
    enabled: activeTab === 'composite_score' && startDate <= endDate,
  })

  function toggleLiquidity(level: string) {
    setLiquidity((prev) => (prev.includes(level) ? prev.filter((l) => l !== level) : [...prev, level]))
  }

  function resetFilters() {
    setSectorId('')
    setMinScore('')
    setMaxScore('')
    setMinRsi('')
    setMaxRsi('')
    setMinVariation('')
    setMaxVariation('')
    setMinVolume('')
    setLiquidity([])
  }

  const rows = screenerQuery.data ?? []
  const compositeRows = compositeQuery.data ?? []
  const hasActiveFilters =
    sectorId !== '' || minScore !== '' || maxScore !== '' || minRsi !== '' || maxRsi !== '' ||
    minVariation !== '' || maxVariation !== '' || minVolume !== '' || liquidity.length > 0

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Screener</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Filtre toute la cote sur plusieurs critères à la fois, ou consulte un score de synthèse pondéré par
          entreprise.
        </p>
      </div>

      <Tabs
        tabs={[
          { id: 'filters', label: 'Filtres multi-critères' },
          { id: 'composite_score', label: 'Score composite' },
        ]}
        active={activeTab}
        onChange={(id) => setActiveTab(id as 'filters' | 'composite_score')}
      />

      {activeTab === 'filters' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet écran.</strong> Les autres écrans (Classements, Cotations, Statistiques)
              répondent chacun à une question précise sur un critère à la fois. Le screener répond à « quelles
              entreprises correspondent à PLUSIEURS critères en même temps ? » (ex. « RSI survendu ET liquidité
              élevée ET secteur Finance ») — utile pour partir d'une idée d'investissement et voir immédiatement
              quelles entreprises la vérifient, plutôt que de croiser manuellement plusieurs tableaux.
            </p>
            <p>
              <strong>Colonne Rang secteur.</strong> Position de l'entreprise au sein de son propre secteur
              d'activité, classée par performance de cours sur la période choisie (1 = la meilleure performance du
              secteur sur cette période). Une entreprise très performante dans un secteur globalement en difficulté
              peut être plus significative qu'une performance équivalente dans un secteur déjà porteur.
            </p>
            <p>
              <strong>Signal, Liquidité, RSI.</strong> Mêmes définitions que sur la page Cotations (score composite
              -2 à +2 basé sur RSI/MACD/tendance/Bollinger, plafonné automatiquement si le titre est illiquide) —
              voir l'onglet « Signaux techniques » de cette page pour le détail complet de chaque mesure.
            </p>
            <p>
              <strong>Les filtres se combinent.</strong> Chaque critère renseigné s'ajoute aux autres (ET logique,
              pas OU) — plus tu ajoutes de filtres, plus la liste se réduit. Laisse un champ vide pour ne pas
              filtrer dessus.
            </p>
          </InfoPanel>

          <Card>
            <div className="flex flex-wrap items-end gap-4">
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
                <Input type="date" value={startDate} max={endDate} onChange={(e) => setStartDate(e.target.value)} />
              </label>
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
                <Input type="date" value={endDate} min={startDate} max={todayIso()} onChange={(e) => setEndDate(e.target.value)} />
              </label>
              <label className="w-52">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Secteur</span>
                <SearchableSelect
                  value={sectorId}
                  onChange={setSectorId}
                  placeholder="Tous"
                  options={sectorOptions.map(([id, name]) => ({ value: String(id), label: name }))}
                />
              </label>
            </div>

            <div className="mt-4 flex flex-wrap items-end gap-4">
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Signal min</span>
                <Select value={minScore} onChange={(e) => setMinScore(e.target.value)}>
                  <option value="">—</option>
                  {[-2, -1, 0, 1, 2].map((s) => <option key={s} value={s}>{s > 0 ? `+${s}` : s}</option>)}
                </Select>
              </label>
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Signal max</span>
                <Select value={maxScore} onChange={(e) => setMaxScore(e.target.value)}>
                  <option value="">—</option>
                  {[-2, -1, 0, 1, 2].map((s) => <option key={s} value={s}>{s > 0 ? `+${s}` : s}</option>)}
                </Select>
              </label>
              <label className="w-28">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">RSI min</span>
                <Input type="number" min={0} max={100} value={minRsi} onChange={(e) => setMinRsi(e.target.value)} />
              </label>
              <label className="w-28">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">RSI max</span>
                <Input type="number" min={0} max={100} value={maxRsi} onChange={(e) => setMaxRsi(e.target.value)} />
              </label>
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Variation min (%)</span>
                <Input type="number" value={minVariation} onChange={(e) => setMinVariation(e.target.value)} />
              </label>
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Variation max (%)</span>
                <Input type="number" value={maxVariation} onChange={(e) => setMaxVariation(e.target.value)} />
              </label>
              <label className="w-32">
                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Volume min</span>
                <Input type="number" min={0} value={minVolume} onChange={(e) => setMinVolume(e.target.value)} />
              </label>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-4">
              <span className="text-xs font-medium text-gray-700 dark:text-gray-300">Liquidité :</span>
              {LIQUIDITY_LEVELS.map((level) => (
                <label key={level} className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                  <input type="checkbox" checked={liquidity.includes(level)} onChange={() => toggleLiquidity(level)} />
                  {level}
                </label>
              ))}
              {hasActiveFilters && (
                <button
                  type="button"
                  onClick={resetFilters}
                  className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >
                  Réinitialiser les filtres
                </button>
              )}
            </div>

            {startDate > endDate && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}
          </Card>

          {screenerQuery.isLoading && <LoadingState label="Chargement du screener…" />}
          {screenerQuery.error && <ErrorState message={(screenerQuery.error as Error).message} />}

          {screenerQuery.data && rows.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              Aucune entreprise ne correspond à ces critères — élargis les filtres.
            </p>
          )}

          {rows.length > 0 && (
            <Card>
              <div className="mb-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span>{rows.length} entreprise(s) · {selected.length} sélectionnée(s)</span>
                <button
                  type="button"
                  onClick={() => setSelected(selected.length === rows.length ? [] : rows.map((r) => r.company_id))}
                  className="underline hover:text-gray-700 dark:hover:text-gray-200"
                >
                  {selected.length === rows.length ? 'Tout décocher' : 'Tout sélectionner'}
                </button>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3"></th>
                      <th className="pb-2 pr-3">Symbole</th>
                      <th className="pb-2 pr-3">Entreprise</th>
                      <th className="pb-2 pr-3">Secteur</th>
                      <th
                        className="pb-2 pr-3 text-right"
                        title="Rang de l'entreprise au sein de son secteur, par performance de cours sur la période choisie"
                      >
                        Rang secteur
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Performance de cours sur la période choisie">
                        Perf. période
                      </th>
                      <th className="pb-2 pr-3 text-right">Cours</th>
                      <th className="pb-2 pr-3 text-right" title="Variation par rapport à la clôture de la veille">
                        Variation
                      </th>
                      <th className="pb-2 pr-3 text-right">Volume</th>
                      <th className="pb-2 pr-3 text-right">RSI</th>
                      <th className="pb-2 pr-3">Liquidité</th>
                      <th className="pb-2">Signal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((r) => {
                      const variation = r.variation_percent !== null ? Number(r.variation_percent) : null
                      return (
                        <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                          <td className="py-2 pr-3">
                            <input
                              type="checkbox"
                              checked={selected.includes(r.company_id)}
                              onChange={() =>
                                setSelected((prev) =>
                                  prev.includes(r.company_id) ? prev.filter((id) => id !== r.company_id) : [...prev, r.company_id],
                                )
                              }
                            />
                          </td>
                          <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                          <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                          <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.sector ?? '—'}</td>
                          <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                            {r.sector_rank !== null ? `${r.sector_rank}/${r.sector_size}` : '—'}
                          </td>
                          <td
                            className={`py-2 pr-3 text-right tabular-nums ${
                              r.period_performance_percent === null
                                ? 'text-gray-500'
                                : r.period_performance_percent > 0
                                  ? 'text-emerald-600 dark:text-emerald-400'
                                  : r.period_performance_percent < 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-gray-500'
                            }`}
                          >
                            {r.period_performance_percent !== null
                              ? `${r.period_performance_percent > 0 ? '+' : ''}${r.period_performance_percent.toFixed(2)}%`
                              : '—'}
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.close_price, 2)}</td>
                          <td
                            className={`py-2 pr-3 text-right tabular-nums ${
                              variation === null ? 'text-gray-500' : variation > 0 ? 'text-emerald-600 dark:text-emerald-400' : variation < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500'
                            }`}
                          >
                            {variation !== null ? `${variation > 0 ? '+' : ''}${variation.toFixed(2)}%` : '—'}
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.volume)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">{r.rsi_14 !== null ? fmt(r.rsi_14, 1) : '—'}</td>
                          <td className="py-2 pr-3">
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${liquidityBadgeClass(r.liquidity)}`}>
                              {r.liquidity ?? '—'}
                            </span>
                          </td>
                          <td className="py-2">
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${signalBadgeClass(r.score)}`}>
                              {r.label}
                            </span>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              <ChartAiAnalysis
                chartType="screener"
                parameters={{ ...filters, selected_company_ids: [...selected].sort((a, b) => a - b) }}
                data={rows.filter((r) => selected.length === 0 || selected.includes(r.company_id))}
                companyIdsForReports={selected}
                disabled={rows.length === 0}
              />
            </Card>
          )}
        </>
      )}

      {activeTab === 'composite_score' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Un score de synthèse 0-100 par entreprise, combinant en une
              seule note pondérée les 6 dimensions déjà présentes séparément ailleurs dans l'application :
              Fondamental (30%), Technique (25%), Momentum (15%), Liquidité (10%), Secteur (10%), Marché (10%).
              Ce n'est PAS un signal d'achat/vente ni une prédiction — un outil de tri rapide parmi de nombreuses
              entreprises, pas une recommandation.
            </p>
            <p>
              <strong>Fondamental.</strong> Moyenne de plusieurs ratios déjà extraits par IA des rapports financiers
              (PER, rendement du dividende, ROE, croissance du chiffre d'affaires, marge nette — page Fondamentaux),
              chacun ramené sur une échelle 0-100 par un barème simple. Vide si aucun rapport financier n'a encore
              été traité avec succès pour cette entreprise.
            </p>
            <p>
              <strong>Technique.</strong> Le même score composite -2/+2 que les pages Cotations/Screener (RSI/MACD/
              tendance/Bollinger), simplement ramené sur une échelle 0-100.
            </p>
            <p>
              <strong>Momentum / Marché.</strong> Momentum = performance de cours sur la période sélectionnée.
              Marché = cette même performance comparée à celle de BRVM-COMPOSITE sur la même période
              (surperformance ou sous-performance relative) — deux mesures liées mais différentes : un titre peut
              avoir un bon Momentum (il monte) tout en ayant un Marché faible (il monte moins vite que l'ensemble
              du marché).
            </p>
            <p>
              <strong>Couverture.</strong> Une entreprise sans fondamentaux disponibles n'est pas pénalisée par un
              zéro silencieux sur cette dimension — le score est recalculé sur les seules dimensions disponibles.
              La colonne Couverture indique quelle part des 100% de pondération a réellement pu être utilisée :
              compare toujours ce chiffre avant de comparer deux scores entre eux, une couverture de 45% est moins
              fiable qu'une couverture de 90%.
            </p>
          </InfoPanel>

          <Card>
            <div className="flex flex-wrap items-end gap-4">
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
                <Input type="date" value={startDate} max={endDate} onChange={(e) => setStartDate(e.target.value)} />
              </label>
              <label className="w-40">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
                <Input type="date" value={endDate} min={startDate} max={todayIso()} onChange={(e) => setEndDate(e.target.value)} />
              </label>
              <label className="w-52">
                <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Secteur</span>
                <SearchableSelect
                  value={sectorId}
                  onChange={setSectorId}
                  placeholder="Tous"
                  options={sectorOptions.map(([id, name]) => ({ value: String(id), label: name }))}
                />
              </label>
            </div>
            {startDate > endDate && (
              <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}
          </Card>

          {compositeQuery.isLoading && <LoadingState label="Calcul du score composite…" />}
          {compositeQuery.error && <ErrorState message={(compositeQuery.error as Error).message} />}

          {compositeRows.length > 0 && (
            <Card>
              <div className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                {compositeRows.length} entreprise(s) · pondérations : Fondamental 30% · Technique 25% · Momentum 15%
                · Liquidité 10% · Secteur 10% · Marché 10%
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3">Symbole</th>
                      <th className="pb-2 pr-3">Entreprise</th>
                      <th className="pb-2 pr-3">Secteur</th>
                      <th className="pb-2 pr-3 text-right" title="Score de synthèse 0-100, PAS un signal d'achat/vente">Score</th>
                      <th className="pb-2 pr-3 text-right" title="Part des 100% de pondération réellement calculée">Couverture</th>
                      <th className="pb-2 pr-3 text-right">Fondam.</th>
                      <th className="pb-2 pr-3 text-right">Techn.</th>
                      <th className="pb-2 pr-3 text-right">Moment.</th>
                      <th className="pb-2 pr-3 text-right">Liquid.</th>
                      <th className="pb-2 pr-3 text-right">Secteur</th>
                      <th className="pb-2 text-right">Marché</th>
                    </tr>
                  </thead>
                  <tbody>
                    {compositeRows.map((r) => (
                      <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                        <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                        <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                        <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.sector ?? '—'}</td>
                        <td className={`py-2 pr-3 text-right tabular-nums text-base font-bold ${compositeScoreClass(r.composite_score)}`}>
                          {r.composite_score !== null ? r.composite_score.toFixed(0) : '—'}
                        </td>
                        <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{r.coverage_percent}%</td>
                        <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{fmtSub(r.sub_scores.fundamental)}</td>
                        <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{fmtSub(r.sub_scores.technical)}</td>
                        <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{fmtSub(r.sub_scores.momentum)}</td>
                        <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{fmtSub(r.sub_scores.liquidity)}</td>
                        <td className="py-2 pr-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{fmtSub(r.sub_scores.sector)}</td>
                        <td className="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{fmtSub(r.sub_scores.market)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <ChartAiAnalysis
                chartType="composite_score"
                parameters={compositeParams}
                data={compositeRows}
                disabled={compositeRows.length === 0}
              />
            </Card>
          )}
        </>
      )}
    </div>
  )
}
