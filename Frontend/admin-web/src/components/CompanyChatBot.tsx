import { useEffect, useId, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { ApiError, callApi } from '../lib/apiClient'
import type { CompanyChatMessage } from '../lib/types'
import { AI_MODELS, AI_PROVIDERS, type AiProvider } from '../lib/aiModels'
import { Button, ErrorState, Input, Select } from './ui'
import { TrashIcon } from './icons'

const markdownComponents = {
  table: (props: React.ComponentProps<'table'>) => <table className="mb-3 w-full border-collapse text-xs" {...props} />,
  th: (props: React.ComponentProps<'th'>) => <th className="border border-gray-200 bg-gray-50 px-2 py-1 text-left font-semibold dark:border-gray-700 dark:bg-gray-800" {...props} />,
  td: (props: React.ComponentProps<'td'>) => <td className="border border-gray-200 px-2 py-1 dark:border-gray-700" {...props} />,
  h1: (props: React.ComponentProps<'h1'>) => <h1 className="mb-2 mt-3 text-base font-bold" {...props} />,
  h2: (props: React.ComponentProps<'h2'>) => <h2 className="mb-2 mt-3 text-sm font-semibold" {...props} />,
  h3: (props: React.ComponentProps<'h3'>) => <h3 className="mb-1 mt-2 text-sm font-semibold" {...props} />,
  p: (props: React.ComponentProps<'p'>) => <p className="mb-2 text-sm leading-relaxed" {...props} />,
  ul: (props: React.ComponentProps<'ul'>) => <ul className="mb-2 list-disc space-y-1 pl-5 text-sm" {...props} />,
  ol: (props: React.ComponentProps<'ol'>) => <ol className="mb-2 list-decimal space-y-1 pl-5 text-sm" {...props} />,
  strong: (props: React.ComponentProps<'strong'>) => <strong className="font-semibold" {...props} />,
}

/**
 * Chat bot IA du tableau de bord entreprise (api_company_chat.php) — pose
 * une question en langage libre, l'assistant répond en s'appuyant sur les
 * données déjà agrégées du tableau de bord (`dashboardData`, même payload
 * que l'"Analyse IA globale") ET sa propre recherche internet (grounding
 * natif du fournisseur choisi, voir class/AiChatClientInterface.php côté
 * backend) — conversation persistée en continu par entreprise, pas de
 * notion de "session" qui se perd au changement d'onglet.
 */
export function CompanyChatBot({
  companyId,
  company,
  dashboardData,
}: {
  companyId: number
  company: { symbol: string; name: string; sector: string | null }
  dashboardData: unknown
}) {
  const queryClient = useQueryClient()
  const [provider, setProvider] = useState<AiProvider>('gemini')
  const [model, setModel] = useState('')
  const [draft, setDraft] = useState('')
  const [pendingUserMessage, setPendingUserMessage] = useState<string | null>(null)
  const modelListId = useId()
  const scrollRef = useRef<HTMLDivElement>(null)

  const queryKey = ['company-chat', companyId]

  const listQuery = useQuery({
    queryKey,
    queryFn: () => callApi<CompanyChatMessage[]>('api_company_chat.php', 'list', { company_id: companyId }),
  })

  const sendMutation = useMutation({
    mutationFn: (message: string) =>
      callApi<CompanyChatMessage>('api_company_chat.php', 'send', {
        company_id: companyId,
        company,
        dashboard_data: dashboardData,
        message,
        provider,
        model: model || undefined,
      }),
    onSettled: () => {
      setPendingUserMessage(null)
      queryClient.invalidateQueries({ queryKey })
    },
  })

  const clearMutation = useMutation({
    mutationFn: () => callApi<null>('api_company_chat.php', 'clear', { company_id: companyId }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  const messages = listQuery.data ?? []

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages.length, pendingUserMessage, sendMutation.isPending])

  const handleSend = () => {
    const message = draft.trim()
    if (!message || sendMutation.isPending) return
    setPendingUserMessage(message)
    setDraft('')
    sendMutation.mutate(message)
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <div className="w-40">
          <Select
            value={provider}
            onChange={(e) => {
              setProvider(e.target.value as AiProvider)
              setModel('')
            }}
          >
            {AI_PROVIDERS.map((p) => (
              <option key={p.value} value={p.value}>{p.label}</option>
            ))}
          </Select>
        </div>
        <div className="w-56">
          <Input
            list={modelListId}
            value={model}
            onChange={(e) => setModel(e.target.value)}
            placeholder="Modèle (défaut du fournisseur)"
          />
          <datalist id={modelListId}>
            {AI_MODELS[provider].map((m) => (
              <option key={m.value} value={m.value}>{m.label}</option>
            ))}
          </datalist>
        </div>
        <div className="ml-auto">
          <Button
            variant="secondary"
            disabled={messages.length === 0 || clearMutation.isPending}
            onClick={() => {
              if (window.confirm('Effacer toute la conversation avec cette entreprise ? Cette action est irréversible.')) {
                clearMutation.mutate()
              }
            }}
          >
            <span className="flex items-center gap-2">
              <TrashIcon /> Effacer la conversation
            </span>
          </Button>
        </div>
      </div>

      <div
        ref={scrollRef}
        className="flex max-h-[60vh] min-h-[300px] flex-col gap-3 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-950"
      >
        {listQuery.isLoading && <p className="text-sm text-gray-500 dark:text-gray-400">Chargement de la conversation…</p>}

        {!listQuery.isLoading && messages.length === 0 && !pendingUserMessage && (
          <div className="m-auto max-w-md text-center text-sm text-gray-500 dark:text-gray-400">
            <p className="mb-2 font-medium text-gray-700 dark:text-gray-300">Pose une question sur {company.name} ({company.symbol}).</p>
            <p>
              L'assistant s'appuie sur toutes les données déjà chargées dans ce tableau de bord (cours, indicateurs, fondamentaux,
              opérations sur titres, backtest, risque, classement...) et peut compléter par une recherche internet — réponses
              détaillées et vulgarisées, pensées pour quelqu'un qui débute en bourse.
            </p>
          </div>
        )}

        {messages.map((m) => (
          <ChatBubble key={m.id} message={m} />
        ))}

        {pendingUserMessage && (
          <ChatBubble
            message={{
              id: -1,
              company_id: companyId,
              role: 'user',
              content: pendingUserMessage,
              provider: null,
              model: null,
              sources: [],
              created_at: '',
            }}
          />
        )}

        {sendMutation.isPending && (
          <div className="flex items-center gap-2 self-start rounded-lg bg-white px-3 py-2 text-xs text-gray-500 shadow-sm dark:bg-gray-900 dark:text-gray-400">
            <span className="h-2 w-2 animate-pulse rounded-full bg-gray-900 dark:bg-gray-100 dark:text-gray-900" />
            L'assistant réfléchit et peut faire une recherche internet…
          </div>
        )}

        {sendMutation.isError && (
          <ErrorState message={sendMutation.error instanceof ApiError ? sendMutation.error.message : "Échec de l'envoi du message"} />
        )}
      </div>

      <div className="flex items-end gap-2">
        <textarea
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault()
              handleSend()
            }
          }}
          placeholder="Écris ta question ici (Entrée pour envoyer, Maj+Entrée pour une nouvelle ligne)…"
          rows={2}
          className="w-full resize-none rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-gray-900 dark:focus:border-gray-300 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
        />
        <Button onClick={handleSend} disabled={!draft.trim() || sendMutation.isPending}>
          Envoyer
        </Button>
      </div>
    </div>
  )
}

