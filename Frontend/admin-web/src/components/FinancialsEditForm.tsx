import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { callApi } from '../lib/apiClient'
import { Button, ErrorState, Modal, Select } from './ui'

interface FieldDef {
  key: string
  label: string
  type: 'number' | 'text' | 'date'
  suffix?: string
}

interface FieldGroup {
  title: string
  fields: FieldDef[]
}

/**
 * Champs de key_financials/valuation_assessment ouverts à la saisie
 * manuelle — mêmes clés et regroupement thématique que le schéma IA (voir
 * ReportAnalysisService::buildPrompt() et FundamentalsDetailPanel.tsx),
 * moins les champs narratifs (executive_summary, swot, risks...) qui n'ont
 * pas leur place dans un formulaire de chiffres.
 */
const KEY_FINANCIALS_GROUPS: FieldGroup[] = [
  {
    title: 'Compte de résultat',
    fields: [
      { key: 'currency', label: 'Devise', type: 'text' },
      { key: 'period_end_date', label: 'Date de clôture', type: 'date' },
      { key: 'revenue', label: "Chiffre d'affaires", type: 'number' },
      { key: 'revenue_prior_year', label: 'CA exercice précédent', type: 'number' },
      { key: 'revenue_growth_percent', label: 'Croissance CA', type: 'number', suffix: '%' },
      { key: 'gross_profit', label: 'Marge brute', type: 'number' },
      { key: 'gross_margin_percent', label: 'Marge brute', type: 'number', suffix: '%' },
      { key: 'operating_income', label: 'Résultat opérationnel', type: 'number' },
      { key: 'operating_margin_percent', label: 'Marge opérationnelle', type: 'number', suffix: '%' },
      { key: 'ebitda', label: 'EBITDA', type: 'number' },
      { key: 'ebitda_margin_percent', label: 'Marge EBITDA', type: 'number', suffix: '%' },
      { key: 'net_income', label: 'Résultat net', type: 'number' },
      { key: 'net_income_prior_year', label: 'Résultat net (N-1)', type: 'number' },
      { key: 'net_margin_percent', label: 'Marge nette', type: 'number', suffix: '%' },
    ],
  },
  {
    title: 'Rentabilité',
    fields: [
      { key: 'roe_percent', label: 'ROE', type: 'number', suffix: '%' },
      { key: 'roa_percent', label: 'ROA', type: 'number', suffix: '%' },
    ],
  },
  {
    title: 'Structure financière',
    fields: [
      { key: 'total_debt', label: 'Dette totale', type: 'number' },
      { key: 'total_equity', label: 'Capitaux propres', type: 'number' },
      { key: 'total_assets', label: 'Total actif', type: 'number' },
      { key: 'debt_to_equity', label: 'Dette/CP', type: 'number' },
      { key: 'interest_expense', label: "Charges d'intérêt", type: 'number' },
      { key: 'interest_coverage_ratio', label: 'Couverture des intérêts', type: 'number' },
      { key: 'debt_to_ebitda', label: 'Dette/EBITDA', type: 'number' },
    ],
  },
  {
    title: 'Liquidité / BFR',
    fields: [
      { key: 'current_assets', label: 'Actifs courants', type: 'number' },
      { key: 'current_liabilities', label: 'Passifs courants', type: 'number' },
      { key: 'current_ratio', label: 'Current Ratio', type: 'number' },
      { key: 'quick_ratio', label: 'Quick Ratio', type: 'number' },
      { key: 'working_capital', label: 'BFR', type: 'number' },
      { key: 'cash_position', label: 'Trésorerie', type: 'number' },
      { key: 'receivable_days', label: 'Délai clients', type: 'number', suffix: 'j' },
      { key: 'payable_days', label: 'Délai fournisseurs', type: 'number', suffix: 'j' },
      { key: 'inventory_days', label: 'Délai stocks', type: 'number', suffix: 'j' },
    ],
  },
  {
    title: 'Cash-flow',
    fields: [
      { key: 'operating_cash_flow', label: "Flux de trésorerie d'exploitation", type: 'number' },
      { key: 'capex', label: 'Capex', type: 'number' },
      { key: 'free_cash_flow', label: 'Free cash flow', type: 'number' },
    ],
  },
  {
    title: 'Dividende',
    fields: [{ key: 'dividend_per_share', label: 'Dividende par action', type: 'number' }],
  },
]

