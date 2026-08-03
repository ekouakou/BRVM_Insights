# Image backend BRVM Insights : PHP 8.3 + Apache (mod_php), avec les
# dépendances système utilisées par le scraping/l'extraction PDF (curl, DOM,
# poppler pour pdftotext, tesseract pour l'OCR de repli — voir
# class/PdfTextExtractor.php) et cron pour la synchro intrajournalière
# (cron_sync_brvm.php, voir CRON_SETUP.md).
#
# Ce même Dockerfile sert à la fois pour le service web (Apache en
# premier plan) et pour la synchro planifiée : docker/entrypoint.sh démarre
# cron en arrière-plan avant de passer la main à la commande du conteneur
# (apache2-foreground par défaut).
FROM php:8.3-apache

# Dépendances système :
# - libcurl4-openssl-dev / libonig-dev / libxml2-dev : nécessaires pour
#   compiler les extensions PHP curl/mbstring/dom ci-dessous.
# - poppler-utils : fournit pdftotext (extraction texte natif des PDF).
# - tesseract-ocr + tesseract-ocr-fra : OCR de repli pour les PDF scannés.
# - cron : synchro intrajournalière (voir docker/crontab).
# - default-mysql-client : mysqladmin, utile pour le healthcheck/diagnostic.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libxml2-dev \
        poppler-utils \
        tesseract-ocr \
        tesseract-ocr-fra \
        cron \
        default-mysql-client \
    && docker-php-ext-install -j"$(nproc)" curl mbstring pdo_mysql \
    && apt-get purge -y --auto-remove libcurl4-openssl-dev libonig-dev libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

WORKDIR /var/www/html

# Code applicatif — voir .dockerignore pour ce qui est volontairement exclu
# (storage/logs/cache/uploads/temp : remplacés par des volumes montés au
# démarrage, .env : les secrets passent par les variables d'environnement du
# conteneur, jamais par un fichier embarqué dans l'image).
COPY . .

# Répertoires modifiés à l'exécution (téléchargements, logs, cache...) :
# créés ici pour que les volumes montés en écriture par www-data (utilisateur
# sous lequel tourne Apache dans l'image officielle) ne posent pas de
# problème de permissions au premier démarrage.
RUN mkdir -p storage/reports storage/bulletins logs cache uploads temp locks \
    && chown -R www-data:www-data storage logs cache uploads temp locks

COPY docker/crontab /etc/cron.d/brvm-sync
RUN chmod 0644 /etc/cron.d/brvm-sync

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint-brvm.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-brvm.sh

ENTRYPOINT ["docker-entrypoint-brvm.sh"]
CMD ["apache2-foreground"]
