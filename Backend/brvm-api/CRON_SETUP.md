# Synchronisation automatique quotidienne (launchd)

Ce document explique comment automatiser `cron_sync_brvm.php` sur macOS avec
**launchd** (pas un vrai `crontab`), et pourquoi.

## Pourquoi launchd et pas un simple crontab

- Un `crontab` classique ne se déclenche que si la machine est **allumée et
  réveillée** à l'heure prévue. Sur un laptop qu'on ferme le soir, c'est peu
  fiable.
- `launchd` (le système natif macOS) permet en plus de relancer la tâche
  **à l'ouverture de session** (`RunAtLoad`), en complément d'une heure fixe —
  utile si la machine n'est allumée qu'en soirée.
- MySQL (via MAMP) ne démarre pas tout seul au boot : il faut donc que le job
  sache démarrer MAMP lui-même si besoin, avant de lancer la synchro PHP.

## Principe : une seule "machine désignée"

Si tu travailles sur plusieurs machines, **ne mets ce cron que sur une seule**
(celle le plus souvent allumée en fin de journée, ex: le poste du bureau).
Chaque MAMP a sa propre base MySQL locale — répartir le cron sur plusieurs
machines fragmenterait l'historique au lieu de le compléter. Les autres
machines restent des environnements de dev/test avec une base locale qu'on
peut resynchroniser ponctuellement (dump SQL) si besoin.

## Fichiers concernés

| Fichier | Rôle |
|---|---|
| `cron_sync_brvm.php` | Script de synchro (cotations + indices + indicateurs techniques) |
| `scripts/run_daily_sync.sh` | Wrapper : démarre MAMP si besoin, puis lance le script PHP, avec logs |
| `~/Library/LaunchAgents/com.brvm-insights.daily-sync.plist` | Déclencheur planifié (launchd) |

`cron_sync_brvm.php` refuse tout seul de tourner le week-end ou avant
l'ouverture du marché (08:30, configurable dans `system_config`), donc le
wrapper peut se déclencher plusieurs fois sans risque de dupliquer/corrompre
des données (la table `stock_quotes` fait un *upsert* par entreprise+jour).

## Mise en place, étape par étape

### 1. Vérifier les chemins

Adapte ces deux variables en tête de `scripts/run_daily_sync.sh` si ton
installation diffère :

```bash
PROJECT_DIR="/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api"
PHP_BIN="/Applications/MAMP/bin/php/php8.2.0/bin/php"   # adapte la version PHP si besoin
```

Pour retrouver le binaire PHP fourni par MAMP :

```bash
ls /Applications/MAMP/bin/php/
```

### 2. Rendre le script exécutable

```bash
chmod +x /Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/scripts/run_daily_sync.sh
```

### 3. Tester le script manuellement

Avant d'automatiser quoi que ce soit, vérifie qu'il fonctionne à la main :

```bash
/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/scripts/run_daily_sync.sh
echo "code de sortie: $?"
tail -30 /Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/logs/launchd_sync.log
```

Un code de sortie `0` et un log se terminant par `SYNCHRONISATION TERMINÉE
AVEC SUCCÈS` confirment que tout est correctement branché (MAMP, MySQL, scraper).

### 4. Créer le fichier LaunchAgent

Crée `~/Library/LaunchAgents/com.brvm-insights.daily-sync.plist` :

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.brvm-insights.daily-sync</string>

    <key>ProgramArguments</key>
    <array>
        <string>/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/scripts/run_daily_sync.sh</string>
    </array>

    <!-- Déclenchement principal : 16h35, du lundi au vendredi -->
    <key>StartCalendarInterval</key>
    <array>
        <dict><key>Weekday</key><integer>1</integer><key>Hour</key><integer>16</integer><key>Minute</key><integer>35</integer></dict>
        <dict><key>Weekday</key><integer>2</integer><key>Hour</key><integer>16</integer><key>Minute</key><integer>35</integer></dict>
        <dict><key>Weekday</key><integer>3</integer><key>Hour</key><integer>16</integer><key>Minute</key><integer>35</integer></dict>
        <dict><key>Weekday</key><integer>4</integer><key>Hour</key><integer>16</integer><key>Minute</key><integer>35</integer></dict>
        <dict><key>Weekday</key><integer>5</integer><key>Hour</key><integer>16</integer><key>Minute</key><integer>35</integer></dict>
    </array>

    <!-- Rattrapage à l'ouverture de session (ex: machine allumée seulement le soir) -->
    <key>RunAtLoad</key>
    <true/>

    <key>StandardOutPath</key>
    <string>/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/logs/launchd_stdout.log</string>
    <key>StandardErrorPath</key>
    <string>/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/logs/launchd_stderr.log</string>
