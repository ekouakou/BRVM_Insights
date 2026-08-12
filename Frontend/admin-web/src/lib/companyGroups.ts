import { useCallback, useEffect, useState } from 'react'
import type { Company } from './types'

/**
 * Sélection d'entreprises persistée (localStorage) : la liste cochée sur un
 * écran de comparaison reste active après navigation ou rechargement de la
 * page. Une clé distincte par écran — les écrans Comparaison et Statistiques
 * gardent chacun leur propre sélection.
 */
export function usePersistedSelection(storageKey: string) {
  const [selected, setSelected] = useState<number[]>(() => {
    try {
      const raw = JSON.parse(localStorage.getItem(storageKey) ?? '[]')
      return Array.isArray(raw) ? raw.filter((v): v is number => typeof v === 'number') : []
    } catch {
      return []
    }
  })

  useEffect(() => {
    localStorage.setItem(storageKey, JSON.stringify(selected))
  }, [storageKey, selected])

  return [selected, setSelected] as const
}

export interface CompanySector {
  name: string
  members: Company[]
}

/**
 * Regroupe les entreprises par secteur d'activité réel (companies.sector_id,
 * peuplé par migrations/007_seed_company_sectors.sql) — remplace un ancien
 * regroupement par similarité de nom, qui ratait par exemple des banques
 * dont le nom ne partage pas de préfixe commun (ex: "BICI" vs "BANQUE
 * INTERNATIONALE ... DU BENIN", pourtant deux banques). Les entreprises
 * sans secteur renseigné (sector_name absent) sont isolées dans
 * `unclassified` plutôt que de fausser un secteur existant.
 */
export function groupCompaniesBySector(companies: Company[]): { sectors: CompanySector[]; unclassified: Company[] } {
  const groups = new Map<string, Company[]>()
  const unclassified: Company[] = []

  for (const c of companies) {
    if (!c.sector_name) {
      unclassified.push(c)
      continue
    }
    const existing = groups.get(c.sector_name)
    if (existing) {
      existing.push(c)
    } else {
      groups.set(c.sector_name, [c])
    }
  }

  const sectors: CompanySector[] = []
  for (const [name, members] of groups) {
    members.sort((a, b) => a.symbol.localeCompare(b.symbol))
    sectors.push({ name, members })
  }

  sectors.sort((a, b) => b.members.length - a.members.length || a.name.localeCompare(b.name))
  unclassified.sort((a, b) => a.symbol.localeCompare(b.symbol))

  return { sectors, unclassified }
}

// Palette catégorielle pilotée par variables CSS (index.css) : s'adapte au
// thème clair/sombre. 12 crans avant de boucler.
const PALETTE = [
  'var(--chart-2)', 'var(--chart-5)', 'var(--chart-4)', 'var(--chart-6)',
  'var(--chart-3)', 'var(--chart-7)', 'var(--chart-1)', 'var(--chart-8)',
  'var(--chart-9)', 'var(--chart-10)', 'var(--chart-11)', 'var(--chart-12)',
]

const COLOR_OVERRIDES_KEY = 'brvm_company_colors'

function loadColorOverrides(): Record<number, string> {
  try {
    return JSON.parse(localStorage.getItem(COLOR_OVERRIDES_KEY) ?? '{}')
  } catch {
    return {}
  }
}

/**
 * Résout une couleur CSS var(--chart-N) en hex effectif du thème courant —
 * nécessaire pour <input type="color"> qui n'accepte que du #rrggbb.
 */
export function resolveCssColor(color: string): string {
  if (color.startsWith('var(')) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(color.slice(4, -1)).trim()
    return value || '#171717'
  }
  return color
}

/**
 * Couleur par id d'entreprise, stable quelle que soit la sélection —
 * usage hors écrans de comparaison. Une couleur personnalisée par
 * l'utilisateur (voir useCompanyColors) prime partout.
 */
export function colorForCompany(companyId: number): string {
  const override = loadColorOverrides()[companyId]
  return override ?? PALETTE[companyId % PALETTE.length]
}

/**
 * Couleurs d'un écran de comparaison : assignées par POSITION dans la
 * sélection courante (jamais deux entreprises comparées avec la même
 * couleur tant que la sélection reste ≤ 12), avec personnalisation par
 * entreprise persistée en localStorage — une couleur choisie à la main
 * prime sur l'assignation automatique, dans toute l'application.
 */
export function useCompanyColors() {
  const [overrides, setOverrides] = useState<Record<number, string>>(loadColorOverrides)

  const setColor = useCallback((companyId: number, color: string | null) => {
    setOverrides((prev) => {
      const next = { ...prev }
      if (color === null) {
        delete next[companyId]
      } else {
        next[companyId] = color
      }
      localStorage.setItem(COLOR_OVERRIDES_KEY, JSON.stringify(next))
      return next
    })
  }, [])

  const colorFor = useCallback(
    (companyId: number, selection?: number[]) => {
      const override = overrides[companyId]
      if (override) return override
      if (selection) {
        const idx = selection.indexOf(companyId)
        if (idx >= 0) return PALETTE[idx % PALETTE.length]
      }
      return PALETTE[companyId % PALETTE.length]
    },
    [overrides],
  )

  return { colorFor, setColor, overrides }
}
