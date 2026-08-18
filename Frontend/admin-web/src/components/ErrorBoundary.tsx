import { Component, type ErrorInfo, type ReactNode } from 'react'

/**
 * Filet de sécurité contre les « écrans blancs ».
 *
 * Une exception levée pendant le rendu démonte tout l'arbre React : la page
 * devient entièrement vide et le seul indice se trouve dans la console du
 * navigateur, que l'on n'a presque jamais sous les yeux au moment où le
 * problème survient. Cette limite intercepte l'exception et affiche à la
 * place le message, le composant fautif et la pile — de quoi diagnostiquer
 * sans rien réinstrumenter.
 *
 * Volontairement une classe : React ne propose pas d'équivalent en composant
 * de fonction (`componentDidCatch` n'a pas de hook).
 */
interface Props {
  children: ReactNode
  /** Ce qui a échoué, pour situer l'erreur quand plusieurs limites coexistent. */
  label?: string
}

interface State {
  error: Error | null
  componentStack: string | null
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null, componentStack: null }

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // On conserve la trace console : elle reste la source la plus complète
    // (sourcemaps, valeurs inspectables) quand la console est ouverte.
    console.error('[ErrorBoundary]', this.props.label ?? '', error, info)
    this.setState({ componentStack: info.componentStack ?? null })
  }

  private reset = () => this.setState({ error: null, componentStack: null })

  render() {
    const { error, componentStack } = this.state
    if (!error) return this.props.children

    return (
      <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        <h2 className="text-base font-semibold">
          Cette section n'a pas pu s'afficher{this.props.label ? ` — ${this.props.label}` : ''}
        </h2>
        <p className="mt-1 font-mono text-sm">
          {error.name}: {error.message}
        </p>
        <p className="mt-2 text-red-700 dark:text-red-300">
          Le reste de l'application continue de fonctionner. Si l'erreur persiste après rechargement, le détail
          ci-dessous indique le composant en cause.
        </p>

        <div className="mt-3 flex gap-2">
          <button
            type="button"
            onClick={this.reset}
            className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            Réessayer
          </button>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium hover:bg-red-100 dark:border-red-800 dark:hover:bg-red-900"
          >
            Recharger la page
          </button>
        </div>

        <details className="mt-3">
          <summary className="cursor-pointer select-none font-medium">Détail technique</summary>
          <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded bg-red-100 p-2 text-xs dark:bg-red-900/40">
            {error.stack ?? String(error)}
            {componentStack ? `\n\nArbre de composants :${componentStack}` : ''}
          </pre>
        </details>
      </div>
    )
  }
}
