#!/bin/bash

# Script de déploiement pour Ploi
# Ce script sera exécuté automatiquement lors du déploiement depuis GitHub

cd /home/ploi/digicard-api.arccenciel.com

# Afficher la version de PHP
php -v

# Afficher la branche actuelle
git branch

# Pull les dernières modifications
echo "🔄 Pull des dernières modifications..."
git pull origin main

# Installer les dépendances Composer (production uniquement)
echo "📦 Installation des dépendances Composer..."
composer install --no-dev --optimize-autoloader --no-interaction

# Installer les dépendances NPM et builder (si nécessaire)
if [ -f "package.json" ]; then
    echo "📦 Installation des dépendances NPM..."
    npm ci --production
    npm run build
fi

# Exécuter les migrations
echo "🗄️ Exécution des migrations..."
php artisan migrate --force

# Créer le lien symbolique pour le storage (si nécessaire)
if [ ! -L "public/storage" ]; then
    echo "🔗 Création du lien symbolique storage..."
    php artisan storage:link
fi

# Optimiser Laravel
echo "⚡ Optimisation de Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Nettoyer les caches inutiles
echo "🧹 Nettoyage des caches..."
php artisan cache:clear

# Vérifier les permissions
echo "🔐 Vérification des permissions..."
chmod -R 775 storage bootstrap/cache
chown -R ploi:www-data storage bootstrap/cache

# Recharger PHP-FPM
echo "🔄 Rechargement de PHP-FPM..."
sudo service php8.2-fpm reload

echo "✅ Déploiement terminé avec succès!"

