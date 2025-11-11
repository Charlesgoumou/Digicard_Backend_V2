<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="format-detection" content="telephone=no">
    <meta name="x-apple-disable-message-reformatting">
    <title>Confirmation de votre commande #{{ $order->order_number }} - Arcc En Ciel</title>
    <style>
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 700px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .header p {
            margin: 10px 0 0;
            font-size: 16px;
            opacity: 0.95;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #1f2937;
            margin-bottom: 20px;
        }
        .order-summary {
            background-color: #f8fafc;
            border-left: 4px solid #0ea5e9;
            padding: 20px;
            margin: 25px 0;
            border-radius: 5px;
        }
        .order-summary h2 {
            margin: 0 0 15px;
            color: #1f2937;
            font-size: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .order-info {
            margin: 10px 0;
        }
        .order-info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .order-info-row:last-child {
            border-bottom: none;
        }
        .order-info-label {
            font-weight: 600;
            color: #475569;
        }
        .order-info-value {
            color: #1f2937;
            font-weight: 500;
        }
        .total-row {
            background-color: #dbeafe;
            padding: 12px;
            margin-top: 10px;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
        }
        .cgu-section {
            margin-top: 40px;
            padding: 30px;
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .cgu-section h2 {
            color: #0ea5e9;
            font-size: 22px;
            margin-top: 0;
            text-align: center;
            border-bottom: 2px solid #0ea5e9;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .cgu-section h3 {
            color: #1f2937;
            font-size: 16px;
            margin-top: 20px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .cgu-section p, .cgu-section ul {
            font-size: 14px;
            color: #475569;
            line-height: 1.8;
        }
        .cgu-section ul {
            padding-left: 20px;
        }
        .cgu-section li {
            margin-bottom: 8px;
        }
        .company-info {
            background-color: #f1f5f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #64748b;
        }
        .company-info strong {
            color: #334155;
        }
        .footer {
            background-color: #1e293b;
            color: #cbd5e1;
            text-align: center;
            padding: 30px 20px;
            font-size: 14px;
        }
        .footer a {
            color: #38bdf8;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .button {
            display: inline-block;
            background-color: #0ea5e9;
            color: #ffffff;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 20px;
        }
        .button:hover {
            background-color: #0284c7;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                border-radius: 0;
            }
            .content, .header {
                padding: 25px 20px;
            }
            .order-info-row {
                flex-direction: column;
            }
            .order-info-label {
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- En-tête -->
        <div class="header">
            <h1 style="margin: 0; font-size: 28px; font-weight: bold;">Confirmation de commande</h1>
            <p style="margin: 10px 0 0; font-size: 16px; opacity: 0.95;">Votre commande a été validée avec succès</p>
        </div>

        <!-- Contenu principal -->
        <div class="content">
            <div class="greeting">
                Bonjour {{ $user->name }},
            </div>

            <p style="margin: 0 0 20px; color: #1f2937; line-height: 1.6;">
                Nous vous confirmons que votre commande de cartes de visite digitales a été validée avec succès.
            </p>
            
            <p style="margin: 0 0 20px; color: #1f2937; line-height: 1.6;">
                Votre numéro de commande est : <strong style="color: #0ea5e9;">#{{ $order->order_number }}</strong>
            </p>

            <!-- Résumé de la commande -->
            <div class="order-summary">
                <h2 style="margin: 0 0 15px; color: #1f2937; font-size: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Résumé de votre commande</h2>

                <div class="order-info">
                    <div class="order-info-row">
                        <span class="order-info-label">Numéro de commande :</span>
                        <span class="order-info-value">#{{ $order->order_number }}</span>
                    </div>

                    <div class="order-info-row">
                        <span class="order-info-label">Type de commande :</span>
                        <span class="order-info-value">
                            @if($order->order_type === 'business')
                                Entreprise
                            @else
                                Personnel
                            @endif
                        </span>
                    </div>

                    <div class="order-info-row">
                        <span class="order-info-label">Nombre de cartes :</span>
                        <span class="order-info-value">{{ $order->card_quantity }} carte{{ $order->card_quantity > 1 ? 's' : '' }}</span>
                    </div>

                    <div class="order-info-row">
                        <span class="order-info-label">Première carte :</span>
                        <span class="order-info-value">{{ number_format($basePrice, 0, ',', ' ') }} GNF</span>
                    </div>

                    @if($order->card_quantity > 1)
                    <div class="order-info-row">
                        <span class="order-info-label">Cartes supplémentaires ({{ $order->card_quantity - 1 }} × {{ number_format($extraPrice, 0, ',', ' ') }} GNF) :</span>
                        <span class="order-info-value">{{ number_format(($order->card_quantity - 1) * $extraPrice, 0, ',', ' ') }} GNF</span>
                    </div>
                    @endif

                    <div class="total-row">
                        <div class="order-info-row">
                            <span class="order-info-label">Montant total à payer :</span>
                            <span class="order-info-value">{{ number_format($order->total_price, 0, ',', ' ') }} GNF</span>
                        </div>
                    </div>

                    <div class="order-info-row" style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #cbd5e1;">
                        <span class="order-info-label">Abonnement annuel :</span>
                        <span class="order-info-value">{{ number_format($annualPrice, 0, ',', ' ') }} GNF/an</span>
                    </div>

                    <div class="order-info-row">
                        <span class="order-info-label">Date de début d'abonnement :</span>
                        <span class="order-info-value">{{ \Carbon\Carbon::parse($order->subscription_start_date)->format('d/m/Y') }}</span>
                    </div>
                </div>
            </div>

            <div style="background-color: #dbeafe; padding: 20px; border-radius: 8px; margin: 25px 0; border-left: 4px solid #0ea5e9;">
                <p style="margin: 0 0 10px; font-weight: bold; color: #1f2937; font-size: 16px;">
                    Informations de livraison
                </p>
                <p style="margin: 0; color: #475569; line-height: 1.6;">
                    Votre commande sera livrée dans un délai de <strong>48 heures</strong> après validation. 
                    La livraison est <strong>gratuite</strong> partout à Conakry.
                </p>
            </div>

            <p>
                <strong>Prochaines étapes :</strong><br>
                Vous pouvez maintenant paramétrer votre carte de visite digitale en vous connectant à votre espace client.
            </p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ config('app.frontend_url', url('/')) }}/dashboard" class="button" style="text-decoration: none;">
                    Accéder à mon espace
                </a>
            </div>
            
            <p style="margin-top: 30px; font-size: 14px; color: #64748b; text-align: center;">
                Si vous avez des questions concernant votre commande, n'hésitez pas à nous contacter à 
                <a href="mailto:contact@arccenciel.com" style="color: #0ea5e9; text-decoration: none;">contact@arccenciel.com</a> 
                ou par téléphone au <a href="tel:+224613615732" style="color: #0ea5e9; text-decoration: none;">+224 613615732</a>.
            </p>

            <!-- Section CGU -->
            <div class="cgu-section">
                <h2 style="color: #0ea5e9; font-size: 22px; margin-top: 0; text-align: center; border-bottom: 2px solid #0ea5e9; padding-bottom: 15px; margin-bottom: 20px;">Conditions Générales d'Utilisation</h2>

                <div class="company-info">
                    <strong>ARCC EN CIEL SARLU</strong> ("le Prestataire")<br>
                    Société à Responsabilité Limitée à Associé Unique (SARLU)<br>
                    Capital : 1 000 000 GNF<br>
                    RCCM : GN.TCC.2025.B.10711<br>
                    Siège social : Lambanyi, Commune de Lambanyi, Conakry, République de Guinée<br>
                    Contact : +224 613615732 / +224 661345345<br>
                    Email : contact@arccenciel.com
                </div>

                <p style="text-align: center; font-style: italic; color: #64748b;">
                    Service de Cartes de Visite Digitales Intelligentes
                </p>

                <h3>Article 1 : Objet</h3>
                <p>
                    Les présentes conditions décrivent le fonctionnement, les caractéristiques et les modalités d'utilisation
                    du service de cartes de visite digitales intelligentes proposé par ARCC EN CIEL SARLU.
                </p>

                <h3>Article 2 : Description du Service</h3>
                <p>Le service consiste en la fourniture d'une solution de communication professionnelle interactive comprenant :</p>
                <ul>
                    <li><strong>La Carte de Visite Physique :</strong> Une carte en PVC au format standard, équipée d'une puce de technologie sans contact (NFC). La carte est imprimée avec le design, le logo et la charte graphique fournis par l'utilisateur, et dans le cas contraire, un design lui sera proposé.</li>
                    <li><strong>La Page de Présentation Digitale :</strong> Une page web personnelle et personnalisée avec la charte graphique de l'utilisateur, hébergée par le Prestataire, accessible en approchant un smartphone de la carte (NFC) ou en scannant un QR code imprimé.</li>
                    <li><strong>Fonctionnalités de la Page :</strong> La page web est conçue pour regrouper les informations clés de l'utilisateur :
                        <ul>
                            <li>Une photo de profil et l'identité (nom, prénom, fonction)</li>
                            <li>Un bouton "Enregistrer le contact" permettant de télécharger la fiche contact (.vcf) complète de l'utilisateur</li>
                            <li>Un bouton "Découvrir nos services" (pour les professionnels) ou "Mon Portfolio" (pour les particuliers) redirigeant vers un site web ou une page de présentation</li>
                            <li>Des icônes cliquables redirigeant vers les réseaux sociaux de l'utilisateur (Facebook, WhatsApp, LinkedIn)</li>
                        </ul>
                    </li>
                    <li><strong>L'Étui de Protection :</strong> Chaque carte est fournie avec un étui de protection anti-RFID pour garantir la sécurité des données en bloquant les signaux de la puce lorsque la carte n'est pas utilisée.</li>
                </ul>

                <h3>Article 3 : Souscription et Tarifs</h3>
                <p>L'accès au service est conditionné par une souscription :</p>
                <ul>
                    <li><strong>Première souscription :</strong> Le coût initial pour la création et la fourniture de la carte est de 180 000 GNF par utilisateur.</li>
                    <li><strong>Abonnement annuel :</strong> Un abonnement de 40 000 GNF par an et par utilisateur est requis pour maintenir l'hébergement et l'accessibilité de la page de présentation digitale.</li>
                    <li><strong>Duplicata :</strong> La production d'une ou plusieurs cartes pour les besoins de l'utilisateur, la production pour des cas de perte, de vol ou de dommage, est facturée à 45 000 GNF l'unité.</li>
                </ul>

                <h3>Article 4 : Montant de votre commande</h3>
                <div style="background-color: #dbeafe; padding: 15px; border-radius: 5px; margin: 15px 0;">
                    <p style="margin: 5px 0;"><strong>Montant total à payer :</strong> {{ number_format($order->total_price, 0, ',', ' ') }} GNF</p>
                    <p style="margin: 5px 0;"><strong>Abonnement annuel :</strong> {{ number_format($annualPrice, 0, ',', ' ') }} GNF/an</p>
                    @if($order->order_type === 'business')
                    <p style="margin: 5px 0; font-size: 13px; color: #475569;">
                        <em>Pour une commande entreprise de {{ $order->card_quantity }} carte{{ $order->card_quantity > 1 ? 's' : '' }}</em>
                    </p>
                    @else
                    <p style="margin: 5px 0; font-size: 13px; color: #475569;">
                        <em>Pour une commande personnelle de {{ $order->card_quantity }} carte{{ $order->card_quantity > 1 ? 's' : '' }}</em>
                    </p>
                    @endif
                </div>

                <h3>Article 5 : Durée et Renouvellement</h3>
                <p>
                    Le service est actif pour une durée d'un (1) an à compter de la date de validation de la commande.
                    Il est renouvelable chaque année par le paiement de l'abonnement. Le non-paiement de l'abonnement annuel
                    entraînera la désactivation de la page de présentation digitale associée à la carte.
                </p>

                <h3>Article 6 : Obligations de l'Utilisateur</h3>
                <p>
                    L'utilisateur du service s'engage à fournir des informations exactes pour la personnalisation de sa carte
                    et de sa page. Il est responsable du contenu affiché sur sa page et doit utiliser le service dans le respect
                    des lois en vigueur.
                </p>

                <h3>Article 7 : Obligations d'ARCC EN CIEL SARLU</h3>
                <p>Le Prestataire s'engage à :</p>
                <ul>
                    <li>Produire une carte conforme au design validé</li>
                    <li>Assurer l'hébergement et la disponibilité de la page de présentation digitale</li>
                    <li>Garantir le bon fonctionnement des technologies NFC et QR code intégrées</li>
                    <li>Protéger les données personnelles de l'utilisateur et ne pas les communiquer à des tiers</li>
                </ul>

                <h3>Article 8 : Modification des Informations</h3>
                <p>
                    L'utilisateur peut demander la mise à jour des informations de sa page de présentation en contactant
                    le support d'ARCC EN CIEL SARLU. Une plateforme de gestion autonome des informations sera mise en place ultérieurement.
                </p>

                <h3>Article 9 : Mises à Jour et Évolutions du Service</h3>
                <p>
                    L'abonnement annuel au service inclut l'accès à toutes les mises à jour et améliorations futures des
                    fonctionnalités de la plateforme sans coût additionnel. Cela comprend, de manière non exhaustive,
                    l'amélioration de l'interface de la page de présentation, l'ajout de nouvelles fonctionnalités interactives,
                    et l'accès à la future plateforme de gestion autonome des informations. ARCC EN CIEL SARLU reste seul
                    décisionnaire quant à la nature et au calendrier de déploiement de ces mises à jour.
                </p>

                <h3>Article 10 : Propriété Intellectuelle</h3>
                <p>
                    L'utilisateur reste propriétaire de son logo, de sa charte graphique et des contenus qu'il fournit.
                    Le Prestataire reste propriétaire de l'infrastructure technique et logicielle du service.
                </p>

                <h3>Article 11 : Droit Applicable et Juridiction</h3>
                <p>
                    Les présentes conditions d'utilisation sont régies par le droit en vigueur en République de Guinée.
                    En cas de litige, une solution à l'amiable sera recherchée en priorité. À défaut, le Tribunal de Commerce
                    de Conakry sera seul compétent.
                </p>
            </div>

            <p style="margin-top: 30px; font-size: 14px; color: #64748b;">
                Pour toute question concernant votre commande ou nos services, n'hésitez pas à nous contacter.
            </p>
        </div>

        <!-- Pied de page -->
        <div class="footer">
            <p style="margin: 0 0 10px;"><strong>ARCC EN CIEL SARLU</strong></p>
            <p style="margin: 0 0 5px;">Lambanyi, Commune de Lambanyi, Conakry, République de Guinée</p>
            <p style="margin: 0 0 5px;">Téléphone : <a href="tel:+224613615732" style="color: #38bdf8; text-decoration: none;">+224 613615732</a> / <a href="tel:+224661345345" style="color: #38bdf8; text-decoration: none;">+224 661345345</a></p>
            <p style="margin: 0 0 15px;">Email : <a href="mailto:contact@arccenciel.com" style="color: #38bdf8; text-decoration: none;">contact@arccenciel.com</a></p>
            <p style="margin: 0 0 10px; font-size: 13px;">
                <a href="{{ config('app.frontend_url', url('/')) }}/conditions-generales" style="color: #38bdf8; text-decoration: none; margin-right: 15px;">Conditions Générales</a>
                <a href="{{ config('app.frontend_url', url('/')) }}/politique-confidentialite" style="color: #38bdf8; text-decoration: none;">Politique de Confidentialité</a>
            </p>
            <p style="font-size: 12px; color: #94a3b8; margin: 10px 0 0;">
                © {{ date('Y') }} ARCC EN CIEL SARLU. Tous droits réservés.
            </p>
            <p style="font-size: 11px; color: #64748b; margin: 15px 0 0; line-height: 1.5; padding-top: 15px; border-top: 1px solid #334155;">
                Cet email transactionnel vous a été envoyé suite à la validation de votre commande #{{ $order->order_number }} sur notre plateforme. 
                Si vous n'avez pas effectué cette action, veuillez nous contacter immédiatement à <a href="mailto:contact@arccenciel.com" style="color: #38bdf8; text-decoration: none;">contact@arccenciel.com</a>.
            </p>
        </div>
    </div>
</body>
</html>

