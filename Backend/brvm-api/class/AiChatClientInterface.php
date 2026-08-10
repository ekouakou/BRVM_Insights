<?php
/**
 * Contrat pour les fournisseurs IA capables de répondre en conversation
 * libre (texte Markdown, pas de schéma JSON forcé) avec recherche internet
 * native activée côté fournisseur — utilisé par CompanyChatService (chat bot
 * du tableau de bord entreprise). Séparé de AiClientInterface (analyse
 * structurée en JSON) car le contrat de sortie est différent : ici on veut
 * une réponse rédigée librement, pas un objet validé par schéma.
 */
interface AiChatClientInterface {
    /**
     * $history : tours précédents de la conversation, dans l'ordre
     * chronologique, chaque élément ['role' => 'user'|'assistant', 'content' => string].
     * $userMessage : le nouveau message de l'utilisateur, pas encore dans $history.
     *
     * $options : 'timeout_seconds', 'max_tokens' (Anthropic uniquement) —
     * mêmes clés que AiClientInterface::generateContent().
     *
     * @return array{success:bool, text?:string, sources?:array<array{title:?string,url:string}>, error?:string}
     */
    public function generateChatReply(string $systemPrompt, array $history, string $userMessage, ?string $model = null, ?array $options = null): array;
}
