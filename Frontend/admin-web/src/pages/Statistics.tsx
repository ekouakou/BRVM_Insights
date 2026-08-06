import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
  ReferenceLine,
} from 'recharts'
import { callApi } from '../lib/apiClient'
import type { Company, CorrelationResult, RelativeStrengthSeries, RiskAdjustedResult, RiskMetricsRow, TotalVariationSeries } from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState, Tabs } from '../components/ui'
import { colorForCompany, groupCompaniesBySector } from '../lib/companyGroups'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(n: number) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

type DisplayMode = 'total' | 'split' | 'both'

type StatsTab = 'total_variation' | 'correlation' | 'risk_adjusted' | 'relative_strength' | 'risk_metrics_advanced'

export function Statistics() {
  const [activeTab, setActiveTab] = useState<StatsTab>('total_variation')
  const [selected, setSelected] = useState<number[]>([])
  const [startDate, setStartDate] = useState(daysAgoIso(30))
  const [endDate, setEndDate] = useState(todayIso())
  const [displayMode, setDisplayMode] = useState<DisplayMode>('total')

  // Sous-sélection propre à chaque tableau, indépendante de `selected` (qui
  // pilote aussi les 3 autres graphes de cette page) — permet d'analyser un
  // sous-ensemble des entreprises déjà comparées sans changer la sélection
  // globale. Toujours un sous-ensemble de `selected` en pratique (ces
  // tableaux n'affichent que les entreprises cochées ci-dessus).
  const [correlationSelected, setCorrelationSelected] = useState<string[]>([])
  const [riskAdjustedSelected, setRiskAdjustedSelected] = useState<number[]>([])
  const [riskMetricsSelected, setRiskMetricsSelected] = useState<number[]>([])

  const companiesQuery = useQuery({
    queryKey: ['companies-list'],
    queryFn: () => callApi<Company[]>('api_companies.php', 'list', { per_page: 200, active: 1 }),
  })

  const totalVariationQuery = useQuery({
    queryKey: ['total-variation', selected, startDate, endDate],
    queryFn: () =>
      callApi<TotalVariationSeries[]>('api_quotes.php', 'total_variation', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  const correlationQuery = useQuery({
    queryKey: ['correlation', selected, startDate, endDate],
    queryFn: () =>
      callApi<CorrelationResult>('api_quotes.php', 'correlation', {
        company_ids: selected,
        end_date: endDate,
        days: 90,
      }),
    enabled: selected.length >= 2 && startDate <= endDate,
  })

  const riskAdjustedQuery = useQuery({
    queryKey: ['risk-adjusted', selected, startDate, endDate],
    queryFn: () =>
      callApi<RiskAdjustedResult[]>('api_quotes.php', 'risk_adjusted', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  const relativeStrengthQuery = useQuery({
    queryKey: ['relative-strength', selected, startDate, endDate],
    queryFn: () =>
      callApi<RelativeStrengthSeries[]>('api_quotes.php', 'relative_strength', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  // Sharpe/Sortino réels, Max Drawdown, Calmar, VaR/CVaR, skewness/
  // kurtosis, bêta — voir TODO_ANALYSES.md, points 17-22. Distinct de
  // `risk_adjusted` ci-dessus (ratio simplifié déjà existant).
  const riskMetricsQuery = useQuery({
    queryKey: ['risk-metrics-advanced', selected, startDate, endDate],
    queryFn: () =>
      callApi<RiskMetricsRow[]>('api_risk_metrics.php', 'compute', {
        company_ids: selected,
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: selected.length > 0 && startDate <= endDate,
  })

  const companies = companiesQuery.data ?? []
  const { sectors, unclassified } = useMemo(() => groupCompaniesBySector(companies), [companies])
  const series = totalVariationQuery.data ?? []

  function toggleSelected(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  function toggleCorrelationRow(symbol: string) {
    setCorrelationSelected((prev) => (prev.includes(symbol) ? prev.filter((s) => s !== symbol) : [...prev, symbol]))
  }
  function toggleRiskAdjustedRow(id: number) {
    setRiskAdjustedSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }
  function toggleRiskMetricsRow(id: number) {
    setRiskMetricsSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  // Un point par jour (agrégat quotidien par construction, pas de vue
  // intrajournalière ici) — une colonne par entreprise et par courbe
  // affichée (total / hausses / baisses selon le mode choisi). Les baisses
  // sont stockées en négatif pour que leur aire se dessine sous la ligne
  // zéro (convention hausse en vert au-dessus / baisse en rouge en-dessous).
  const chartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of series) {
      for (const point of serie.data) {
        const row = byDate.get(point.date) ?? { date: point.date }
        if (displayMode === 'total' || displayMode === 'both') {
          row[`${serie.symbol} · total`] = Number(point.total_variation_percent)
        }
        if (displayMode === 'split' || displayMode === 'both') {
          row[`${serie.symbol} · hausses`] = Number(point.total_gain_percent)
          row[`${serie.symbol} · baisses`] = -Number(point.total_loss_percent)
        }
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [series, displayMode])

  // Couleur par entreprise partout (colorForCompany, comme pour le reste de
  // l'app) — le vert/rouge universel rendait la comparaison entre
  // entreprises impossible dès qu'on en sélectionnait plusieurs. Simples
  // lignes, sans remplissage : les baisses restent en négatif (sous la
  // ligne zéro) avec un trait en pointillé, les hausses au-dessus en trait
  // continu — direction + style de trait, même couleur = même entreprise.
  const lines = useMemo(() => {
    const result: { key: string; color: string; width: number; dasharray?: string }[] = []
    for (const serie of series) {
      const color = colorForCompany(serie.company_id)
      if (displayMode === 'total' || displayMode === 'both') {
        result.push({ key: `${serie.symbol} · total`, color, width: displayMode === 'both' ? 2.5 : 2 })
      }
      if (displayMode === 'split' || displayMode === 'both') {
        result.push({ key: `${serie.symbol} · hausses`, color, width: 1.5 })
        result.push({ key: `${serie.symbol} · baisses`, color, width: 1.5, dasharray: '4 2' })
      }
    }
    return result
  }, [series, displayMode])

  const relativeStrengthSeries = relativeStrengthQuery.data ?? []
  const relativeStrengthChartData = useMemo(() => {
    const byDate = new Map<string, Record<string, number | string>>()
    for (const serie of relativeStrengthSeries) {
      for (const point of serie.data) {
        const row = byDate.get(point.date) ?? { date: point.date }
        if (point.relative_strength !== null) {
          row[serie.symbol] = point.relative_strength
        }
        // Même valeur d'indice pour toutes les entreprises à une date donnée
        // (renvoyée en double par série côté backend) — affichée une seule
        // fois ici comme ligne de référence, pour que "vs BRVM-COMPOSITE"
        // (texte de l'onglet) corresponde à une valeur visible sur le
        // graphe et pas seulement à un concept mentionné dans le texte.
        if (point.index_variation_percent !== null) {
          row['BRVM-COMPOSITE'] = point.index_variation_percent
        }
        byDate.set(point.date, row)
      }
    }
    return Array.from(byDate.values()).sort((a, b) => String(a.date).localeCompare(String(b.date)))
  }, [relativeStrengthSeries])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Statistiques</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Sélectionne des entreprises et une période, puis choisis une vue ci-dessous.
        </p>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div className="max-h-64 overflow-y-auto rounded-md border border-gray-200 p-3 dark:border-gray-800">
            {sectors.map((sector) => (
              <div key={sector.name} className="mb-3 last:mb-0">
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  {sector.name}
                </div>
                <div className="flex flex-wrap gap-2">
                  {sector.members.map((c) => (
                    <label
                      key={c.company_id}
                      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                        selected.includes(c.company_id)
                          ? 'text-white'
                          : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                      }`}
                      style={
                        selected.includes(c.company_id)
                          ? { backgroundColor: colorForCompany(c.company_id), borderColor: colorForCompany(c.company_id) }
                          : undefined
                      }
                    >
                      <input
                        type="checkbox"
                        className="hidden"
                        checked={selected.includes(c.company_id)}
                        onChange={() => toggleSelected(c.company_id)}
                      />
                      {c.symbol}
                    </label>
                  ))}
                </div>
              </div>
            ))}

            {unclassified.length > 0 && (
              <div>
                <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  Secteur non renseigné
                </div>
                <div className="flex flex-wrap gap-2">
                  {unclassified.map((c) => (
                    <label
                      key={c.company_id}
                      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium ${
                        selected.includes(c.company_id)
                          ? 'text-white'
                          : 'border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300'
                      }`}
                      style={
                        selected.includes(c.company_id)
                          ? { backgroundColor: colorForCompany(c.company_id), borderColor: colorForCompany(c.company_id) }
                          : undefined
                      }
                    >
                      <input
                        type="checkbox"
                        className="hidden"
                        checked={selected.includes(c.company_id)}
                        onChange={() => toggleSelected(c.company_id)}
                      />
                      {c.symbol}
                    </label>
                  ))}
                </div>
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-end gap-4">
            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
              <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
            </label>

            <label className="w-40">
              <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
              <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
            </label>

            {startDate > endDate && (
              <p className="pb-2 text-sm text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
            )}

            <div className="flex flex-col gap-1">
              <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Courbes affichées</span>
              <div className="flex gap-1 rounded-md border border-gray-300 p-0.5 dark:border-gray-700">
                {(
                  [
                    { value: 'total', label: '1 — Total' },
                    { value: 'split', label: '2 — Hausses/baisses' },
                    { value: 'both', label: '3 — Les trois' },
                  ] as { value: DisplayMode; label: string }[]
                ).map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    onClick={() => setDisplayMode(opt.value)}
                    className={`rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                      displayMode === opt.value
                        ? 'bg-indigo-600 text-white'
                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                    }`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            </div>

            {selected.length > 0 && (
              <button
                type="button"
                onClick={() => setSelected([])}
                className="pb-2 text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
              >
                Tout décocher
              </button>
            )}
          </div>
        </div>
      </Card>

      <Tabs
        tabs={[
          { id: 'total_variation', label: 'Variation totale' },
          { id: 'correlation', label: 'Corrélation' },
          { id: 'risk_adjusted', label: 'Performance ajustée au risque' },
          { id: 'relative_strength', label: 'Force relative vs indice' },
          { id: 'risk_metrics_advanced', label: 'Métriques de risque avancées' },
        ]}
        active={activeTab}
        onChange={(id) => setActiveTab(id as StatsTab)}
      />

      {activeTab === 'total_variation' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Il mesure combien un titre « bouge » réellement dans une
              journée — son agitation, indépendamment de là où il termine. Deux entreprises peuvent finir la journée
              au même prix qu'à l'ouverture : l'une parce qu'il ne s'est rien passé, l'autre parce qu'elle a fait
              +8% puis -8% en cours de séance. Ce graphe distingue les deux.
            </p>
            <p>
              <strong>Comment lire le graphe.</strong> Chaque jour, on additionne la valeur absolue des variations
              entre relevés intrajournaliers successifs (toutes les ~10 min en séance). Un titre qui fait +5% puis
              -3% puis +6% puis -4% a une variation <em>nette</em> de +4% (là où il termine) mais une variation{' '}
              <em>totale</em> d'environ 18% (l'agitation cumulée) — c'est cette seconde mesure qui est affichée ici.
            </p>
            <p>
              <strong>Mode « Hausses/baisses ».</strong> Sépare la part de hausses cumulées (au-dessus de 0, trait
              continu) et de baisses cumulées (en-dessous de 0, trait pointillé) au lieu de la variation totale
              seule — utile pour voir si l'agitation d'un titre est plutôt à la hausse ou à la baisse sur la
              journée.
            </p>
            <p>
              <strong>À quoi ça sert concrètement.</strong> Sert de mesure de volatilité/risque pour l'onglet
              « Performance ajustée au risque » ci-après : un rendement identique obtenu avec peu d'agitation est
              généralement préférable à un rendement obtenu en oscillant fortement.
            </p>
          </InfoPanel>

          {selected.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Coche au moins une entreprise pour afficher le graphe.</p>
          )}

          {selected.length > 0 && totalVariationQuery.isLoading && <LoadingState label="Chargement…" />}
          {selected.length > 0 && totalVariationQuery.error && (
            <ErrorState message={(totalVariationQuery.error as Error).message} />
          )}

          {selected.length > 0 && chartData.length > 0 && (
            <Card title="Variation totale cumulée par jour">
              {(displayMode === 'split' || displayMode === 'both') && (
                <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
                  Couleur = entreprise (comme partout ailleurs). Hausses : trait continu au-dessus de 0. Baisses :
                  trait en pointillé en-dessous de 0 (valeurs inversées pour l'affichage).
                </p>
              )}
              <ResponsiveContainer width="100%" height={400}>
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
                  <YAxis domain={['auto', 'auto']} tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
                  <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />
                  <Tooltip formatter={(value, name) => [`${Number(value).toFixed(2)}%`, name]} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  {lines.map((line) => (
                    <Line
                      key={line.key}
                      type="monotone"
                      dataKey={line.key}
                      stroke={line.color}
                      strokeWidth={line.width}
                      strokeDasharray={line.dasharray}
                      dot={false}
                      connectNulls
                    />
                  ))}
                </LineChart>
              </ResponsiveContainer>

              <ChartAiAnalysis
                chartType="total_variation"
                parameters={{ company_ids: [...selected].sort((a, b) => a - b), start_date: startDate, end_date: endDate, display_mode: displayMode }}
                data={series}
              />
            </Card>
          )}

          {selected.length > 0 && totalVariationQuery.data && chartData.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour la sélection sur cette période.</p>
          )}
        </>
      )}

      {activeTab === 'correlation' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Il répond à la question « ces entreprises bougent-elles
              ensemble, ou indépendamment l'une de l'autre ? » — un outil pour juger la diversification d'un
              ensemble de titres plutôt que leur performance individuelle.
            </p>
            <p>
              <strong>Comment lire la matrice.</strong> Chaque case est la corrélation de Pearson entre les
              variations quotidiennes en % de deux entreprises, calculée sur les 90 derniers jours communs aux
              entreprises sélectionnées (jours où les deux ont une cotation). L'échelle va de -1 à +1 : proche de{' '}
              <strong>+1</strong> (vert) = les deux titres montent et descendent presque toujours ensemble ; proche
              de <strong>-1</strong> (rouge) = ils évoluent en sens quasi opposé ; proche de <strong>0</strong> =
              aucune relation linéaire détectable entre les deux.
            </p>
            <p>
              <strong>Pourquoi c'est utile.</strong> Détenir plusieurs titres très corrélés (proche de +1) revient
              en pratique à porter un seul pari plus gros — ils montent et chutent ensemble. Combiner des titres peu
              ou négativement corrélés réduit l'agitation d'ensemble d'un portefeuille, même si aucun des titres
              pris isolément n'est particulièrement stable.
            </p>
            <p>
              <strong>Limite.</strong> Une corrélation passée (90 derniers jours) n'est pas garantie de se maintenir
              — deux titres d'un même secteur peuvent se découpler après une nouvelle spécifique à l'un d'eux.
            </p>
          </InfoPanel>

          {selected.length < 2 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Coche au moins 2 entreprises pour calculer une corrélation.</p>
      )}
      {selected.length >= 2 && correlationQuery.isLoading && <LoadingState label="Calcul de la corrélation…" />}
      {selected.length >= 2 && correlationQuery.error && <ErrorState message={(correlationQuery.error as Error).message} />}

      {correlationQuery.data && correlationQuery.data.symbols.length >= 2 && (
        <Card>
          <p className="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {correlationQuery.data.common_days} jour(s) commun(s) utilisé(s) pour le calcul.
          </p>
          <div className="overflow-x-auto">
            <table className="text-sm">
              <thead>
                <tr>
                  <th className="pb-2 pr-3">
                    <input
                      type="checkbox"
                      checked={correlationSelected.length === correlationQuery.data.symbols.length}
                      onChange={() =>
                        setCorrelationSelected(
                          correlationSelected.length === correlationQuery.data!.symbols.length ? [] : [...correlationQuery.data!.symbols]
                        )
                      }
                    />
                  </th>
                  <th className="pb-2 pr-3"></th>
                  {correlationQuery.data.symbols.map((s) => (
                    <th key={s} className="px-2 pb-2 text-center text-xs font-semibold text-gray-500 dark:text-gray-400">
                      {s}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {correlationQuery.data.symbols.map((rowSymbol) => (
                  <tr key={rowSymbol}>
                    <td className="pr-3">
                      <input
                        type="checkbox"
                        checked={correlationSelected.includes(rowSymbol)}
                        onChange={() => toggleCorrelationRow(rowSymbol)}
                      />
                    </td>
                    <td className="pr-3 text-xs font-semibold text-gray-500 dark:text-gray-400">{rowSymbol}</td>
                    {correlationQuery.data!.symbols.map((colSymbol) => {
                      const value = correlationQuery.data!.matrix[rowSymbol]?.[colSymbol] ?? null
                      const bg =
                        value === null
                          ? 'transparent'
                          : value >= 0
                            ? `rgba(27, 175, 122, ${Math.abs(value) * 0.7})`
                            : `rgba(227, 73, 72, ${Math.abs(value) * 0.7})`
                      return (
                        <td key={colSymbol} className="p-2 text-center text-xs tabular-nums" style={{ backgroundColor: bg }}>
                          {value !== null ? value.toFixed(2) : '—'}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {correlationSelected.length > 0
              ? `${correlationSelected.length} entreprise(s) cochée(s) — l'analyse IA portera sur ce sous-ensemble.`
              : "Coche des entreprises ci-dessus pour restreindre l'analyse IA à un sous-ensemble (par défaut : tout le tableau)."}
          </p>
          <ChartAiAnalysis
            chartType="correlation"
            parameters={{
              company_ids: [...selected].sort((a, b) => a - b),
              end_date: endDate,
              days: 90,
              selected_symbols: correlationSelected.length > 0 ? [...correlationSelected].sort() : [...correlationQuery.data.symbols].sort(),
            }}
            data={
              correlationSelected.length === 0
                ? correlationQuery.data
                : {
                    ...correlationQuery.data,
                    symbols: correlationQuery.data.symbols.filter((s) => correlationSelected.includes(s)),
                  }
            }
          />
        </Card>
      )}
        </>
      )}

      {activeTab === 'risk_adjusted' && (
        <>
      <InfoPanel>
        <p>
          <strong>À quoi sert cet onglet.</strong> Il répond à la question « pour le même gain, quel titre a demandé
          le moins d'agitation ? » — deux entreprises peuvent afficher le même rendement net sur la période, mais
          l'une y est arrivée en ligne quasi droite, l'autre en oscillant fortement. Ce tableau les distingue.
        </p>
        <p>
          <strong>Colonne « Rendement net ».</strong> = (dernier cours de clôture − premier cours de clôture) ÷
          premier cours, sur la période choisie. C'est la performance brute, identique à ce que montre l'onglet
          « Par performance » de l'écran Classements.
        </p>
        <p>
          <strong>Colonne « Volatilité totale ».</strong> Reprend la variation totale cumulée vue dans l'onglet
          précédent (« Variation totale ») — la somme de toute l'agitation intrajournalière sur la période, hausses
          et baisses confondues.
        </p>
        <p>
          <strong>Colonne « Ratio rendement/risque ».</strong> = rendement net ÷ volatilité totale. Plus il est
          élevé, meilleur est le rendement obtenu pour un niveau d'agitation donné. Un ratio négatif signifie une
          perte nette sur la période, quelle que soit l'agitation. Ce ratio ne dit rien sur le futur — il décrit
          seulement comment la période passée s'est déroulée.
        </p>
      </InfoPanel>

      {selected.length > 0 && riskAdjustedQuery.isLoading && <LoadingState label="Calcul en cours…" />}
      {selected.length > 0 && riskAdjustedQuery.error && <ErrorState message={(riskAdjustedQuery.error as Error).message} />}

      {riskAdjustedQuery.data && riskAdjustedQuery.data.length > 0 && (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  <th className="pb-2 pr-3">
                    <input
                      type="checkbox"
                      checked={riskAdjustedSelected.length === riskAdjustedQuery.data.length}
                      onChange={() =>
                        setRiskAdjustedSelected(
                          riskAdjustedSelected.length === riskAdjustedQuery.data!.length ? [] : riskAdjustedQuery.data!.map((r) => r.company_id)
                        )
                      }
                    />
                  </th>
                  <th className="pb-2 pr-3">Symbole</th>
                  <th className="pb-2 pr-3">Entreprise</th>
                  <th className="pb-2 pr-3 text-right" title="(dernier cours - premier cours) / premier cours sur la période">
                    Rendement net
                  </th>
                  <th
                    className="pb-2 pr-3 text-right"
                    title="Somme de la variation totale quotidienne (hausses + baisses cumulées) sur la période — voir graphe ci-dessus"
                  >
                    Volatilité totale
                  </th>
                  <th className="pb-2 text-right" title="Rendement net / volatilité totale — plus haut est meilleur">
                    Ratio rendement/risque
                  </th>
                </tr>
              </thead>
              <tbody>
                {riskAdjustedQuery.data.map((r) => (
                  <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                    <td className="py-2 pr-3">
                      <input
                        type="checkbox"
                        checked={riskAdjustedSelected.includes(r.company_id)}
                        onChange={() => toggleRiskAdjustedRow(r.company_id)}
                      />
                    </td>
                    <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                    <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                    <td
                      className={`py-2 pr-3 text-right tabular-nums ${
                        (r.net_return_percent ?? 0) > 0
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : (r.net_return_percent ?? 0) < 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-gray-500'
                      }`}
                    >
                      {r.net_return_percent !== null ? `${r.net_return_percent > 0 ? '+' : ''}${r.net_return_percent.toFixed(2)}%` : '—'}
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">
                      {r.total_volatility_percent !== null ? `${r.total_volatility_percent.toFixed(2)}%` : '—'}
                    </td>
                    <td className="py-2 text-right font-semibold tabular-nums">
                      {r.risk_adjusted_ratio !== null ? r.risk_adjusted_ratio.toFixed(3) : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {riskAdjustedSelected.length > 0
              ? `${riskAdjustedSelected.length} entreprise(s) cochée(s) — l'analyse IA portera sur ce sous-ensemble.`
              : "Coche des lignes ci-dessus pour restreindre l'analyse IA à un sous-ensemble (par défaut : tout le tableau)."}
          </p>
          <ChartAiAnalysis
            chartType="risk_adjusted"
            parameters={{
              company_ids: [...selected].sort((a, b) => a - b),
              start_date: startDate,
              end_date: endDate,
              selected_company_ids:
                riskAdjustedSelected.length > 0
                  ? [...riskAdjustedSelected].sort((a, b) => a - b)
                  : riskAdjustedQuery.data.map((r) => r.company_id).sort((a, b) => a - b),
            }}
            data={riskAdjustedSelected.length === 0 ? riskAdjustedQuery.data : riskAdjustedQuery.data.filter((r) => riskAdjustedSelected.includes(r.company_id))}
          />
        </Card>
      )}
        </>
      )}

      {activeTab === 'relative_strength' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Il répond à la question « ce titre bat-il le marché, ou
              se contente-t-il de suivre la tendance générale ? » — une hausse de 2% un jour où tout le marché monte
              de 3% est en réalité une <em>sous</em>-performance, ce que le cours brut ne montre pas seul.
            </p>
            <p>
              <strong>Comment lire le graphe.</strong> Chaque jour : variation en % du titre moins variation en % de
              l'indice BRVM-COMPOSITE (qui regroupe l'ensemble des sociétés cotées à la BRVM, pondérées par leur
              capitalisation boursière) le même jour. Au-dessus de la ligne 0% : le titre a fait mieux que le marché
              ce jour-là. En-dessous : il a fait moins bien — même si son propre cours a progressé ce jour-là.
            </p>
            <p>
              <strong>Comment l'utiliser.</strong> Une courbe qui reste régulièrement au-dessus de 0% signale un
              titre qui surperforme durablement le marché (indépendamment de la tendance générale, haussière ou
              baissière) ; une courbe qui oscille fortement autour de 0% signale un titre qui suit globalement le
              marché sans direction propre marquée.
            </p>
            <p>
              <strong>Ligne grise en pointillés.</strong> C'est la variation quotidienne réelle de l'indice
              BRVM-COMPOSITE lui-même (la référence par rapport à laquelle chaque titre est comparé) — affichée
              directement plutôt que laissée implicite, pour voir d'un coup d'œil si un jour donné était plutôt
              porteur ou difficile pour l'ensemble du marché.
            </p>
          </InfoPanel>

      {selected.length > 0 && relativeStrengthQuery.isLoading && <LoadingState label="Chargement…" />}
      {selected.length > 0 && relativeStrengthQuery.error && (
        <ErrorState message={(relativeStrengthQuery.error as Error).message} />
      )}

      {relativeStrengthChartData.length > 0 && (
        <Card title="Surperformance / sous-performance quotidienne vs BRVM-COMPOSITE">
          <ResponsiveContainer width="100%" height={320}>
            <LineChart data={relativeStrengthChartData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-800" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={30} />
              <YAxis tick={{ fontSize: 11 }} width={60} tickFormatter={(v: number) => `${v}%`} />
              <ReferenceLine y={0} stroke="#9ca3af" strokeDasharray="3 3" />
              <Tooltip
                formatter={(value, name) => [`${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`, name]}
              />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              <Line
                type="monotone"
                dataKey="BRVM-COMPOSITE"
                name="BRVM-COMPOSITE (variation quotidienne)"
                stroke="#6b7280"
                strokeDasharray="5 3"
                dot={false}
                strokeWidth={1.5}
                connectNulls
              />
              {relativeStrengthSeries.map((serie) => (
                <Line
                  key={serie.company_id}
                  type="monotone"
                  dataKey={serie.symbol}
                  stroke={colorForCompany(serie.company_id)}
                  dot={false}
                  strokeWidth={2}
                  connectNulls
                />
              ))}
            </LineChart>
          </ResponsiveContainer>

          <ChartAiAnalysis
            chartType="relative_strength"
            parameters={{ company_ids: [...selected].sort((a, b) => a - b), start_date: startDate, end_date: endDate }}
            data={relativeStrengthSeries}
          />
        </Card>
      )}

      {selected.length > 0 && relativeStrengthQuery.data && relativeStrengthChartData.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Aucune donnée pour la sélection sur cette période.</p>
      )}
        </>
      )}

      {activeTab === 'risk_metrics_advanced' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Métriques de risque/performance standard du domaine,
              calculées à partir des rendements journaliers réels de chaque entreprise — plus rigoureuses que le
              ratio simplifié de l'onglet « Performance ajustée au risque » (qui reste utile, mais utilise
              l'agitation intrajournalière cumulée comme mesure de risque plutôt qu'un vrai écart-type de
              rendements, et n'a pas de taux sans risque).
            </p>
            <p>
              <strong>Historique minimum.</strong> En-dessous de 20 rendements journaliers disponibles, toutes ces
              métriques restent vides sauf le rendement net simple — un Sharpe ou une volatilité « annualisée »
              calculés sur 2-3 jours seraient du bruit statistique, pas une information exploitable.
            </p>
            <p>
              <strong>CAGR / Volatilité annualisée / Sharpe / Sortino.</strong> Le CAGR est le rendement composé
              ramené à un rythme annuel. La volatilité annualisée est l'écart-type des rendements journaliers
              multiplié par √252 (jours de bourse par an). Le Sharpe = (rendement annualisé − taux sans risque) ÷
              volatilité annualisée — <strong>taux sans risque fixé à 0% par défaut</strong> (pas de proxy simple
              pour les bons du Trésor UEMOA/BCEAO dans cette application), donc à interpréter comme un ratio
              rendement/risque plutôt qu'un Sharpe au sens strict tant qu'aucun taux réel n'est renseigné. Le
              Sortino est identique mais ne pénalise que l'agitation à la baisse (écart-type des seuls rendements
              négatifs) — un titre qui monte en dents de scie a un meilleur Sortino qu'un Sharpe, à rendement égal.
            </p>
            <p>
              <strong>Max Drawdown / Calmar.</strong> Le Max Drawdown est la perte la plus importante qu'aurait
              subie un investisseur entré au pire moment (au sommet précédent) sur la période — une mesure de
              risque très concrète, indépendante de la volatilité. Le Calmar = CAGR ÷ |Max Drawdown| : plus il est
              élevé, meilleur est le rendement obtenu pour un même niveau de perte maximale subie.
            </p>
            <p>
              <strong>VaR / CVaR 95%.</strong> La VaR historique est le pire rendement journalier observé parmi les
              5% de pires jours de la période (percentile empirique, pas une hypothèse de distribution normale). La
              CVaR est la moyenne de ces pires jours — toujours pire (ou égale) que la VaR, donne une idée de
              l'ampleur moyenne d'une mauvaise journée plutôt que juste son seuil.
            </p>
            <p>
              <strong>Skewness / Kurtosis.</strong> La skewness indique si les mouvements extrêmes du titre penchent
              plutôt vers les gains ou les pertes. La kurtosis (affichée en excès par rapport à une loi normale, 0 =
              normale) indique si les événements extrêmes sont plus fréquents que ne le suggérerait un simple
              écart-type — une kurtosis élevée signale un risque de mouvement brutal sous-estimé par le Sharpe seul.
            </p>
            <p>
              <strong>Bêta.</strong> Sensibilité du titre aux mouvements de l'indice BRVM-COMPOSITE, calculée par
              régression (covariance/variance des rendements quotidiens sur les jours communs) — à distinguer de
              l'onglet « Force relative vs indice » (un écart de rendement jour par jour, pas un coefficient de
              régression). Bêta &gt; 1 : le titre amplifie les mouvements du marché ; &lt; 1 : il les amortit.
            </p>
          </InfoPanel>

          {selected.length > 0 && riskMetricsQuery.isLoading && <LoadingState label="Calcul en cours…" />}
          {selected.length > 0 && riskMetricsQuery.error && (
            <ErrorState message={(riskMetricsQuery.error as Error).message} />
          )}

          {riskMetricsQuery.data && riskMetricsQuery.data.length > 0 && (
            <Card>
              {(() => {
                const benchmarkReturn = riskMetricsQuery.data.find((r) => r.benchmark_return_percent !== null)?.benchmark_return_percent ?? null
                return (
                  <p className="mb-3 text-sm text-gray-600 dark:text-gray-300">
                    <strong>BRVM-COMPOSITE sur la période :</strong>{' '}
                    {benchmarkReturn !== null ? (
                      <span className={benchmarkReturn > 0 ? 'text-emerald-600 dark:text-emerald-400' : benchmarkReturn < 0 ? 'text-red-600 dark:text-red-400' : ''}>
                        {benchmarkReturn > 0 ? '+' : ''}{benchmarkReturn.toFixed(2)}%
                      </span>
                    ) : (
                      '—'
                    )}{' '}
                    <span className="text-gray-400 dark:text-gray-500">(la valeur concrète face à laquelle lire la colonne Bêta ci-dessous)</span>
                  </p>
                )
              })()}
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3">
                        <input
                          type="checkbox"
                          checked={riskMetricsSelected.length === riskMetricsQuery.data.length}
                          onChange={() =>
                            setRiskMetricsSelected(
                              riskMetricsSelected.length === riskMetricsQuery.data!.length
                                ? []
                                : riskMetricsQuery.data!.map((r) => r.company_id)
                            )
                          }
                        />
                      </th>
                      <th className="pb-2 pr-3">Symbole</th>
                      <th className="pb-2 pr-3">Entreprise</th>
                      <th className="pb-2 pr-3 text-right" title="(dernier cours - premier cours) / premier cours, sur la période">
                        Rdt net
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Rendement annualisé composé">
                        CAGR
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Écart-type des rendements journaliers × √252">
                        Vol. ann.
                      </th>
                      <th className="pb-2 pr-3 text-right" title="(rendement annualisé - taux sans risque) / volatilité annualisée — taux sans risque = 0% par défaut">
                        Sharpe
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Comme Sharpe, mais dénominateur = écart-type des seuls rendements négatifs">
                        Sortino
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Perte la plus forte depuis un sommet précédent sur la période">
                        Max DD
                      </th>
                      <th className="pb-2 pr-3 text-right" title="CAGR ÷ |Max Drawdown|">
                        Calmar
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Percentile empirique des pires rendements journaliers (seuil 95%)">
                        VaR 95%
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Moyenne des rendements au-delà du seuil VaR">
                        CVaR 95%
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Asymétrie de la distribution des rendements">
                        Skew.
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Excès de kurtosis (0 = loi normale) — queues de distribution">
                        Kurt.
                      </th>
                      <th className="pb-2 text-right" title="Sensibilité aux mouvements de BRVM-COMPOSITE (régression)">
                        Bêta
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {riskMetricsQuery.data.map((r) => (
                      <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                        <td className="py-2 pr-3">
                          <input
                            type="checkbox"
                            checked={riskMetricsSelected.includes(r.company_id)}
                            onChange={() => toggleRiskMetricsRow(r.company_id)}
                          />
                        </td>
                        <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                        <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                        <td
                          className={`py-2 pr-3 text-right tabular-nums ${
                            (r.net_return_percent ?? 0) > 0
                              ? 'text-emerald-600 dark:text-emerald-400'
                              : (r.net_return_percent ?? 0) < 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-gray-500'
                          }`}
                        >
                          {r.net_return_percent !== null ? `${r.net_return_percent > 0 ? '+' : ''}${r.net_return_percent.toFixed(2)}%` : '—'}
                        </td>
                        {r.insufficient_history ? (
                          <td className="py-2 text-gray-400 dark:text-gray-600" colSpan={11}>
                            Historique insuffisant ({r.daily_returns_count}/20 rendements journaliers requis)
                          </td>
                        ) : (
                          <>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.cagr_percent !== null ? `${r.cagr_percent > 0 ? '+' : ''}${r.cagr_percent.toFixed(2)}%` : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.annualized_volatility_percent !== null ? `${r.annualized_volatility_percent.toFixed(2)}%` : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums font-semibold">
                              {r.sharpe_ratio !== null ? r.sharpe_ratio.toFixed(3) : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.sortino_ratio !== null ? r.sortino_ratio.toFixed(3) : '—'}
                            </td>
                            <td
                              className="py-2 pr-3 text-right tabular-nums text-red-600 dark:text-red-400"
                              title={
                                r.max_drawdown_peak_date && r.max_drawdown_trough_date
                                  ? `Du ${r.max_drawdown_peak_date} au ${r.max_drawdown_trough_date}`
                                  : undefined
                              }
                            >
                              {r.max_drawdown_percent !== null ? `${r.max_drawdown_percent.toFixed(2)}%` : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.calmar_ratio !== null ? r.calmar_ratio.toFixed(3) : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.var_percent !== null ? `${r.var_percent.toFixed(2)}%` : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.cvar_percent !== null ? `${r.cvar_percent.toFixed(2)}%` : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.skewness !== null ? r.skewness.toFixed(2) : '—'}
                            </td>
                            <td className="py-2 pr-3 text-right tabular-nums">
                              {r.excess_kurtosis !== null ? r.excess_kurtosis.toFixed(2) : '—'}
                            </td>
                            <td className="py-2 text-right tabular-nums" title={`Calculé sur ${r.beta_common_days} jour(s) commun(s) avec l'indice`}>
                              {r.beta !== null ? r.beta.toFixed(2) : '—'}
                            </td>
                          </>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {riskMetricsSelected.length > 0
                  ? `${riskMetricsSelected.length} entreprise(s) cochée(s) — l'analyse IA portera sur ce sous-ensemble.`
                  : "Coche des lignes ci-dessus pour restreindre l'analyse IA à un sous-ensemble (par défaut : tout le tableau)."}
              </p>
              <ChartAiAnalysis
                chartType="risk_metrics_advanced"
                parameters={{
                  company_ids: [...selected].sort((a, b) => a - b),
                  start_date: startDate,
                  end_date: endDate,
                  selected_company_ids:
                    riskMetricsSelected.length > 0
                      ? [...riskMetricsSelected].sort((a, b) => a - b)
                      : riskMetricsQuery.data.map((r) => r.company_id).sort((a, b) => a - b),
                }}
                data={riskMetricsSelected.length === 0 ? riskMetricsQuery.data : riskMetricsQuery.data.filter((r) => riskMetricsSelected.includes(r.company_id))}
                companyIdsForReports={riskMetricsSelected}
              />
            </Card>
          )}
        </>
      )}
    </div>
  )
}
