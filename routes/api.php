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

// Webhook pour Chap Chap Pay (publique, sans authentification)
Route::post('/payment/webhook', [OrderController::class, 'paymentWebhook'])->name('api.payment.webhook');
Route::post('/payment/webhook-additional-cards', [OrderController::class, 'paymentWebhookAdditionalCards'])->name('api.payment.webhook.additional.cards');

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

    // Gestion du Profil (Paramétrage)
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');       // Lire les données du profil actuel
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');     // Mettre à jour les données textuelles du profil
    Route::post('/user/avatar', [ProfileController::class, 'updateAvatar'])->name('avatar.update'); // Mettre à jour la photo de profil


    // Gestion des Employés (pour les admins d'entreprise)
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    Route::post('/employees/{employee}/add-card', [EmployeeController::class, 'addCard'])->name('employees.add-card');
    Route::post('/employees/{employee}/remove-card', [EmployeeController::class, 'removeCard'])->name('employees.remove-card');
    // Assigner un employé à un slot
    Route::post('/orders/{orderId}/slots/{slotNumber}/assign', [EmployeeController::class, 'assignSlot'])->name('orders.slots.assign');

    // Employé: Définition du mot de passe initial
    Route::post('/employee/set-password', [EmployeeController::class, 'setPassword'])->name('employee.set-password');

    // Gestion des Commandes
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/check-payment', [OrderController::class, 'checkPaymentStatus'])->name('orders.check-payment');
    Route::get('/additional-payments/{additionalPaymentId}/check-status', [OrderController::class, 'checkAdditionalPaymentStatus'])->name('additional-payments.check-status');
    Route::patch('/orders/{order}/configure', [OrderController::class, 'markAsConfigured'])->name('orders.configure');
    Route::patch('/orders/{order}/profile', [OrderController::class, 'updateProfile'])->name('orders.profile.update');
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
});

// Routes publiques pour afficher les pages
Route::get('/company/{username}', [CompanyPageController::class, 'show'])->name('company-page.show');
Route::get('/portfolio/{username}', [UserPortfolioController::class, 'show'])->name('user-portfolio.show');

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
