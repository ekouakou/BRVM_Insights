<?php
/**
 * Envoi de notifications push via l'API OneSignal.
 * Config : ONESIGNAL_CONFIG (config.php), clés fournies via .env
 * (ONESIGNAL_APP_ID / ONESIGNAL_API_KEY).
 *
 * Best-effort volontairement : un échec d'envoi ne doit jamais faire échouer
 * une synchronisation BRVM. Les appelants doivent traiter le retour comme
 * informatif (logs), pas comme une condition de succès/échec.
 */
class OneSignalNotifier {
    private const ENDPOINT = 'https://onesignal.com/api/v1/notifications';

    /** Nombre max de lignes affichées par section (Hausse/Baisse) dans le corps du message */
    private const MAX_LINES_PER_SECTION = 20;

    private $crud;
    private $appId;
    private $apiKey;
    private $enabled;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
        $config = getConfig('onesignal') ?? [];
        $this->appId = $config['app_id'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->enabled = (bool) ($config['enabled'] ?? false);
    }

    public function isConfigured() {
        return $this->enabled && $this->appId !== '' && $this->apiKey !== '';
    }

    /**
     * Envoie une notification à tous les abonnés ("All").
     *
     * @return array{success: bool, http_code: ?int, error: ?string, response: ?array}
     */
    public function send($heading, $content, array $extra = []) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'http_code' => null, 'error' => 'OneSignal non configuré (ONESIGNAL_APP_ID/ONESIGNAL_API_KEY manquants dans .env, ou ONESIGNAL_CONFIG désactivé)', 'response' => null];
        }

        $payload = [
            'app_id' => $this->appId,
            'included_segments' => ['All'],
            'headings' => ['fr' => $heading, 'en' => $heading],
            'contents' => ['fr' => $content, 'en' => $content],
        ];

        if (!empty($extra['url'])) {
            $payload['url'] = $extra['url'];
        }
        if (!empty($extra['data'])) {
            $payload['data'] = $extra['data'];
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Key ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'http_code' => null, 'error' => $curlError, 'response' => null];
        }

        $decoded = json_decode($responseBody, true);
        $success = $httpCode >= 200 && $httpCode < 300 && empty($decoded['errors']);

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'error' => $success ? null : ($decoded['errors'][0] ?? "Réponse HTTP $httpCode inattendue"),
            'response' => $decoded,
        ];
    }

    /**
     * Notification envoyée à chaque synchronisation BRVM (cotations + indices),
     * détaillant les entreprises en hausse et en baisse du jour.
     *
     * $quoteStats / $indexStats : tableaux de stats retournés par
     * BRVMSyncService::syncQuotes()/syncIndices(), ou null si cette étape n'a
     * pas pu s'exécuter du tout (exception avant même de démarrer).
     */
    public function notifySyncCompleted(?array $quoteStats, ?array $indexStats) {
        $failed = ($quoteStats['failed'] ?? 0) + ($indexStats['failed'] ?? 0);
        $totalFailure = $quoteStats === null && $indexStats === null;

        if ($totalFailure) {
            $dateHeure = date('d/m/Y à H:i');
            return $this->send(
                'BRVM Insights — Échec de synchronisation',
                "La synchronisation du $dateHeure a échoué avant de démarrer. Voir sync_logs.",
                ['data' => ['type' => 'brvm_sync', 'synced_at' => date('Y-m-d H:i:s'), 'failed' => $failed]]
            );
        }

        $today = date('Y-m-d');
        $movers = $this->getMovers($today);
        $gainers = $movers['gainers'];
        $losers = $movers['losers'];

        $heading = sprintf(
            'BRVM Insights — %d hausse%s, %d baisse%s',
            count($gainers), count($gainers) > 1 ? 's' : '',
            count($losers), count($losers) > 1 ? 's' : ''
        );
        if ($failed > 0) {
            $heading .= " ($failed erreur" . ($failed > 1 ? 's' : '') . ')';
        }

        $content = $this->buildMoversContent($gainers, $losers, $failed);
        $url = APP_BASE_URL . '/market-movers.php?date=' . urlencode($today);

        return $this->send($heading, $content, [
            'url' => $url,
            'data' => [
                'type' => 'brvm_sync',
                'synced_at' => date('Y-m-d H:i:s'),
                'date' => $today,
                'failed' => $failed,
                'gainers_count' => count($gainers),
                'losers_count' => count($losers),
                // Le champ "data" OneSignal est plafonné à 2048 octets : on ne peut
                // pas y faire tenir la liste complète (jusqu'à ~45 sociétés), donc
                // seulement un aperçu. Le tableau complet est accessible via "url".
                'gainers' => $this->toDataRows(array_slice($gainers, 0, 10)),
                'losers' => $this->toDataRows(array_slice($losers, 0, 10)),
                'url' => $url,
            ]
        ]);
    }

    /**
     * Cotations du jour avec une variation non nulle, séparées hausse/baisse,
     * chaque section triée par amplitude décroissante.
     */
    private function getMovers($date) {
        $sql = "
            SELECT c.symbol, c.name, sq.variation_percent
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.trading_date = ?
            AND c.active = 1
            AND sq.variation_percent <> 0
            ORDER BY sq.variation_percent DESC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$date]) ?: [];

        $gainers = array_values(array_filter($rows, fn($r) => (float) $r['variation_percent'] > 0));
        $losers = array_values(array_filter($rows, fn($r) => (float) $r['variation_percent'] < 0));

        // $losers hérite du tri DESC de la requête (du moins négatif au plus
        // négatif) : on veut la plus grosse baisse en premier.
        usort($losers, fn($a, $b) => (float) $a['variation_percent'] <=> (float) $b['variation_percent']);

        return ['gainers' => $gainers, 'losers' => $losers];
    }

    private function buildMoversContent(array $gainers, array $losers, $failedCount) {
        $lines = [];

        if ($failedCount > 0) {
            $lines[] = "⚠️ $failedCount erreur(s) durant la synchro (voir sync_logs)";
            $lines[] = '';
        }

        if (empty($gainers) && empty($losers)) {
            $lines[] = "Aucune variation de cours aujourd'hui.";
            return implode("\n", $lines);
        }

        $lines[] = '📈 HAUSSE (' . count($gainers) . ')';
        $lines = array_merge($lines, $this->formatSection($gainers, true));

        $lines[] = '';
        $lines[] = '📉 BAISSE (' . count($losers) . ')';
        $lines = array_merge($lines, $this->formatSection($losers, false));

        return implode("\n", $lines);
    }

    private function formatSection(array $rows, $isGain) {
        if (empty($rows)) {
            return ['—'];
        }

        $lines = [];
        foreach (array_slice($rows, 0, self::MAX_LINES_PER_SECTION) as $row) {
            $variation = (float) $row['variation_percent'];
            $sign = $isGain ? '+' : ''; // les valeurs de baisse sont déjà négatives
            $lines[] = sprintf('%s %s%.2f%%', $row['symbol'], $sign, $variation);
        }

        $remaining = count($rows) - self::MAX_LINES_PER_SECTION;
        if ($remaining > 0) {
            $lines[] = "... et $remaining de plus";
        }

        return $lines;
    }

    private function toDataRows(array $rows) {
        return array_map(function ($r) {
            return [
                'symbol' => $r['symbol'],
                'variation_percent' => (float) $r['variation_percent'],
            ];
        }, $rows);
    }
}
