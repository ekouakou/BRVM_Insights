import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart, Bar, Cell, XAxis, YAxis, Tooltip, CartesianGrid, ResponsiveContainer, LabelList, ReferenceLine } from 'recharts'
import { callApi } from '../lib/apiClient'
import type { PerformanceRankingRow, VolumeRankingRow } from '../lib/types'
import { Card, ErrorState, InfoPanel, Input, LoadingState, Tabs } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function fmt(n: string | number | null | undefined, digits = 0): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(days: number) {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

/** Médaille pour le podium (top 3), sinon le rang brut. */
function RankBadge({ rank }: { rank: number }) {
  const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : null
  if (medal) return <span title={`Rang ${rank}`}>{medal}</span>
  return <span className="text-gray-400 dark:text-gray-500">{rank}</span>
}

function SelectionHeader({
  selected,
  total,
  onToggleAll,
}: {
  selected: number
  total: number
  onToggleAll: () => void
}) {
  return (
    <div className="mb-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
      <span>{selected} sélectionnée(s)</span>
      <button type="button" onClick={onToggleAll} className="underline hover:text-gray-700 dark:hover:text-gray-200">
        {selected === total ? 'Tout décocher' : 'Tout sélectionner'}
      </button>
    </div>
  )
}

export function VolumeRanking() {
  const [activeTab, setActiveTab] = useState<'volume' | 'performance'>('volume')
  const [startDate, setStartDate] = useState(daysAgoIso(30))
  const [endDate, setEndDate] = useState(todayIso())
  const [volumeSelected, setVolumeSelected] = useState<number[]>([])
  const [perfSelected, setPerfSelected] = useState<number[]>([])

  const periodEnabled = !!startDate && !!endDate && startDate <= endDate

  // Somme du volume (et du turnover) de chaque entreprise active sur toute
  // la plage de dates — pas seulement sa dernière séance connue. Une
  // entreprise sans transaction sur la période apparaît quand même, à 0
  // (voir api_quotes.php::getVolumeRanking, LEFT JOIN).
  const volumeQuery = useQuery({
    queryKey: ['volume-ranking', startDate, endDate],
    queryFn: () =>
      callApi<VolumeRankingRow[]>('api_quotes.php', 'volume_ranking', {
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: periodEnabled,
  })

  // Performance de cours = (dernière clôture - première clôture) / première
  // clôture sur la période, pour toutes les entreprises actives. Contrairement
  // au volume, une entreprise sans cotation sur la période n'a pas de valeur
  // "neutre" à afficher (voir api_quotes.php::getPerformanceRanking).
  const perfQuery = useQuery({
    queryKey: ['performance-ranking', startDate, endDate],
    queryFn: () =>
      callApi<PerformanceRankingRow[]>('api_quotes.php', 'performance_ranking', {
        start_date: startDate,
        end_date: endDate,
      }),
    enabled: periodEnabled,
  })

  const volumeRows = volumeQuery.data ?? []
  const volumeChartData = volumeRows.map((r) => ({ symbol: r.symbol, volume: r.total_volume }))

  const perfRows = perfQuery.data ?? []
  const perfChartData = perfRows
    .filter((r) => r.variation_percent !== null)
    .map((r) => ({ symbol: r.symbol, variation: r.variation_percent as number }))

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Classements</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Entreprises actives classées par volume d'actions échangées et par performance de cours sur une même
          période choisie ci-dessous.
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-end gap-4">
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de début</span>
            <Input type="date" value={startDate} max={endDate} onChange={(e) => setStartDate(e.target.value)} />
          </label>
          <label className="w-44">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date de fin</span>
            <Input type="date" value={endDate} min={startDate} max={todayIso()} onChange={(e) => setEndDate(e.target.value)} />
          </label>
        </div>
        {startDate > endDate && (
          <p className="mt-2 text-xs text-red-600 dark:text-red-400">La date de début doit précéder la date de fin.</p>
        )}
      </Card>

      <Tabs
        tabs={[
          { id: 'volume', label: 'Par volume' },
          { id: 'performance', label: 'Par performance' },
        ]}
        active={activeTab}
        onChange={(id) => setActiveTab(id as 'volume' | 'performance')}
      />

      {activeTab === 'volume' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Il répond à la question « quelles entreprises s'échangent le
              plus (ou le moins) en bourse en ce moment ? ». Le volume mesure l'activité de négociation — combien
              d'actions ont changé de main — pas la direction du cours. Une entreprise très échangée peut très bien
              terminer la période au même prix qu'au départ.
            </p>
            <p>
              <strong>Comment lire le tableau.</strong> Les entreprises sont classées du volume total le plus élevé
              au plus faible, calculé en additionnant le volume de chaque séance de la période choisie ci-dessus.
              Une entreprise sans aucune transaction sur la période apparaît quand même, avec un volume de 0 — cela
              signale un titre illiquide plutôt qu'une erreur de données.
            </p>
            <p>
              <strong>Colonnes « Actions en circulation / Volume vendu / Rotation / Restant ».</strong> « Actions en
              circulation » est le nombre total d'actions déjà émises par l'entreprise (donnée BRVM) — ce n'est PAS
              un stock disponible à l'achat : la BRVM ne publie pas de carnet d'ordres public, donc il n'existe pas
              de véritable notion de volume « à vendre ». « Rotation » (volume vendu ÷ actions en circulation)
              permet de comparer des entreprises de tailles très différentes sur une base commune. « Restant » est
              une estimation basse (actions en circulation − volume vendu, jamais négative) : comme un même titre
              peut être retradé plusieurs fois dans la période, le vrai nombre d'actions jamais touchées peut être
              plus élevé que ce chiffre.
            </p>
            <p>
              <strong>À ne pas en conclure.</strong> Un volume élevé n'est ni un signal d'achat ni de vente — il
              indique seulement de l'activité. Croise-le avec l'onglet « Par performance » pour voir si cette
              activité s'est accompagnée d'un mouvement de prix.
            </p>
          </InfoPanel>

          {volumeQuery.isLoading && <LoadingState label="Chargement du classement par volume…" />}
          {volumeQuery.error && <ErrorState message={(volumeQuery.error as Error).message} />}

          {volumeChartData.length > 0 && (
            <Card title="Volumes par entreprise">
              <ResponsiveContainer width="100%" height={Math.max(240, volumeChartData.length * 24)}>
                <BarChart data={volumeChartData} layout="vertical" margin={{ left: 16, right: 24 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-gray-200 dark:stroke-gray-800" />
                  <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => fmt(v)} />
                  <YAxis type="category" dataKey="symbol" tick={{ fontSize: 11 }} width={60} />
                  <Tooltip formatter={(value) => [fmt(value as number), 'Volume']} />
                  <Bar dataKey="volume" fill="#4f46e5" radius={[0, 4, 4, 0]}>
                    <LabelList dataKey="volume" position="right" formatter={(v) => fmt(v as number)} className="fill-gray-500 text-[10px] dark:fill-gray-400" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </Card>
          )}

          {volumeRows.length > 0 && (
            <Card>
              <SelectionHeader
                selected={volumeSelected.length}
                total={volumeRows.length}
                onToggleAll={() => setVolumeSelected(volumeSelected.length === volumeRows.length ? [] : volumeRows.map((r) => r.company_id))}
              />
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3"></th>
                      <th className="pb-2 pr-3">Rang</th>
                      <th className="pb-2 pr-3">Symbole</th>
                      <th className="pb-2 pr-3">Entreprise</th>
                      <th className="pb-2 pr-3">Secteur</th>
                      <th
                        className="pb-2 pr-3 text-right"
                        title="Actions en circulation (total émis, donnée BRVM scrapée ponctuellement) — PAS un stock « à vendre », il n'existe pas de carnet d'ordres public à la BRVM"
                      >
                        Actions en circulation
                      </th>
                      <th className="pb-2 pr-3 text-right" title="« Vendu » — somme du volume quotidien échangé sur la période">
                        Volume vendu
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Volume vendu ÷ actions en circulation">
                        Rotation
                      </th>
                      <th
                        className="pb-2 pr-3 text-right"
                        title="« Restant » — estimation basse (actions en circulation - volume vendu, plafonnée à 0). Les mêmes actions peuvent être retradées plusieurs fois : ne signifie pas que ces actions précises sont restées immobiles."
                      >
                        Restant (est.)
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Somme du turnover (cours × volume) quotidien sur la période">
                        Turnover total
                      </th>
                      <th className="pb-2" title="Nombre de séances avec une cotation enregistrée sur la période">
                        Jours cotés
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {volumeRows.map((r, i) => {
                      const rank = i + 1
                      return (
                        <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                          <td className="py-2 pr-3">
                            <input
                              type="checkbox"
                              checked={volumeSelected.includes(r.company_id)}
                              onChange={() =>
                                setVolumeSelected((prev) =>
                                  prev.includes(r.company_id) ? prev.filter((id) => id !== r.company_id) : [...prev, r.company_id],
                                )
                              }
                            />
                          </td>
                          <td className="py-2 pr-3">
                            <RankBadge rank={rank} />
                          </td>
                          <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                          <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                          <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.sector ?? '—'}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.shares_outstanding)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums font-medium">{fmt(r.total_volume)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">
                            {r.turnover_percent !== null ? `${fmt(r.turnover_percent, 2)}%` : '—'}
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">
                            {r.shares_remaining_estimate !== null ? (
                              <span title={r.fully_rotated ? 'Capital retradé au moins 1× en moyenne sur la période — estimation à 0' : undefined}>
                                {fmt(r.shares_remaining_estimate)}
                              </span>
                            ) : (
                              '—'
                            )}
                          </td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.total_turnover)}</td>
                          <td className="py-2 text-gray-500 dark:text-gray-400">{r.trading_days}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              <ChartAiAnalysis
                chartType="volume_ranking"
                parameters={{ start_date: startDate, end_date: endDate, selected_company_ids: [...volumeSelected].sort((a, b) => a - b) }}
                data={volumeRows.filter((r) => volumeSelected.length === 0 || volumeSelected.includes(r.company_id))}
                companyIdsForReports={volumeSelected}
              />
            </Card>
          )}
        </>
      )}

      {activeTab === 'performance' && (
        <>
          <InfoPanel>
            <p>
              <strong>À quoi sert cet onglet.</strong> Il répond à la question « quelles entreprises ont le mieux
              (ou le moins bien) performé en bourse sur la période ? », en se basant uniquement sur le mouvement du
              cours — pas sur les résultats financiers de l'entreprise. Pour croiser ce mouvement de cours avec les
              résultats financiers, coche des entreprises puis active « Inclure les résultats financiers » dans le
              bloc d'analyse IA plus bas : l'IA s'appuiera alors aussi sur leur rapport déjà traité le plus récent
              (texte reformaté en Markdown en priorité). Laisse la case décochée pour une analyse basée
              uniquement sur le cours.
            </p>
            <p>
              <strong>Comment lire le tableau.</strong> La performance = (dernier cours de clôture de la période −
              premier cours de clôture de la période) ÷ premier cours, en %. Les entreprises sont classées de la
              plus forte hausse à la plus forte baisse. Une entreprise sans aucune cotation sur la période affiche
              « — » plutôt que 0% : afficher 0% laisserait croire à tort à un cours stable et observé, alors qu'il
              n'y a simplement pas de donnée.
            </p>
            <p>
              <strong>Le graphique</strong> est colorié en vert (performance positive) ou rouge (négative), avec une
              ligne de référence à 0% — les entreprises sans donnée n'y figurent pas (impossible de tracer une barre
              sans les deux cours nécessaires au calcul).
            </p>
            <p>
              <strong>Limites à connaître.</strong> Ce calcul ne tient pas compte des dividendes versés pendant la
              période (rendement total sous-estimé pour les entreprises qui en distribuent), ni de la trajectoire
              entre les deux dates : un titre qui a fortement oscillé avant de revenir à son point de départ affiche
              une performance proche de 0%, comme un titre resté parfaitement stable — pour distinguer les deux, il
              faut regarder l'onglet « Par volume » (activité) ou consulter directement son historique de cours
              (page Cotations).
            </p>
          </InfoPanel>

          {perfQuery.isLoading && <LoadingState label="Chargement du classement par performance…" />}
          {perfQuery.error && <ErrorState message={(perfQuery.error as Error).message} />}

          {perfChartData.length > 0 && (
            <Card title="Performance par entreprise">
              <ResponsiveContainer width="100%" height={Math.max(240, perfChartData.length * 24)}>
                <BarChart data={perfChartData} layout="vertical" margin={{ left: 16, right: 24 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-gray-200 dark:stroke-gray-800" />
                  <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => `${fmt(v, 1)}%`} />
                  <YAxis type="category" dataKey="symbol" tick={{ fontSize: 11 }} width={60} />
                  <ReferenceLine x={0} stroke="#9ca3af" />
                  <Tooltip formatter={(value) => [`${fmt(value as number, 2)}%`, 'Variation']} />
                  <Bar dataKey="variation" radius={[0, 4, 4, 0]}>
                    {perfChartData.map((d) => (
                      <Cell key={d.symbol} fill={d.variation >= 0 ? '#059669' : '#dc2626'} />
                    ))}
                    <LabelList dataKey="variation" position="right" formatter={(v) => `${fmt(v as number, 1)}%`} className="fill-gray-500 text-[10px] dark:fill-gray-400" />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </Card>
          )}

          {perfRows.length > 0 && (
            <Card>
              <SelectionHeader
                selected={perfSelected.length}
                total={perfRows.length}
                onToggleAll={() => setPerfSelected(perfSelected.length === perfRows.length ? [] : perfRows.map((r) => r.company_id))}
              />
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      <th className="pb-2 pr-3"></th>
                      <th className="pb-2 pr-3">Rang</th>
                      <th className="pb-2 pr-3">Symbole</th>
                      <th className="pb-2 pr-3">Entreprise</th>
                      <th className="pb-2 pr-3">Secteur</th>
                      <th className="pb-2 pr-3 text-right" title="Cours de clôture à la première séance de la période">
                        1ère clôture
                      </th>
                      <th className="pb-2 pr-3 text-right" title="Cours de clôture à la dernière séance de la période">
                        Dernière clôture
                      </th>
                      <th className="pb-2 pr-3 text-right" title="(dernière clôture - 1ère clôture) / 1ère clôture">
                        Performance
                      </th>
                      <th className="pb-2" title="Nombre de séances avec une cotation enregistrée sur la période">
                        Jours cotés
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {perfRows.map((r, i) => {
                      const rank = i + 1
                      const v = r.variation_percent
                      return (
                        <tr key={r.company_id} className="border-t border-gray-100 dark:border-gray-800">
                          <td className="py-2 pr-3">
                            <input
                              type="checkbox"
                              checked={perfSelected.includes(r.company_id)}
                              onChange={() =>
                                setPerfSelected((prev) =>
                                  prev.includes(r.company_id) ? prev.filter((id) => id !== r.company_id) : [...prev, r.company_id],
                                )
                              }
                            />
                          </td>
                          <td className="py-2 pr-3">
                            <RankBadge rank={rank} />
                          </td>
                          <td className="py-2 pr-3 font-medium">{r.symbol}</td>
                          <td className="py-2 pr-3 text-gray-600 dark:text-gray-300">{r.name}</td>
                          <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{r.sector ?? '—'}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.first_close_price, 2)}</td>
                          <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.last_close_price, 2)}</td>
                          <td
                            className={`py-2 pr-3 text-right tabular-nums font-medium ${
                              v === null ? 'text-gray-500' : v > 0 ? 'text-emerald-600 dark:text-emerald-400' : v < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500'
                            }`}
                          >
                            {v !== null ? `${v > 0 ? '+' : ''}${fmt(v, 2)}%` : '—'}
                          </td>
                          <td className="py-2 text-gray-500 dark:text-gray-400">{r.trading_days}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              <ChartAiAnalysis
                chartType="performance_ranking"
                parameters={{ start_date: startDate, end_date: endDate, selected_company_ids: [...perfSelected].sort((a, b) => a - b) }}
                data={perfRows.filter((r) => perfSelected.length === 0 || perfSelected.includes(r.company_id))}
                companyIdsForReports={perfSelected}
              />
            </Card>
          )}
        </>
      )}
    </div>
  )
}
