<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyPageController extends Controller
{
    /**
     * Liste toutes les pages entreprise avec filtres
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = CompanyPage::query();

        // Filtre par statut de publication
        if ($request->has('is_published') && $request->is_published !== '') {
            $query->where('is_published', $request->is_published === 'true');
        }

        // Recherche par nom d'entreprise
        if ($request->has('company_name') && $request->company_name !== '') {
            $query->where('company_name', 'like', '%' . $request->company_name . '%');
        }

        // Filtre par user_id (business_admin)
        if ($request->has('user_id') && $request->user_id !== '') {
            $query->where('user_id', $request->user_id);
        }

        // Filtre par date de création (depuis)
        if ($request->has('created_from') && $request->created_from !== '') {
            $query->whereDate('created_at', '>=', $request->created_from);
        }

        // Filtre par date de création (jusqu'à)
        if ($request->has('created_to') && $request->created_to !== '') {
            $query->whereDate('created_at', '<=', $request->created_to);
        }

        // Recherche par email du propriétaire
        if ($request->has('owner_email') && $request->owner_email !== '') {
            $query->whereHas('user', function($q) use ($request) {
                $q->where('email', 'like', '%' . $request->owner_email . '%');
            });
        }

        // Chargement des relations
        $query->with(['user:id,name,email,username,company_name,avatar_url']);

        // Tri (par défaut : plus récentes en premier)
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination (20 par page par défaut)
        $perPage = $request->get('per_page', 20);
        $pages = $query->paginate($perPage);

        // Enrichir chaque page avec le nombre d'employés uniques (par email)
        $pages->getCollection()->transform(function ($page) {
            $businessAdminId = $page->user_id;
            
            // S'assurer que la relation user est chargée avec l'email
            if (!$page->relationLoaded('user')) {
                $page->load('user:id,name,email');
            }
            
            // Récupérer l'email du business admin (toujours inclus)
            $businessAdminEmail = $page->user->email ?? null;
            
            // Créer un tableau d'emails uniques (commencer avec le business admin)
            $uniqueEmails = [];
            if ($businessAdminEmail) {
                $uniqueEmails[] = $businessAdminEmail;
            }
            
            // Source 1 : Récupérer tous les order_employees des commandes business de ce business admin
            $businessOrders = \App\Models\Order::where('user_id', $businessAdminId)
                ->where('order_type', 'business')
                ->where('status', '!=', 'cancelled')
                ->pluck('id');
            
            if ($businessOrders->isNotEmpty()) {
                $orderEmployeeEmails = \App\Models\OrderEmployee::whereIn('order_id', $businessOrders)
                    ->whereNotNull('employee_email')
                    ->where('employee_email', '!=', '')
                    ->distinct()
                    ->pluck('employee_email')
                    ->unique()
                    ->values()
                    ->toArray();
                
                // Ajouter les emails des order_employees (sans doublons)
                foreach ($orderEmployeeEmails as $email) {
                    if ($email && !in_array($email, $uniqueEmails, true)) {
                        $uniqueEmails[] = $email;
                    }
                }
            }
            
            // Source 2 : Récupérer aussi les employés via la relation employees du User
            // (pour les employés qui ont été créés mais pas encore dans une commande)
            try {
                $businessAdmin = \App\Models\User::find($businessAdminId);
                if ($businessAdmin) {
                    $directEmployees = $businessAdmin->employees()
                        ->whereNotNull('email')
                        ->where('email', '!=', '')
                        ->pluck('email')
                        ->unique()
                        ->values()
                        ->toArray();
                    
                    // Ajouter les emails des employés directs (sans doublons)
                    foreach ($directEmployees as $email) {
                        if ($email && !in_array($email, $uniqueEmails, true)) {
                            $uniqueEmails[] = $email;
                        }
                    }
                }
            } catch (\Exception $e) {
                // En cas d'erreur, continuer avec les données déjà collectées
                \Log::warning('Erreur lors de la récupération des employés directs', [
                    'business_admin_id' => $businessAdminId,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Le nombre total de personnel = nombre d'emails uniques (employés + business admin)
            $page->total_personnel = count($uniqueEmails);
            
            return $page;
        });

        // Statistiques globales
        $stats = [
            'total_pages' => CompanyPage::count(),
            'published_pages' => CompanyPage::where('is_published', true)->count(),
            'unpublished_pages' => CompanyPage::where('is_published', false)->count(),
            'pages_with_logo' => CompanyPage::whereNotNull('logo_url')->count(),
            'pages_with_services' => CompanyPage::whereNotNull('services')->count(),
        ];

        return response()->json([
            'company_pages' => $pages,
            'stats' => $stats
        ]);
    }

    /**
     * Affiche les détails d'une page entreprise
     * 
     * @param CompanyPage $page
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(CompanyPage $page)
    {
        // Charger les relations
        $page->load(['user:id,name,email,username,company_name,avatar_url,role,vcard_phone,phone_numbers']);

        // Récupérer tous les membres de l'entreprise (business admin + employés)
        $businessAdminId = $page->user_id;
        $members = [];
        
        // 1. Ajouter le business admin
        if ($page->user) {
            $adminPhone = $page->user->vcard_phone ?? null;
            // Si pas de vcard_phone, essayer phone_numbers (tableau JSON)
            if (!$adminPhone && $page->user->phone_numbers && is_array($page->user->phone_numbers) && count($page->user->phone_numbers) > 0) {
                $adminPhone = $page->user->phone_numbers[0] ?? null;
            }
            
            $members[] = [
                'name' => $page->user->name,
                'email' => $page->user->email,
                'phone' => $adminPhone,
                'role' => 'business_admin',
                'is_business_admin' => true,
            ];
        }
        
        // 2. Récupérer les employés depuis order_employees (toutes les commandes business)
        $businessOrders = \App\Models\Order::where('user_id', $businessAdminId)
            ->where('order_type', 'business')
            ->where('status', '!=', 'cancelled')
            ->pluck('id');
        
        if ($businessOrders->isNotEmpty()) {
            // Récupérer tous les order_employees avec leurs emails uniques
            $orderEmployees = \App\Models\OrderEmployee::whereIn('order_id', $businessOrders)
                ->whereNotNull('employee_email')
                ->where('employee_email', '!=', '')
                ->select('employee_name', 'employee_email', 'employee_id')
                ->distinct()
                ->get()
                ->unique('employee_email');
            
            // Pour chaque employé unique, récupérer les informations complètes depuis la table users si possible
            foreach ($orderEmployees as $orderEmployee) {
                // Vérifier si cet employé n'est pas le business admin lui-même
                if ($orderEmployee->employee_email === $page->user->email) {
                    continue;
                }
                
                // Essayer de récupérer les informations depuis la table users
                $employeeUser = \App\Models\User::where('email', $orderEmployee->employee_email)
                    ->select('id', 'name', 'email', 'vcard_phone', 'phone_numbers')
                    ->first();
                
                $employeeName = $employeeUser->name ?? $orderEmployee->employee_name ?? 'N/A';
                $employeePhone = null;
                
                if ($employeeUser) {
                    // Utiliser vcard_phone en priorité
                    $employeePhone = $employeeUser->vcard_phone ?? null;
                    // Sinon, utiliser phone_numbers (tableau JSON)
                    if (!$employeePhone && $employeeUser->phone_numbers && is_array($employeeUser->phone_numbers) && count($employeeUser->phone_numbers) > 0) {
                        $employeePhone = $employeeUser->phone_numbers[0] ?? null;
                    }
                }
                
                $members[] = [
                    'name' => $employeeName,
                    'email' => $orderEmployee->employee_email,
                    'phone' => $employeePhone,
                    'role' => 'employee',
                    'is_business_admin' => false,
                ];
            }
        }
        
        // 3. Récupérer aussi les employés directs via la relation employees (pour ceux qui ne sont pas encore dans une commande)
        try {
            $businessAdmin = \App\Models\User::find($businessAdminId);
            if ($businessAdmin) {
                $directEmployees = $businessAdmin->employees()
                    ->whereNotNull('email')
                    ->where('email', '!=', '')
                    ->select('id', 'name', 'email', 'vcard_phone', 'phone_numbers')
                    ->get();
                
                foreach ($directEmployees as $directEmployee) {
                    // Vérifier si cet employé n'est pas déjà dans la liste
                    $exists = false;
                    $memberIndex = -1;
                    foreach ($members as $index => $member) {
                        if ($member['email'] === $directEmployee->email) {
                            $exists = true;
                            $memberIndex = $index;
                            break;
                        }
                    }
                    
                    if ($exists && $memberIndex >= 0) {
                        // Mettre à jour le numéro de téléphone si disponible et manquant
                        if (!$members[$memberIndex]['phone'] && ($directEmployee->vcard_phone || ($directEmployee->phone_numbers && is_array($directEmployee->phone_numbers) && count($directEmployee->phone_numbers) > 0))) {
                            $members[$memberIndex]['phone'] = $directEmployee->vcard_phone ?? ($directEmployee->phone_numbers[0] ?? null);
                        }
                    } elseif (!$exists) {
                        // Ajouter l'employé s'il n'existe pas déjà
                        $employeePhone = $directEmployee->vcard_phone ?? null;
                        if (!$employeePhone && $directEmployee->phone_numbers && is_array($directEmployee->phone_numbers) && count($directEmployee->phone_numbers) > 0) {
                            $employeePhone = $directEmployee->phone_numbers[0] ?? null;
                        }
                        
                        $members[] = [
                            'name' => $directEmployee->name,
                            'email' => $directEmployee->email,
                            'phone' => $employeePhone,
                            'role' => 'employee',
                            'is_business_admin' => false,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Erreur lors de la récupération des employés directs dans show', [
                'business_admin_id' => $businessAdminId,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Trier les membres : business admin en premier, puis les employés par nom
        usort($members, function ($a, $b) {
            if ($a['is_business_admin'] && !$b['is_business_admin']) {
                return -1;
            }
            if (!$a['is_business_admin'] && $b['is_business_admin']) {
                return 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        // Analyser le contenu de la page
        $contentAnalysis = [
            'has_logo' => !empty($page->logo_url),
            'has_colors' => !empty($page->primary_color),
            'services_count' => is_array($page->services) ? count($page->services) : 0,
            'has_hero_section' => !empty($page->hero_headline),
            'has_chart' => !empty($page->chart_labels),
            'pillars_count' => is_array($page->pillars) ? count($page->pillars) : 0,
            'has_process_order' => !empty($page->process_order_steps),
            'has_process_logistics' => !empty($page->process_logistics_steps),
            'has_contact' => !empty($page->contact_email),
            'completion_percentage' => $this->calculateCompletionPercentage($page),
        ];

        return response()->json([
            'company_page' => $page,
            'content_analysis' => $contentAnalysis,
            'members' => $members,
        ]);
    }

    /**
     * Toggle la publication d'une page entreprise
     * 
     * @param CompanyPage $page
     * @return \Illuminate\Http\JsonResponse
     */
    public function togglePublish(CompanyPage $page)
    {
        $wasPublished = $page->is_published;
        $page->is_published = !$page->is_published;
        $page->save();

        // Logger l'action
        Log::info('Admin company page publication toggled', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'page_id' => $page->id,
            'company_name' => $page->company_name,
            'user_id' => $page->user_id,
            'user_email' => $page->user->email,
            'was_published' => $wasPublished,
            'is_published' => $page->is_published,
            'timestamp' => now(),
        ]);

        $message = $page->is_published 
            ? 'Page entreprise publiée avec succès' 
            : 'Page entreprise dépubliée avec succès';

        return response()->json([
            'message' => $message,
            'company_page' => $page->fresh(['user:id,name,email'])
        ]);
    }

    /**
     * Supprime une page entreprise (modération)
     * 
     * @param CompanyPage $page
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(CompanyPage $page)
    {
        // Sauvegarder les infos avant suppression pour le log
        $pageData = [
            'page_id' => $page->id,
            'company_name' => $page->company_name,
            'user_id' => $page->user_id,
            'user_email' => $page->user->email,
        ];

        // Supprimer la page
        $page->delete();

        // Logger l'action
        Log::warning('Admin company page deletion', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'deleted_page' => $pageData,
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'Page entreprise supprimée avec succès'
        ]);
    }

    /**
     * Calcule le pourcentage de complétion d'une page
     * 
     * @param CompanyPage $page
     * @return int
     */
    private function calculateCompletionPercentage(CompanyPage $page): int
    {
        $fields = [
            'company_name',
            'logo_url',
            'primary_color',
            'services',
            'hero_headline',
            'hero_subheadline',
            'hero_description',
            'chart_labels',
            'chart_data',
            'pillars',
            'engagement_description',
            'contact_email',
        ];

        $completedFields = 0;
        $totalFields = count($fields);

        foreach ($fields as $field) {
            if (!empty($page->$field)) {
                $completedFields++;
            }
        }

        return round(($completedFields / $totalFields) * 100);
    }

    /**
     * Statistiques globales des pages entreprise
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats()
    {
        $stats = [
            // Compteurs globaux
            'total_pages' => CompanyPage::count(),
            'published_pages' => CompanyPage::where('is_published', true)->count(),
            'unpublished_pages' => CompanyPage::where('is_published', false)->count(),

            // Analyse de contenu
            'pages_with_logo' => CompanyPage::whereNotNull('logo_url')->count(),
            'pages_with_services' => CompanyPage::whereNotNull('services')
                ->where('services', '!=', '[]')
                ->count(),
            'pages_with_chart' => CompanyPage::whereNotNull('chart_labels')->count(),
            'pages_with_pillars' => CompanyPage::whereNotNull('pillars')
                ->where('pillars', '!=', '[]')
                ->count(),

            // Services moyens par page
            'average_services_per_page' => CompanyPage::whereNotNull('services')
                ->get()
                ->average(function($page) {
                    return is_array($page->services) ? count($page->services) : 0;
                }),

            // Tendances (7 derniers jours)
            'pages_created_last_7_days' => CompanyPage::where('created_at', '>=', now()->subDays(7))->count(),
            'pages_published_last_7_days' => CompanyPage::where('is_published', true)
                ->where('updated_at', '>=', now()->subDays(7))
                ->count(),

            // Top entreprises (par nombre de services)
            'top_companies_by_services' => CompanyPage::whereNotNull('services')
                ->with('user:id,name,email')
                ->get()
                ->sortByDesc(function($page) {
                    return is_array($page->services) ? count($page->services) : 0;
                })
                ->take(5)
                ->values(),

            // Dernières pages créées
            'recent_pages' => CompanyPage::with('user:id,name,email,company_name')
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get(),
        ];

        return response()->json($stats);
    }
}
