#!/bin/bash

# Script de déploiement pour DigiCard API
# Usage: ./deploy.sh

set -e  # Arrêter en cas d'erreur

cd /home/ploi/digicard-api.arccenciel.com

echo "🚀 Début du déploiement..."

# Passer en mode maintenance (Optionnel mais recommandé)
echo "📦 Activation du mode maintenance..."
php artisan down || true

# Pull les dernières modifications
echo "📥 Récupération des modifications depuis Git..."
git pull origin main

# Installer les dépendances Composer
echo "📦 Installation des dépendances Composer..."
composer install --no-dev --optimize-autoloader

# Installer les dépendances NPM (si nécessaire pour le panel admin)
echo "📦 Installation des dépendances NPM..."
npm install
npm run build

# Exécuter les migrations
echo "🔄 Exécution des migrations..."
php artisan migrate --force

# Créer les liens symboliques
echo "🔗 Création des liens symboliques..."
php artisan storage:link

# ✅ IMPORTANT : Nettoyer les caches AVANT de les recréer
echo "🧹 Nettoyage des caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear

# ✅ NOUVEAU : Vider OPcache (CRITIQUE pour que le nouveau code soit pris en compte)
echo "🔄 Vidage d'OPcache..."
php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache vidé avec succès' . PHP_EOL; } else { echo 'OPcache non disponible' . PHP_EOL; }"

# Optimiser Laravel (Prend en compte les changements du .env)
echo "⚡ Optimisation de Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Redémarrer les workers de queue (POUR VOS EMAILS)
echo "📧 Redémarrage des workers de queue..."
php artisan queue:restart

# Recharger PHP-FPM (CRITIQUE pour vider OPcache)
echo "🔄 Rechargement de PHP-FPM..."
sudo service php8.4-fpm reload

# Alternative si la commande ci-dessus ne fonctionne pas :
# sudo systemctl reload php8.4-fpm

# Sortir du mode maintenance
echo "✅ Désactivation du mode maintenance..."
php artisan up

echo "🎉 Déploiement terminé avec succès !"
echo ""
echo "💡 Pour vérifier que le nouveau code est actif, exécutez :"
echo "   tail -f storage/logs/laravel.log | grep 'Webhook cartes supplémentaires appelé'"

