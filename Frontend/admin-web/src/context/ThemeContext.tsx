import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'

export type ThemePreference = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'brvm_admin_theme'

/**
 * Applique la préférence au document : la variante Tailwind `dark:` est
 * pilotée par la classe `dark` sur <html> (voir @custom-variant dans
 * index.css). En mode "system", on suit prefers-color-scheme en direct.
 * color-scheme est aussi posé pour que les contrôles natifs (scrollbars,
 * inputs date/number) suivent le thème.
 */
function applyTheme(preference: ThemePreference) {
  const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches
  const dark = preference === 'dark' || (preference === 'system' && systemDark)
  document.documentElement.classList.toggle('dark', dark)
  document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
}

const ThemeContext = createContext<{ theme: ThemePreference; setTheme: (t: ThemePreference) => void }>({
  theme: 'system',
  setTheme: () => {},
})

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<ThemePreference>(() => {
    const stored = localStorage.getItem(STORAGE_KEY)
    return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system'
  })

  const setTheme = useCallback((t: ThemePreference) => {
    setThemeState(t)
    localStorage.setItem(STORAGE_KEY, t)
  }, [])

  useEffect(() => {
    applyTheme(theme)
    if (theme !== 'system') return
    // En mode système : suivre les changements d'OS en direct.
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const listener = () => applyTheme('system')
    media.addEventListener('change', listener)
    return () => media.removeEventListener('change', listener)
  }, [theme])

  return <ThemeContext.Provider value={{ theme, setTheme }}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  return useContext(ThemeContext)
}