const VALUATION_GROUP: FieldGroup = {
  title: 'Valorisation',
  fields: [
    { key: 'shares_outstanding', label: 'Actions en circulation', type: 'number' },
    { key: 'eps', label: 'BPA', type: 'number' },
    { key: 'book_value_per_share', label: 'Valeur comptable/action', type: 'number' },
    { key: 'pe_ratio', label: 'PER', type: 'number' },
    { key: 'price_to_book', label: 'P/B', type: 'number' },
    { key: 'ev_to_ebitda', label: 'EV/EBITDA', type: 'number' },
    { key: 'dividend_yield_percent', label: 'Rendement dividende', type: 'number', suffix: '%' },
    { key: 'payout_ratio_percent', label: 'Taux de distribution', type: 'number', suffix: '%' },
    { key: 'free_float_percent', label: 'Flottant', type: 'number', suffix: '%' },
  ],
}

const VERDICT_OPTIONS = ['sous-coté', 'surcoté', 'correctement valorisé', 'indéterminable']

const KF_TEXT_KEYS = new Set(['currency', 'period_end_date'])

function toFormValues(source: Record<string, unknown> | null | undefined): Record<string, string> {
  const values: Record<string, string> = {}
  if (!source) return values
  for (const [key, value] of Object.entries(source)) {
    values[key] = value === null || value === undefined ? '' : String(value)
  }
  return values
}

function buildPayload(values: Record<string, string>, textKeys: Set<string>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}
  for (const [key, raw] of Object.entries(values)) {
    const trimmed = raw.trim()
    if (trimmed === '') {
      payload[key] = null
      continue
    }
    if (textKeys.has(key)) {
      payload[key] = trimmed
      continue
    }
    const n = Number(trimmed)
    payload[key] = Number.isNaN(n) ? null : n
  }
  return payload
}

function FieldInput({
  field,
  value,
  onChange,
}: {
  field: FieldDef
  value: string
  onChange: (v: string) => void
}) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-xs text-gray-500 dark:text-gray-400">
        {field.label}
        {field.suffix ? ` (${field.suffix})` : ''}
      </span>
      <input
        type={field.type === 'number' ? 'number' : field.type}
        step="any"
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-gray-300"
      />
    </label>
  )
}

/**
 * Formulaire complet de saisie/correction des données financières d'un
 * rapport (key_financials + valuation_assessment) — sert deux usages :
 * ajuster des chiffres déjà extraits par IA (initialKeyFinancials/
 * initialValuationAssessment pré-remplis depuis l'analyse existante) ou
 * saisir un rapport jamais analysé par IA (formulaire vide). Dans les deux
 * cas, enregistre une entrée distincte (provider='manuel') sans jamais
 * modifier l'analyse IA d'origine — voir
 * ReportAnalysisService::saveManualFinancials().
 */
