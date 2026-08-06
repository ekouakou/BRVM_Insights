import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import type { CompanyQuoteRow, MarketOverview } from '../lib/types'
import { Card, ErrorState, LoadingState, StatTile, Table } from '../components/ui'
import { ChartAiAnalysis } from '../components/ChartAiAnalysis'

function fmt(n: string | number | null | undefined, digits = 2): string {
  if (n === null || n === undefined) return '—'
  const num = typeof n === 'string' ? parseFloat(n) : n
  if (Number.isNaN(num)) return '—'
  return num.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

function VariationBadge({ value }: { value: string | number }) {
  const num = typeof value === 'string' ? parseFloat(value) : value
  const tone = num > 0 ? 'text-emerald-600 dark:text-emerald-400' : num < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500'
  return <span className={tone}>{num > 0 ? '+' : ''}{fmt(num)}%</span>
}

// Une entreprise peut apparaître dans plusieurs des 3 tableaux à la fois
// (ex: forte hausse ET fort volume) — sélection partagée par symbole,
// dédoublonnée naturellement par ce choix de clé.
function toggleSymbol(prev: string[], symbol: string): string[] {
  return prev.includes(symbol) ? prev.filter((s) => s !== symbol) : [...prev, symbol]
}

export function Overview() {
  const [selectedSymbols, setSelectedSymbols] = useState<string[]>([])

  const { data, isLoading, error } = useQuery({
    queryKey: ['market-overview'],
    queryFn: () => callApi<MarketOverview>('api_market.php', 'overview'),
  })

  if (isLoading) return <LoadingState label="Chargement de la vue d’ensemble…" />
  if (error) return <ErrorState message={(error as Error).message} />
  if (!data) return null

  const s = data.statistics

  function filterRows(rows: CompanyQuoteRow[]): CompanyQuoteRow[] {
    return selectedSymbols.length === 0 ? rows : rows.filter((r) => selectedSymbols.includes(r.symbol))
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-xl font-semibold">Vue d’ensemble du marché</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">Séance du {data.date}</p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <StatTile label="Entreprises cotées" value={s.total_companies} />
        <StatTile label="Hausses" value={s.gainers_count} tone="positive" />
        <StatTile label="Baisses" value={s.losers_count} tone="negative" />
        <StatTile label="Inchangées" value={s.unchanged_count} />
        <StatTile label="Variation moyenne" value={`${fmt(s.avg_variation)}%`} />
        <StatTile label="Volume total" value={fmt(s.total_volume, 0)} />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card title="Plus fortes hausses">
          <Table headers={['', 'Symbole', 'Cours', 'Var.']}>
            {data.top_gainers.map((r) => (
              <tr key={r.symbol}>
                <td className="px-3 py-2">
                  <input
                    type="checkbox"
                    checked={selectedSymbols.includes(r.symbol)}
                    onChange={() => setSelectedSymbols((prev) => toggleSymbol(prev, r.symbol))}
                  />
                </td>
                <td className="px-3 py-2 font-medium">{r.symbol}</td>
                <td className="px-3 py-2">{fmt(r.close_price)}</td>
                <td className="px-3 py-2"><VariationBadge value={r.variation_percent} /></td>
              </tr>
            ))}
          </Table>
        </Card>

        <Card title="Plus fortes baisses">
          <Table headers={['', 'Symbole', 'Cours', 'Var.']}>
            {data.top_losers.map((r) => (
              <tr key={r.symbol}>
                <td className="px-3 py-2">
                  <input
                    type="checkbox"
                    checked={selectedSymbols.includes(r.symbol)}
                    onChange={() => setSelectedSymbols((prev) => toggleSymbol(prev, r.symbol))}
                  />
                </td>
                <td className="px-3 py-2 font-medium">{r.symbol}</td>
                <td className="px-3 py-2">{fmt(r.close_price)}</td>
                <td className="px-3 py-2"><VariationBadge value={r.variation_percent} /></td>
              </tr>
            ))}
          </Table>
        </Card>

        <Card title="Volumes les plus élevés">
          <Table headers={['', 'Symbole', 'Volume', 'Var.']}>
            {data.volume_leaders.map((r) => (
              <tr key={r.symbol}>
                <td className="px-3 py-2">
                  <input
                    type="checkbox"
                    checked={selectedSymbols.includes(r.symbol)}
                    onChange={() => setSelectedSymbols((prev) => toggleSymbol(prev, r.symbol))}
                  />
                </td>
                <td className="px-3 py-2 font-medium">{r.symbol}</td>
                <td className="px-3 py-2">{fmt(r.volume, 0)}</td>
                <td className="px-3 py-2"><VariationBadge value={r.variation_percent} /></td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>

      <p className="text-xs text-gray-500 dark:text-gray-400">
        {selectedSymbols.length > 0
          ? `${selectedSymbols.length} entreprise(s) cochée(s) dans les tableaux ci-dessus — l'analyse IA portera sur ce sous-ensemble (statistiques globales toujours incluses).`
          : "Coche des lignes dans les tableaux ci-dessus pour restreindre l'analyse IA à un sous-ensemble (par défaut : résumé de toute la séance)."}
        {selectedSymbols.length > 0 && (
          <button type="button" onClick={() => setSelectedSymbols([])} className="ml-2 text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400">
            Tout décocher
          </button>
        )}
      </p>
      <ChartAiAnalysis
        chartType="market_summary"
        parameters={{ date: data.date, selected_symbols: [...selectedSymbols].sort() }}
        data={{
          ...data,
          top_gainers: filterRows(data.top_gainers),
          top_losers: filterRows(data.top_losers),
          volume_leaders: filterRows(data.volume_leaders),
        }}
      />
    </div>
  )
}
