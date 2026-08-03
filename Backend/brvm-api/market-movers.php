<?php
/**
 * Tableau exhaustif des variations du jour (hausses / baisses), pensé pour
 * être ouvert depuis le lien d'une notification push OneSignal — la
 * notification n'affiche qu'un aperçu, cette page montre tout.
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

$rows = $crud->executeCustomQuery(
    "SELECT c.symbol, c.name, sq.close_price, sq.variation_percent, sq.volume
     FROM stock_quotes sq
     INNER JOIN companies c ON c.id = sq.company_id
     WHERE sq.trading_date = ? AND c.active = 1
     ORDER BY sq.variation_percent DESC",
    [$date]
) ?: [];

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
    return number_format((float) $v, 0, ',', ' ');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BRVM Insights — Variations du <?= htmlspecialchars($date) ?></title>
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
  .wrap { max-width: 900px; margin: 0 auto; }
  header { margin-bottom: 18px; }
  h1 { font-size: 19px; margin: 0 0 4px; }
  .sub { color: var(--text-secondary); font-size: 13px; }
  .date-nav { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
  .date-nav a {
    font-size: 12px; padding: 4px 9px; border-radius: 999px; text-decoration: none;
    background: var(--neutral-fill); color: var(--text-secondary); border: 1px solid var(--border);
  }
  .date-nav a.active { background: var(--text-primary); color: var(--surface-1); }

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
  table { width: 100%; border-collapse: collapse; background: var(--surface-1); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; font-size: 13px; }
  thead th { text-align: left; font-weight: 600; color: var(--text-secondary); font-size: 12px; padding: 8px 12px; border-bottom: 1px solid var(--gridline); }
  tbody td { padding: 7px 12px; border-bottom: 1px solid var(--gridline); font-variant-numeric: tabular-nums; }
  tbody tr:last-child td { border-bottom: none; }
  td.num, th.num { text-align: right; }
  td.pct.up { color: var(--good); font-weight: 600; }
  td.pct.down { color: var(--critical); font-weight: 600; }
  .empty { color: var(--text-muted); font-size: 13px; padding: 14px; text-align: center; }
  footer { margin-top: 26px; font-size: 12px; color: var(--text-muted); text-align: center; }
  footer a { color: inherit; }
</style>
</head>
<body class="viz-root">
<div class="wrap">
  <header>
    <h1>Variations du marché BRVM</h1>
    <div class="sub"><?= htmlspecialchars(date('d/m/Y', strtotime($date))) ?> — <?= count($rows) ?> société(s) cotée(s)</div>
    <?php if (!empty($availableDates)): ?>
    <div class="date-nav">
      <?php foreach ($availableDates as $d): $d = $d['trading_date']; ?>
        <a href="?date=<?= urlencode($d) ?>" class="<?= $d === $date ? 'active' : '' ?>"><?= htmlspecialchars(date('d/m', strtotime($d))) ?></a>
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
  <?php if (empty($gainers)): ?>
    <div class="empty">Aucune hausse ce jour-là.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Symbole</th><th>Nom</th><th class="num">Cours</th><th class="num">Volume</th><th class="num">Variation</th></tr></thead>
      <tbody>
        <?php foreach ($gainers as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['symbol']) ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="num"><?= fmtNum($r['close_price']) ?></td>
          <td class="num"><?= fmtNum($r['volume']) ?></td>
          <td class="num pct up"><?= fmtPct($r['variation_percent']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="section-title">📉 Baisses (<?= count($losers) ?>)</div>
  <?php if (empty($losers)): ?>
    <div class="empty">Aucune baisse ce jour-là.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Symbole</th><th>Nom</th><th class="num">Cours</th><th class="num">Volume</th><th class="num">Variation</th></tr></thead>
      <tbody>
        <?php foreach ($losers as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['symbol']) ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="num"><?= fmtNum($r['close_price']) ?></td>
          <td class="num"><?= fmtNum($r['volume']) ?></td>
          <td class="num pct down"><?= fmtPct($r['variation_percent']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (!empty($unchanged)): ?>
  <div class="section-title">Inchangées (<?= count($unchanged) ?>)</div>
  <div class="empty" style="text-align:left;"><?= htmlspecialchars(implode(', ', array_column($unchanged, 'symbol'))) ?></div>
  <?php endif; ?>

  <footer>
    BRVM Insights — <a href="dashboard.html">Voir le tableau de bord complet</a>
  </footer>
</div>
</body>
</html>