export function FinancialsEditForm({
  reportId,
  companyId,
  reportTitle,
  initialKeyFinancials,
  initialValuationAssessment,
  onClose,
  onSaved,
}: {
  /** null = aucun rapport existant : un rapport synthétique est créé à la volée (voir companyId ci-dessous). */
  reportId: number | null
  /** Requis quand reportId est null. Ignoré sinon (le rapport existant porte déjà son company_id). */
  companyId?: number
  /** Affiché tel quel si reportId est fourni ; sinon pré-remplit le champ "Titre" éditable (un titre par défaut est généré côté serveur si laissé vide). */
  reportTitle?: string
  initialKeyFinancials?: Record<string, unknown> | null
  initialValuationAssessment?: Record<string, unknown> | null
  onClose: () => void
  onSaved?: () => void
}) {
  const [keyFinancials, setKeyFinancials] = useState<Record<string, string>>(() => toFormValues(initialKeyFinancials))
  const [valuation, setValuation] = useState<Record<string, string>>(() => toFormValues(initialValuationAssessment))
  const [newReportTitle, setNewReportTitle] = useState(reportTitle ?? '')

  const isNew = reportId === null

  const saveMutation = useMutation({
    mutationFn: () =>
      callApi('api_report_analysis.php', 'save_manual', {
        report_id: reportId ?? undefined,
        company_id: isNew ? companyId : undefined,
        report_title: isNew ? newReportTitle : undefined,
        key_financials: buildPayload(keyFinancials, KF_TEXT_KEYS),
        valuation_assessment: buildPayload(valuation, new Set(['verdict', 'rationale'])),
      }),
    onSuccess: () => {
      onSaved?.()
      onClose()
    },
  })

  return (
    <Modal title={isNew ? 'Nouvelle saisie manuelle' : `Données financières — ${reportTitle}`} onClose={onClose}>
      <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
        Saisie manuelle : enregistrée comme sa propre entrée (« analysé par manuel »), sans jamais modifier une
        analyse IA existante — les deux restent consultables séparément via le filtre « IA » de la fiche Fondamentaux.
        Laisse un champ vide s'il n'est pas connu, plutôt que d'y mettre 0.
      </p>

      <div className="flex flex-col gap-5">
        {isNew && (
          <label className="flex flex-col gap-1">
            <span className="text-xs text-gray-500 dark:text-gray-400">Titre (facultatif — sinon dérivé de la date de clôture ci-dessous)</span>
            <input
              type="text"
              value={newReportTitle}
              onChange={(e) => setNewReportTitle(e.target.value)}
              placeholder="ex. Rapport annuel — Exercice 2025"
              className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-gray-300"
            />
          </label>
        )}
        {KEY_FINANCIALS_GROUPS.map((group) => (
          <div key={group.title}>
            <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{group.title}</h4>
            <div className="grid grid-cols-2 gap-3">
              {group.fields.map((field) => (
                <FieldInput
                  key={field.key}
                  field={field}
                  value={keyFinancials[field.key] ?? ''}
                  onChange={(v) => setKeyFinancials((prev) => ({ ...prev, [field.key]: v }))}
                />
              ))}
            </div>
          </div>
        ))}

        <div>
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{VALUATION_GROUP.title}</h4>
          <div className="grid grid-cols-2 gap-3">
            {VALUATION_GROUP.fields.map((field) => (
              <FieldInput
                key={field.key}
                field={field}
                value={valuation[field.key] ?? ''}
                onChange={(v) => setValuation((prev) => ({ ...prev, [field.key]: v }))}
              />
            ))}
          </div>
        </div>

        <div>
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Verdict IA (facultatif)</h4>
          <div className="flex flex-col gap-3">
            <label className="flex flex-col gap-1">
              <span className="text-xs text-gray-500 dark:text-gray-400">Verdict</span>
              <Select value={valuation.verdict ?? ''} onChange={(e) => setValuation((prev) => ({ ...prev, verdict: e.target.value }))}>
                <option value="">— Aucun —</option>
                {VERDICT_OPTIONS.map((v) => (
                  <option key={v} value={v}>
                    {v}
                  </option>
                ))}
              </Select>
            </label>
            <label className="flex flex-col gap-1">
              <span className="text-xs text-gray-500 dark:text-gray-400">Justification</span>
              <textarea
                rows={3}
                value={valuation.rationale ?? ''}
                onChange={(e) => setValuation((prev) => ({ ...prev, rationale: e.target.value }))}
                className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-gray-900 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-gray-300"
              />
            </label>
          </div>
        </div>
      </div>

      {saveMutation.error && <ErrorState message={(saveMutation.error as Error).message} />}

      <div className="mt-5 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Annuler
        </Button>
        <Button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </Button>
      </div>
    </Modal>
  )
}
