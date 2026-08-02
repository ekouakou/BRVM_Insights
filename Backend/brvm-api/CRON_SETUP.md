# Synchronisation automatique intrajournalière (launchd)

Ce document explique comment automatiser `cron_sync_brvm.php` sur macOS avec
**launchd** (pas un vrai `crontab`), et pourquoi.

Les cours sur `brvm.org/fr/cours-actions/0` évoluent en cours de séance : le
job se déclenche donc toutes les 15 minutes pendant les heures de marché
(08:30-16:00 + 1h de marge, lundi-vendredi), pas une seule fois par jour.
`cron_sync_brvm.php` filtre lui-même les déclenchements hors de cette fenêtre
(`isMarketOpen()`), donc le déclencheur peut se réveiller sans risque même en
dehors des heures de bourse (retour immédiat, aucune requête vers brvm.org).

## Pourquoi launchd et pas un simple crontab

- Un `crontab` classique ne se déclenche que si la machine est **allumée et
  réveillée** à l'heure prévue. Sur un laptop qu'on ferme le soir, c'est peu
  fiable.
- `launchd` (le système natif macOS) permet en plus de relancer la tâche
  **à l'ouverture de session** (`RunAtLoad`), en complément du déclenchement
  périodique — utile pour rattraper un passage manqué si la machine vient de
  s'allumer.
- MySQL (via MAMP) ne démarre pas tout seul au boot : il faut donc que le job
  sache démarrer MAMP lui-même si besoin, avant de lancer la synchro PHP.

## Principe : une seule "machine désignée"

Si tu travailles sur plusieurs machines, **ne mets ce cron que sur une seule**
(celle le plus souvent allumée pendant les heures de marché, ex: le poste du
bureau — une machine éteinte en journée ne peut pas capter les mouvements
intrajournaliers).
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

`cron_sync_brvm.php` refuse tout seul de tourner le week-end ou en dehors des
heures de marché (08:30-16:00 + 1h de marge, configurable dans
`system_config`), donc le wrapper peut se déclencher aussi souvent que voulu
sans risque de dupliquer/corrompre des données (la table `stock_quotes` fait
un *upsert* par entreprise+jour) — chaque nouveau passage dans la journée
écrase simplement la ligne du jour avec le cours le plus récent, il n'y a pas
d'historique intrajournalier conservé, seulement une valeur par jour tenue à
jour plus fréquemment.

## Mise en place, étape par étape

### 1. Configurer PHP_BIN et MYSQL_PORT pour cette machine

`PROJECT_DIR` se déduit automatiquement de l'emplacement du script (pas besoin
d'y toucher). En revanche `PHP_BIN` (version de PHP choisie dans MAMP) et
`MYSQL_PORT` dépendent de l'installation locale et diffèrent souvent d'une
machine de dev à l'autre — ne les modifie **pas** directement dans
`scripts/run_daily_sync.sh` (fichier suivi par git, partagé entre machines).

Crée plutôt un fichier local, ignoré par git, à côté du script :

```bash
cp scripts/run_daily_sync.local.sh.example scripts/run_daily_sync.local.sh
```

Puis édite `scripts/run_daily_sync.local.sh` avec les valeurs de **cette**
machine :

```bash
PHP_BIN="/Applications/MAMP/bin/php/php8.3.30/bin/php"
MYSQL_PORT="8889"
```

Pour retrouver le binaire PHP fourni par MAMP :

```bash
ls /Applications/MAMP/bin/php/
```

Pour retrouver le port MySQL réellement utilisé par MAMP (il peut changer
après une mise à jour de MAMP — c'est souvent `8889`, pas le `3306` par
défaut de MySQL) :

```bash
ps aux | grep mysqld | grep -o -- '--port=[0-9]*'
```

Répète cette étape (fichier `.local.sh` avec ses propres valeurs) sur chaque
machine de dev — c'est justement ce qui évite les conflits git entre elles.

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

    <!-- Déclenchement toutes les 15 minutes (900s), 7j/7 et 24h/24 : c'est
         cron_sync_brvm.php (isMarketOpen()) qui filtre les jours/heures hors
         marché, donc pas besoin de restreindre le déclencheur lui-même. -->
    <key>StartInterval</key>
    <integer>900</integer>

    <!-- Rattrapage à l'ouverture de session (ex: machine allumée seulement en journée) -->
    <key>RunAtLoad</key>
    <true/>

    <key>StandardOutPath</key>
    <string>/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/logs/launchd_stdout.log</string>
    <key>StandardErrorPath</key>
    <string>/Applications/MAMP/htdocs/BRVM_Insights/Backend/brvm-api/logs/launchd_stderr.log</string>
</dict>
</plist>
```

Change `StartInterval` (en secondes) si tu préfères une autre fréquence —
`900` = 15 min, `300` = 5 min, `1800` = 30 min. La fenêtre effective
(marché ouvert + 1h de marge après clôture) est contrôlée côté PHP dans
`isMarketOpen()`, pas ici.

### Si tu as déjà l'ancien plist (1x/jour à 16h35)

Édite simplement le fichier existant pour remplacer le bloc
`StartCalendarInterval` par le bloc `StartInterval` ci-dessus, puis recharge-le
(étape 5 ci-dessous) — pas besoin de renommer le fichier ni le `Label`.

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

## Déclencher une synchro manuellement (sans attendre le prochain passage)

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
| `MySQL toujours injoignable après 60s` | 1) MAMP est-il configuré pour démarrer Apache+MySQL automatiquement à l'ouverture (pas juste ouvrir la fenêtre) ? Teste `open -a MAMP` puis vérifie `ps aux \| grep mysqld`. 2) `MYSQL_PORT` dans `scripts/run_daily_sync.local.sh` correspond-il au port réel (`ps aux \| grep mysqld \| grep -o -- '--port=[0-9]*'`) ? Une mise à jour de MAMP peut changer ce port. |
| `PHP_BIN: No such file or directory` | Une mise à jour de MAMP peut retirer l'ancienne version de PHP embarquée. Vérifie `ls /Applications/MAMP/bin/php/` et mets à jour `PHP_BIN` dans `scripts/run_daily_sync.local.sh` |
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