function hostnameOf(url: string): string {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

function ChatBubble({ message }: { message: CompanyChatMessage }) {
  const isUser = message.role === 'user'

  return (
    <div className={`flex flex-col ${isUser ? 'items-end' : 'items-start'}`}>
      <div
        className={`max-w-[85%] rounded-lg px-3 py-2 shadow-sm ${
          isUser
            ? 'bg-gray-900 dark:bg-gray-100 dark:text-gray-900 text-white'
            : 'bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100'
        }`}
      >
        {isUser ? (
          <p className="whitespace-pre-wrap text-sm">{message.content}</p>
        ) : (
          <ReactMarkdown remarkPlugins={[remarkGfm]} components={markdownComponents}>
            {message.content}
          </ReactMarkdown>
        )}
      </div>

      {!isUser && message.sources.length > 0 && (
        <div className="mt-1 max-w-[85%] text-xs text-gray-500 dark:text-gray-400">
          <span className="font-medium">Sources : </span>
          {message.sources.map((s, i) => (
            <span key={s.url}>
              {i > 0 && ', '}
              <a href={s.url} target="_blank" rel="noreferrer" className="underline hover:text-black dark:hover:text-white">
                {s.title || hostnameOf(s.url)}
              </a>
            </span>
          ))}
        </div>
      )}

      {!isUser && (message.provider || message.model) && (
        <div className="mt-1 text-[11px] text-gray-400 dark:text-gray-600">
          {message.provider}{message.model ? `/${message.model}` : ''}
        </div>
      )}
    </div>
  )
}
