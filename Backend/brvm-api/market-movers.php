<?php
/**
 * Tableau exhaustif des variations du jour (hausses / baisses / inchangées),
 * pensé pour être ouvert depuis le lien d'une notification push OneSignal —
 * la notification n'affiche qu'un aperçu, cette page montre tout.
 *
 * Supporte aussi une vue "intrajournalière" : avec le cron réglé sur un
 * intervalle court (voir docker/crontab), class/BRVMSyncService.php
 * enregistre un relevé dans intraday_quotes à chaque synchro. On peut donc
 * choisir une heure précise de la journée (paramètre ?time=) pour voir l'état
 * du marché à ce moment-là, pas seulement la clôture.
 *
 * Rendu HTML côté serveur (pas d'appel JS à une API) : reste accessible même
 * derrière l'authentification ajoutée sur les endpoints api_*.php, et
 * fonctionne pour n'importe quel visiteur du lien, pas seulement un admin
 * connecté.
 */

require_once 'config.php';
require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';

$crud = new DynamiqueCrud();

$date = $_GET['date'] ?? null;
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = null;
}
if (!$date) {
    $result = $crud->executeCustomQuery("SELECT MAX(trading_date) AS d FROM stock_quotes");
    $date = $result[0]['d'] ?? date('Y-m-d');
}

// Relevés intrajournaliers disponibles pour cette date (pour la navigation par heure)
$availableTimes = $crud->executeCustomQuery(
    "SELECT DISTINCT quote_datetime FROM intraday_quotes WHERE DATE(quote_datetime) = ? ORDER BY quote_datetime ASC",
    [$date]
) ?: [];

$requestedTime = $_GET['time'] ?? null;
$time = null;
if ($requestedTime) {
    foreach ($availableTimes as $t) {
        $hhmm = date('H:i', strtotime($t['quote_datetime']));
        if ($hhmm === $requestedTime || $t['quote_datetime'] === $requestedTime) {
            $time = $t['quote_datetime'];
            break;
        }
    }
    // Si l'heure demandée ne correspond à aucun relevé connu, on retombe
    // silencieusement sur la vue de clôture plutôt que d'afficher une erreur.
}

if ($time) {
    $rows = $crud->executeCustomQuery(
        "SELECT
            c.symbol, c.name, iq.volume,
            sq.previous_close AS previous_close,
            iq.price AS current_price,
            CASE
                WHEN sq.previous_close IS NULL OR sq.previous_close = 0 THEN 0
                ELSE ROUND((iq.price - sq.previous_close) / sq.previous_close * 100, 2)
            END AS variation_percent
         FROM intraday_quotes iq
         INNER JOIN companies c ON c.id = iq.company_id
         LEFT JOIN stock_quotes sq ON sq.company_id = iq.company_id AND sq.trading_date = ?
         WHERE iq.quote_datetime = ? AND c.active = 1
         ORDER BY variation_percent DESC",
        [$date, $time]
    ) ?: [];
} else {
    $rows = $crud->executeCustomQuery(
        "SELECT
            c.symbol, c.name, sq.volume, sq.variation_percent,
            sq.previous_close AS previous_close,
            sq.close_price AS current_price
         FROM stock_quotes sq
         INNER JOIN companies c ON c.id = sq.company_id
         WHERE sq.trading_date = ? AND c.active = 1
         ORDER BY sq.variation_percent DESC",
        [$date]
    ) ?: [];
}

$gainers = array_values(array_filter($rows, fn($r) => (float) $r['variation_percent'] > 0));
$unchanged = array_values(array_filter($rows, fn($r) => (float) $r['variation_percent'] == 0));
$losers = array_values(array_filter($rows, fn($r) => (float) $r['variation_percent'] < 0));
usort($losers, fn($a, $b) => (float) $a['variation_percent'] <=> (float) $b['variation_percent']);