</dict>
</plist>
```

Weekday : `1` = lundi … `5` = vendredi. Change l'heure (`Hour`/`Minute`) si tu
préfères un autre horaire — 16h35 laisse 35 min de marge après la clôture
(16h00) pour que BRVM publie les chiffres définitifs.

### 5. Charger le job

```bash
launchctl unload ~/Library/LaunchAgents/com.brvm-insights.daily-sync.plist 2>/dev/null
launchctl load ~/Library/LaunchAgents/com.brvm-insights.daily-sync.plist
```

`RunAtLoad` déclenche immédiatement une première synchro au chargement — c'est
normal, ça sert aussi de test.

### 6. Vérifier que c'est actif

```bash
launchctl list | grep brvm-insights
```

Une ligne doit apparaître (le 2ᵉ champ est le dernier code de sortie observé,
`0` = succès, `-` = pas encore lancé).

## Déclencher une synchro manuellement (sans attendre 16h35)

Trois façons, du plus complet au plus direct :

**1. Via launchd** (passe par le wrapper complet : démarre MAMP si besoin,
écrit dans les mêmes logs que le déclenchement automatique) :

```bash
launchctl kickstart -p "gui/$(id -u)/com.brvm-insights.daily-sync"
```

Compte 20-30 secondes avant que le job démarre réellement (launchd ne
l'exécute pas instantanément). `-p` affiche le PID lancé.

**2. Le script directement**, sans passer par launchd (le plus rapide, pratique
pour tester une modif) :

```bash
/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/scripts/run_daily_sync.sh
```

**3. Depuis le dashboard** (`dashboard.html`), bouton "Synchroniser
maintenant" — appelle directement `api_brvm_sync.php?action=sync_now` sans
passer par le wrapper, donc ne démarre pas MAMP tout seul si MySQL est déjà
arrêté.

Dans tous les cas, vérifie le résultat dans `logs/launchd_sync.log` (pour 1 et
2) ou dans la table `sync_logs`.

## Dépannage

| Symptôme | À vérifier |
|---|---|
| Rien dans `logs/launchd_sync.log` | Le plist est-il bien chargé (`launchctl list \| grep brvm-insights`) ? Chemins corrects dans `ProgramArguments` ? |
| `MySQL toujours injoignable après 60s` | MAMP est-il configuré pour démarrer Apache+MySQL automatiquement à l'ouverture (pas juste ouvrir la fenêtre) ? Teste `open -a MAMP` puis vérifie `ps aux \| grep mysqld` |
| Erreurs PHP dans `logs/launchd_stderr.log` | Lance `php -l cron_sync_brvm.php` avec le binaire PHP de MAMP pour vérifier la syntaxe |
| Pas de synchro le week-end | Comportement voulu (`isMarketOpen()` bloque samedi/dimanche) |
| Synchro qui tourne mais aucune nouvelle donnée un jour donné | Vérifie que le site brvm.org était bien accessible : `curl -I https://www.brvm.org/fr/cours-actions/0` |

Les logs de synchro détaillés (par exécution, avec stats insérés/mis à jour/échoués)
sont aussi dans la table `sync_logs` de la base de données.

## Désactiver / désinstaller

```bash
launchctl unload ~/Library/LaunchAgents/com.brvm-insights.daily-sync.plist
rm ~/Library/LaunchAgents/com.brvm-insights.daily-sync.plist
```

## Reproduire sur une autre machine

Si tu changes un jour de "machine désignée" :

1. Répète les étapes 1 à 6 ci-dessus sur la nouvelle machine.
2. Désinstalle le job sur l'ancienne machine (section précédente) pour éviter
   d'avoir deux machines qui écrivent chacune dans leur propre base sans se
   synchroniser entre elles.
3. Pense à exporter/importer un dump SQL (`mysqldump`) si tu veux transférer
   l'historique déjà accumulé plutôt que repartir de zéro.
