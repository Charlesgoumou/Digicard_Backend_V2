<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CompanyPageController;
use App\Http\Controllers\UserPortfolioController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\CompanyPageController as AdminCompanyPageController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\SharedContactController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\UserPreferencesController;
use App\Http\Controllers\ImageSearchController;
use App\Http\Controllers\DashboardController as FrontendDashboardController;
use App\Http\Controllers\PublicPointageController;
use App\Http\Controllers\AttendanceReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- Route pour servir les fichiers depuis storage (publique) ---
// ✅ CORRECTION : Route API pour servir les fichiers depuis storage/app/public
// Cette route fonctionne même si .htaccess ne fonctionne pas correctement
Route::get('/storage/{path}', [StorageController::class, 'serve'])
    ->where('path', '.*')
    ->name('api.storage.serve');

// --- Routes Publiques ---
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/verify', [AuthController::class, 'verify'])->name('verify');
Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->name('verification.resend');
// Tarification publique
Route::get('/settings/pricing', [SettingsController::class, 'getPublicPricing'])->name('settings.pricing');
// Contenu public d'accueil (CMS)
Route::get('/homepage', [SettingsController::class, 'getPublicHomepage'])->name('homepage.public');
// Route publique pour vérifier l'utilisateur actuel (retourne null si non authentifié)
// ✅ AJOUT: Utiliser le middleware 'web' et 'EnsureFrontendRequestsAreStateful' 
// pour établir correctement la session lors des rechargements de page
Route::middleware(['web', \App\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->get('/user', [AuthController::class, 'user'])
    ->name('user.public');

// Routes Google OAuth - Sélection de compte (publiques, appelées avant connexion)
// CRITIQUE: Utiliser le middleware 'web' et 'EnsureFrontendRequestsAreStateful' pour avoir accès à la session Laravel
Route::middleware(['web', \App\Http\Middleware\EnsureFrontendRequestsAreStateful::class])->group(function () {
    Route::get('/google/pending-accounts', [SocialController::class, 'getPendingAccounts'])->name('google.pending-accounts');
    Route::post('/google/select-account', [SocialController::class, 'selectAccount'])->name('google.select-account');
    Route::post('/google/validate-token', [SocialController::class, 'validateToken'])->name('google.validate-token');
});

// Réinitialisation de mot de passe
Route::post('/password/reset-link', [PasswordResetController::class, 'sendResetLink'])->name('password.reset-link');
Route::post('/password/reset', [PasswordResetController::class, 'resetPassword'])->name('password.reset');
Route::post('/password/verify-token', [PasswordResetController::class, 'verifyToken'])->name('password.verify-token');

// Formulaire de contact
Route::post('/contact', [ContactController::class, 'sendMessage'])->name('contact.send');

// Pointage — profil public (empreinte appareil + contexte commande)
Route::post('/public/pointage/verify', [PublicPointageController::class, 'verify'])
    ->middleware('throttle:60,1')
    ->name('public.pointage.verify');
Route::post('/public/pointage/check-in', [PublicPointageController::class, 'checkIn'])
    ->middleware('throttle:60,1')
    ->name('public.pointage.check-in');
Route::post('/public/pointage/check-out', [PublicPointageController::class, 'processDeparture'])
    ->middleware('throttle:60,1')
    ->name('public.pointage.check-out');

// Webhook pour Chap Chap Pay (publique, sans authentification)
Route::post('/payment/webhook', [OrderController::class, 'paymentWebhook'])->name('api.payment.webhook');

// ✅ NOUVEAU: Route de simulation pour le développement (simule le webhook)
// Cette route permet de forcer la validation d'une commande en développement local
Route::post('/payment/simulate-success/{orderId}', [OrderController::class, 'simulatePaymentSuccess'])->name('api.payment.simulate-success');
// ✅ NOUVEAU: Route spécifique pour simuler le webhook (appelée depuis PaymentCloseView)
Route::post('/payment/simulate-webhook/{orderId}', [OrderController::class, 'simulateWebhook'])->name('api.payment.simulate-webhook');

// ✅ NOUVEAU: Route publique pour vérifier le statut des paiements supplémentaires après redirection
// Permet de vérifier le statut sans authentification car la session peut être perdue après redirection externe
Route::get('/additional-payments/{additionalPaymentId}/check-status-public', [OrderController::class, 'checkAdditionalPaymentStatusPublic'])->name('additional-payments.check-status-public');
Route::post('/payment/webhook-additional-cards', [OrderController::class, 'paymentWebhookAdditionalCards'])->name('api.payment.webhook.additional.cards');

// ✅ NOUVEAU: Route publique pour échanger un token de paiement contre une session
// Le frontend appelle cette route après avoir reçu le token dans l'URL
// ✅ CRITIQUE: Utiliser les middlewares 'web' et 'EnsureFrontendRequestsAreStateful' 
// pour établir correctement la session avec les cookies après une redirection externe
Route::middleware(['web', \App\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->post('/auth/exchange-token', [AuthController::class, 'exchangeToken'])
    ->name('auth.exchange-token');

// --- Routes Protégées (Nécessitent une authentification Sanctum valide et compte non suspendu) ---
Route::middleware(['auth:sanctum', 'not_suspended'])->group(function () {
    // Authentification & Déconnexion
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    
    // Finalisation du profil (pour utilisateurs Google)
    Route::post('/complete-profile', [AuthController::class, 'completeProfile'])->name('profile.complete');
    Route::get('/existing-account-types', [AuthController::class, 'getExistingAccountTypes'])->name('account.types');

    // Gestion du Compte (Informations de connexion)
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');     // Mettre à jour nom, email, téléphone, mot de passe
    Route::post('/account/verify-email-change', [AccountController::class, 'verifyEmailChange'])->name('account.verify-email-change'); // Vérifier le code de changement d'email
    Route::post('/account/resend-email-change-code', [AccountController::class, 'resendEmailChangeCode'])->name('account.resend-email-change-code'); // Renvoyer le code de changement d'email
    Route::post('/account/toggle-two-factor', [AccountController::class, 'toggleTwoFactor'])->name('account.toggle-two-factor'); // Activer/désactiver la 2FA
    Route::get('/account/linked-accounts', [AccountController::class, 'getLinkedAccounts'])->name('account.linked-accounts'); // Récupérer tous les comptes liés
    Route::post('/account/create-linked-account', [AccountController::class, 'createLinkedAccount'])->name('account.create-linked-account'); // Créer un nouveau compte lié
    Route::post('/account/switch-to-linked-account', [AccountController::class, 'switchToLinkedAccount'])->name('account.switch-to-linked-account'); // Basculer vers un compte lié sans mot de passe

    // Gestion du Profil (Paramétrage)
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');       // Lire les données du profil actuel
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');     // Mettre à jour les données textuelles du profil
    Route::post('/user/avatar', [ProfileController::class, 'updateAvatar'])->name('avatar.update'); // Mettre à jour la photo de profil
    
    // Gestion des Préférences Utilisateur
    Route::get('/user/preferences', [UserPreferencesController::class, 'show'])->name('user.preferences.show');
    Route::post('/user/preferences', [UserPreferencesController::class, 'update'])->name('user.preferences.update');

    // Cartes du dashboard (visibilité conditionnelle)
    Route::get('/dashboard/cards', [FrontendDashboardController::class, 'getCards'])->name('dashboard.cards');


    // Gestion des Employés (pour les admins d'entreprise)
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    Route::post('/employees/{employee}/add-card', [EmployeeController::class, 'addCard'])->name('employees.add-card');
    Route::post('/employees/{employee}/remove-card', [EmployeeController::class, 'removeCard'])->name('employees.remove-card');
    Route::post('/employees/{employee}/reset-device', [EmployeeController::class, 'resetDevice'])->name('employees.reset-device');
    // Assigner un employé à un slot
    Route::post('/orders/{orderId}/slots/{slotNumber}/assign', [EmployeeController::class, 'assignSlot'])->name('orders.slots.assign');

    // Employé: Définition du mot de passe initial
    Route::post('/employee/set-password', [EmployeeController::class, 'setPassword'])->name('employee.set-password');

    // Gestion des Commandes
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/check-payment', [OrderController::class, 'checkPaymentStatus'])->name('orders.check-payment');
    // ✅ NOUVEAU: Endpoint léger pour vérifier le statut de paiement (pour le polling)
    Route::get('/orders/{order}/status', [OrderController::class, 'getOrderStatus'])->name('orders.status');
    Route::get('/additional-payments/{additionalPaymentId}/check-status', [OrderController::class, 'checkAdditionalPaymentStatus'])->name('additional-payments.check-status');
    Route::patch('/orders/{order}/configure', [OrderController::class, 'markAsConfigured'])->name('orders.configure');
    Route::post('/orders/{order}/seal-device', [OrderController::class, 'sealDevice'])->name('orders.seal-device');
    Route::patch('/orders/{order}/profile', [OrderController::class, 'updateProfile'])->name('orders.profile.update');
    Route::patch('/orders/{order}/security-groups', [OrderController::class, 'updateSecurityGroups'])->name('orders.security-groups.update');

    // Rapports d'assiduité (pointage) — business_admin
    Route::get('/business/reports/attendance', [AttendanceReportController::class, 'attendance'])->name('business.reports.attendance');
    Route::get('/business/reports/attendance/export', [AttendanceReportController::class, 'export'])->name('business.reports.attendance.export');
    Route::post('/orders/{order}/avatar', [OrderController::class, 'uploadOrderAvatar'])->name('orders.avatar.upload');
    Route::post('/orders/{order}/use-profile-avatar', [OrderController::class, 'useProfileAvatar'])->name('orders.avatar.use-profile');
    Route::post('/orders/upload-custom-design', [OrderController::class, 'uploadCustomDesign'])->name('orders.upload-custom-design');
    Route::post('/orders/{order}/validate', [OrderController::class, 'validate'])->name('orders.validate');
    Route::post('/orders/{order}/add-cards', [OrderController::class, 'addCards'])->name('orders.add-cards');
    Route::delete('/orders/{order}', [OrderController::class, 'cancel'])->name('orders.cancel');

    // Gestion de la Page Entreprise (pour les business_admin)
    Route::get('/company-page', [CompanyPageController::class, 'index'])->name('company-page.index');
    Route::put('/company-page', [CompanyPageController::class, 'update'])->name('company-page.update');
    Route::post('/company-page/logo', [CompanyPageController::class, 'uploadLogo'])->name('company-page.upload-logo');
    Route::post('/company-page/generate-content', [CompanyPageController::class, 'generateContent'])->name('company-page.generate-content');
    Route::post('/company-page/extract-presentation', [CompanyPageController::class, 'extractPresentation'])->name('company-page.extract-presentation');

    // Gestion du Portfolio Personnel (pour les comptes particuliers)
    Route::get('/user-portfolio', [UserPortfolioController::class, 'index'])->name('user-portfolio.index');
    Route::put('/user-portfolio', [UserPortfolioController::class, 'update'])->name('user-portfolio.update');
    Route::post('/user-portfolio/photo', [UserPortfolioController::class, 'uploadPhoto'])->name('user-portfolio.photo');
    Route::post('/user-portfolio/generate-content', [UserPortfolioController::class, 'generateContent'])->name('user-portfolio.generate-content');
    Route::post('/user-portfolio/extract-document', [UserPortfolioController::class, 'extractDocument'])->name('user-portfolio.extract-document');
    
    // Recherche d'images pour les plats et boissons
    Route::get('/image-search', [ImageSearchController::class, 'searchImage'])->name('image.search');

    // --- Routes Rendez-vous (Protégées) ---
    // Récupérer la configuration des rendez-vous de l'utilisateur connecté
    Route::get('/appointment-settings', [AppointmentController::class, 'getSettings'])->name('appointment-settings.show');
    // Mettre à jour la configuration des rendez-vous
    Route::put('/appointment-settings', [AppointmentController::class, 'updateSettings'])->name('appointment-settings.update');
    // Récupérer tous les rendez-vous de l'utilisateur connecté
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    // Annuler un rendez-vous
    Route::put('/appointments/{id}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
    // Compter les rendez-vous non téléchargés
    Route::get('/appointments/not-downloaded/count', [AppointmentController::class, 'countNotDownloaded'])->name('appointments.not-downloaded.count');
    // Télécharger tous les rendez-vous non téléchargés au format ICS (AVANT la route avec paramètre)
    Route::get('/appointments/download-all', [AppointmentController::class, 'downloadAllIcs'])->name('appointments.download-all');
    // Télécharger un rendez-vous au format ICS
    Route::get('/appointments/{id}/download', [AppointmentController::class, 'downloadIcs'])->name('appointments.download');

    // --- Routes Contacts Partagés (Protégées) ---
    // Liste des contacts partagés reçus
    Route::get('/shared-contacts', [SharedContactController::class, 'index'])->name('shared-contacts.index');
    // Télécharger tous les nouveaux contacts en un seul fichier vCard (AVANT la route avec paramètre)
    Route::get('/shared-contacts/download-all', [SharedContactController::class, 'downloadAllVCards'])->name('shared-contacts.download-all');
    // Télécharger un contact au format vCard
    Route::get('/shared-contacts/{contact}/download', [SharedContactController::class, 'downloadVCard'])->name('shared-contacts.download');
    // Supprimer un contact
    Route::delete('/shared-contacts/{contact}', [SharedContactController::class, 'destroy'])->name('shared-contacts.destroy');

    // --- Routes Marketplace (Protégées) ---
    // Liste de toutes les offres disponibles
    Route::get('/marketplace/offers', [MarketplaceController::class, 'index'])->name('marketplace.offers.index');
    // Détails d'une offre avec ses avis
    Route::get('/marketplace/offers/{id}', [MarketplaceController::class, 'show'])->name('marketplace.offers.show');
    // Générer la description d'une offre avec l'IA à partir d'une image
    Route::post('/marketplace/generate-description', [MarketplaceController::class, 'generateDescriptionFromImage'])->name('marketplace.generate-description');
    // Créer une nouvelle offre
    Route::post('/marketplace/offers', [MarketplaceController::class, 'store'])->name('marketplace.offers.store');
    // Ajouter/Retirer des favoris
    Route::post('/marketplace/offers/{id}/toggle-favorite', [MarketplaceController::class, 'toggleFavorite'])->name('marketplace.offers.toggle-favorite');
    // Ajouter un avis à une offre
    Route::post('/marketplace/offers/{id}/reviews', [MarketplaceController::class, 'addReview'])->name('marketplace.offers.reviews');
    // Ajouter au panier
    Route::post('/marketplace/offers/{id}/add-to-cart', [MarketplaceController::class, 'addToCart'])->name('marketplace.offers.add-to-cart');
    // Envoyer un message à l'annonceur
    Route::post('/marketplace/send-message', [MarketplaceController::class, 'sendMessage'])->name('marketplace.send-message');
    // Mettre à jour une offre
    Route::put('/marketplace/offers/{id}', [MarketplaceController::class, 'update'])->name('marketplace.offers.update');
    // Supprimer une offre
    Route::delete('/marketplace/offers/{id}', [MarketplaceController::class, 'destroy'])->name('marketplace.offers.destroy');
    // Récupérer les statistiques d'une offre
    Route::get('/marketplace/offers/{id}/stats', [MarketplaceController::class, 'getStats'])->name('marketplace.offers.stats');
    // Récupérer tous les messages d'une offre (pour le vendeur)
    Route::get('/marketplace/offers/{id}/messages', [MarketplaceController::class, 'getOfferMessages'])->name('marketplace.offers.messages');
    // Répondre à un message
    Route::post('/marketplace/messages/{messageId}/reply', [MarketplaceController::class, 'replyToMessage'])->name('marketplace.messages.reply');
    // Récupérer tous les messages de l'utilisateur
    Route::get('/marketplace/messages', [MarketplaceController::class, 'getUserMessages'])->name('marketplace.messages.user');
});

// Routes publiques pour afficher les pages
Route::get('/company/{username}', [CompanyPageController::class, 'show'])->name('company-page.show');
Route::get('/e/{code}', [CompanyPageController::class, 'showByCode'])->name('company-page.show-by-code');
Route::get('/portfolio/{username}', [UserPortfolioController::class, 'show'])->name('user-portfolio.show');
Route::get('/portfolio/{username}/menu', [UserPortfolioController::class, 'showMenu'])->name('user-portfolio.menu');

// --- Routes Rendez-vous (Publiques) ---
// Récupérer toutes les dates disponibles avec des créneaux
Route::get('/user/{user}/available-dates', [AppointmentController::class, 'getAvailableDates'])->name('appointments.available-dates');
// Récupérer les créneaux disponibles pour un utilisateur à une date donnée
Route::get('/user/{user}/slots', [AppointmentController::class, 'getPublicSlots'])->name('appointments.slots');
// Réserver un rendez-vous chez un utilisateur
Route::post('/user/{user}/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
// ✅ Annuler un rendez-vous par token (route publique pour le demandeur)
Route::get('/appointments/cancel/{token}', [AppointmentController::class, 'cancelByToken'])->name('appointments.cancel-by-token');

// --- Routes Échange de Contact (Publique) ---
// Partager son contact avec un utilisateur (route publique sans auth ni CSRF)
Route::post('/user/{user}/share-contact', [SharedContactController::class, 'store'])->name('shared-contacts.store');

// --- Routes Admin Publiques (Authentification) ---
Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login');
});

// --- Routes Admin Protégées ---
Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    // Authentification
    Route::get('/me', [AdminAuthController::class, 'me'])->name('me');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
});

// --- Routes Admin (Super Admin uniquement) ---
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard & Statistiques
    Route::get('/stats', [DashboardController::class, 'stats'])->name('stats');
    
    // Gestion des utilisateurs
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
    Route::post('/users/{user}/toggle-two-factor', [AdminUserController::class, 'toggleTwoFactor'])->name('users.toggle-two-factor');
    Route::post('/users/{user}/impersonate', [AdminUserController::class, 'impersonate'])->name('users.impersonate');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
    
    // Gestion des commandes
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/stats', [AdminOrderController::class, 'stats'])->name('orders.stats');
    Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
    
    // Notifications
    Route::get('/notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('notifications.index');
    
    // Modération des pages entreprise
    Route::get('/company-pages', [AdminCompanyPageController::class, 'index'])->name('company-pages.index');
    Route::get('/company-pages/stats', [AdminCompanyPageController::class, 'stats'])->name('company-pages.stats');
    Route::get('/company-pages/{page}', [AdminCompanyPageController::class, 'show'])->name('company-pages.show');
    Route::patch('/company-pages/{page}/toggle', [AdminCompanyPageController::class, 'togglePublish'])->name('company-pages.toggle');
    Route::delete('/company-pages/{page}', [AdminCompanyPageController::class, 'destroy'])->name('company-pages.destroy');
    
    // Paramètres et Configuration
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/status', [SettingsController::class, 'getSystemStatus'])->name('settings.status');
    Route::post('/settings/maintenance', [SettingsController::class, 'toggleMaintenance'])->name('settings.maintenance');
    Route::post('/settings/cache/clear', [SettingsController::class, 'clearCache'])->name('settings.cache.clear');

    // CMS Accueil
    Route::get('/homepage', [SettingsController::class, 'getHomepage'])->name('homepage.get');
    Route::post('/homepage', [SettingsController::class, 'updateHomepage'])->name('homepage.update');
    Route::post('/homepage/upload-testimonial-avatar', [SettingsController::class, 'uploadTestimonialAvatar'])->name('homepage.upload-testimonial-avatar');
    Route::post('/homepage/upload-social-proof-logo', [SettingsController::class, 'uploadSocialProofLogo'])->name('homepage.upload-social-proof-logo');
});

// Route pour arrêter l'impersonation (accessible sans être admin)
Route::middleware('auth:sanctum')->post('/stop-impersonating', [AdminUserController::class, 'stopImpersonating'])->name('stop-impersonating');