// Dates disponibles pour la navigation rapide (les 10 dernières séances)
$availableDates = $crud->executeCustomQuery(
    "SELECT DISTINCT trading_date FROM stock_quotes ORDER BY trading_date DESC LIMIT 10"
) ?: [];

function fmtPct($v) {
    $v = (float) $v;
    $sign = $v > 0 ? '+' : '';
    return $sign . number_format($v, 2, ',', ' ') . '%';
}
function fmtNum($v) {
    if ($v === null) return '—';
    return number_format((float) $v, 0, ',', ' ');
}
function renderTable($rows, $variantClass) {
    if (empty($rows)) {
        echo '<div class="empty">Aucune société dans cette catégorie.</div>';
        return;
    }
    ?>
    <table>
      <thead>
        <tr>
          <th>Symbole</th><th>Nom</th>
          <th class="num">Cours précédent</th><th class="num">Cours actuel</th>
          <th class="num">Volume</th><th class="num">Variation</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['symbol']) ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="num"><?= fmtNum($r['previous_close']) ?></td>
          <td class="num"><?= fmtNum($r['current_price']) ?></td>
          <td class="num"><?= fmtNum($r['volume']) ?></td>
          <td class="num pct <?= $variantClass ?>"><?= fmtPct($r['variation_percent']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BRVM Insights — Variations du <?= htmlspecialchars($date) ?><?= $time ? ' à ' . htmlspecialchars(date('H:i', strtotime($time))) : '' ?></title>
<style>
  .viz-root {
    color-scheme: light;
    --surface-1: #fcfcfb; --page: #f9f9f7; --text-primary: #0b0b0b;
    --text-secondary: #52514e; --text-muted: #898781; --gridline: #e1e0d9;
    --border: rgba(11,11,11,0.10); --good: #0ca30c; --critical: #d03b3b;
    --neutral-fill: #eceae4;
  }
  @media (prefers-color-scheme: dark) {
    :root:where(:not([data-theme="light"])) .viz-root {
      color-scheme: dark;
      --surface-1: #1a1a19; --page: #0d0d0d; --text-primary: #ffffff;
      --text-secondary: #c3c2b7; --text-muted: #898781; --gridline: #2c2c2a;
      --border: rgba(255,255,255,0.10); --good: #0ca30c; --critical: #e66767;
      --neutral-fill: #262624;
    }
  }
  :root[data-theme="dark"] .viz-root {
    color-scheme: dark;
    --surface-1: #1a1a19; --page: #0d0d0d; --text-primary: #ffffff;
    --text-secondary: #c3c2b7; --text-muted: #898781; --gridline: #2c2c2a;
    --border: rgba(255,255,255,0.10); --good: #0ca30c; --critical: #e66767;
    --neutral-fill: #262624;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body.viz-root {
    background: var(--page); color: var(--text-primary);
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    padding: 20px;
  }
  .wrap { max-width: 980px; margin: 0 auto; }
  header { margin-bottom: 18px; }
  h1 { font-size: 19px; margin: 0 0 4px; }
  .sub { color: var(--text-secondary); font-size: 13px; }
  .nav-label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin: 12px 0 4px; }
  .date-nav, .time-nav { display: flex; gap: 6px; flex-wrap: wrap; }
  .date-nav a, .time-nav a {
    font-size: 12px; padding: 4px 9px; border-radius: 999px; text-decoration: none;
    background: var(--neutral-fill); color: var(--text-secondary); border: 1px solid var(--border);
  }
  .date-nav a.active, .time-nav a.active { background: var(--text-primary); color: var(--surface-1); }

  .summary { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
  .summary .tile {
    flex: 1; min-width: 140px; background: var(--surface-1); border: 1px solid var(--border);
    border-radius: 10px; padding: 12px 14px;
  }
  .summary .tile .n { font-size: 22px; font-weight: 700; }
  .summary .tile .l { font-size: 12px; color: var(--text-secondary); }
  .summary .tile.up .n { color: var(--good); }
  .summary .tile.down .n { color: var(--critical); }

  .section-title {
    font-size: 13px; text-transform: uppercase; letter-spacing: .04em;
    color: var(--text-secondary); margin: 22px 0 8px;
  }
  .table-scroll { overflow-x: auto; border-radius: 10px; }
  table { width: 100%; min-width: 560px; border-collapse: collapse; background: var(--surface-1); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; font-size: 13px; }
  thead th { text-align: left; font-weight: 600; color: var(--text-secondary); font-size: 12px; padding: 8px 12px; border-bottom: 1px solid var(--gridline); white-space: nowrap; }
  tbody td { padding: 7px 12px; border-bottom: 1px solid var(--gridline); font-variant-numeric: tabular-nums; }
  tbody tr:last-child td { border-bottom: none; }
  td.num, th.num { text-align: right; }
  td.pct.up { color: var(--good); font-weight: 600; }
  td.pct.down { color: var(--critical); font-weight: 600; }
  td.pct.flat { color: var(--text-muted); font-weight: 600; }
  .empty { color: var(--text-muted); font-size: 13px; padding: 14px; text-align: center; background: var(--surface-1); border: 1px solid var(--border); border-radius: 10px; }
  footer { margin-top: 26px; font-size: 12px; color: var(--text-muted); text-align: center; }
  footer a { color: inherit; }
</style>
</head>
<body class="viz-root">
<div class="wrap">
  <header>
    <h1>Variations du marché BRVM</h1>
    <div class="sub">
      <?= htmlspecialchars(date('d/m/Y', strtotime($date))) ?><?= $time ? ' — relevé de ' . htmlspecialchars(date('H:i', strtotime($time))) : ' — clôture' ?>
      — <?= count($rows) ?> société(s)
    </div>

    <?php if (!empty($availableDates)): ?>
    <div class="nav-label">Jour</div>
    <div class="date-nav">
      <?php foreach ($availableDates as $d): $d = $d['trading_date']; ?>
        <a href="?date=<?= urlencode($d) ?>" class="<?= $d === $date ? 'active' : '' ?>"><?= htmlspecialchars(date('d/m', strtotime($d))) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($availableTimes)): ?>
    <div class="nav-label">Heure (relevés intrajournaliers)</div>
    <div class="time-nav">
      <a href="?date=<?= urlencode($date) ?>" class="<?= !$time ? 'active' : '' ?>">Clôture</a>
      <?php foreach ($availableTimes as $t): $hhmm = date('H:i', strtotime($t['quote_datetime'])); ?>
        <a href="?date=<?= urlencode($date) ?>&time=<?= urlencode($hhmm) ?>" class="<?= ($time && date('H:i', strtotime($time)) === $hhmm) ? 'active' : '' ?>"><?= htmlspecialchars($hhmm) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </header>

  <div class="summary">
    <div class="tile up"><div class="n"><?= count($gainers) ?></div><div class="l">Hausses</div></div>
    <div class="tile down"><div class="n"><?= count($losers) ?></div><div class="l">Baisses</div></div>
    <div class="tile"><div class="n"><?= count($unchanged) ?></div><div class="l">Inchangées</div></div>
  </div>

  <div class="section-title">📈 Hausses (<?= count($gainers) ?>)</div>
  <div class="table-scroll"><?php renderTable($gainers, 'up'); ?></div>

  <div class="section-title">📉 Baisses (<?= count($losers) ?>)</div>
  <div class="table-scroll"><?php renderTable($losers, 'down'); ?></div>

  <div class="section-title">➖ Inchangées (<?= count($unchanged) ?>)</div>
  <div class="table-scroll"><?php renderTable($unchanged, 'flat'); ?></div>

  <footer>
    BRVM Insights — <a href="dashboard.html">Voir le tableau de bord complet</a>
  </footer>
</div>
</body>
</html>
