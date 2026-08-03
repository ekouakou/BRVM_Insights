import type { Company } from './types'

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

/** Palette catégorielle fixe (même ordre partout) — assignée par id d'entreprise, stable quelle que soit la sélection. */
const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948']

export function colorForCompany(companyId: number): string {
  return PALETTE[companyId % PALETTE.length]
}
