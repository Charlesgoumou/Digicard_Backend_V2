<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="format-detection" content="telephone=no">
    <meta name="x-apple-disable-message-reformatting">
    <title>Cartes supplémentaires ajoutées - Commande #{{ $order->order_number }} - Arcc En Ciel</title>
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
        .success-box {
            background-color: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 20px;
            margin: 25px 0;
            border-radius: 5px;
        }
        .payment-summary {
            background-color: #f8fafc;
            border-left: 4px solid #10b981;
            padding: 20px;
            margin: 25px 0;
            border-radius: 5px;
        }
        .payment-summary h2 {
            margin: 0 0 15px;
            color: #1f2937;
            font-size: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .payment-info {
            margin: 10px 0;
        }
        .payment-info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .payment-info-row:last-child {
            border-bottom: none;
        }
        .payment-info-label {
            font-weight: 600;
            color: #475569;
        }
        .payment-info-value {
            color: #1f2937;
            font-weight: 500;
        }
        .total-row {
            background-color: #d1fae5;
            padding: 12px;
            margin-top: 10px;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
        }
        .info-section {
            background-color: #dbeafe;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
            border-left: 4px solid #0ea5e9;
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
            background-color: #10b981;
            color: #ffffff;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 20px;
        }
        .button:hover {
            background-color: #059669;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                border-radius: 0;
            }
            .content, .header {
                padding: 25px 20px;
            }
            .payment-info-row {
                flex-direction: column;
            }
            .payment-info-label {
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- En-tête -->
        <div class="header">
            <h1 style="margin: 0; font-size: 28px; font-weight: bold;">✅ Paiement Réussi !</h1>
            <p style="margin: 10px 0 0; font-size: 16px; opacity: 0.95;">Vos cartes supplémentaires ont été ajoutées avec succès</p>
        </div>

        <!-- Contenu principal -->
        <div class="content">
            <div class="greeting">
                Bonjour {{ $user->name }},
            </div>

            <div class="success-box">
                <p style="margin: 0; color: #1f2937; line-height: 1.6; font-size: 16px; font-weight: 500;">
                    🎉 Vos cartes supplémentaires ont été ajoutées avec succès à votre commande !
                </p>
            </div>

            <p style="margin: 0 0 20px; color: #1f2937; line-height: 1.6;">
                Nous vous confirmons que votre paiement pour les cartes supplémentaires a été validé avec succès.
            </p>

            <p style="margin: 0 0 20px; color: #1f2937; line-height: 1.6;">
                Votre numéro de commande est : <strong style="color: #10b981;">#{{ $order->order_number }}</strong>
            </p>

            <!-- Résumé du paiement -->
            <div class="payment-summary">
                <h2 style="margin: 0 0 15px; color: #1f2937; font-size: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Détails du paiement</h2>

                <div class="payment-info">
                    <div class="payment-info-row">
                        <span class="payment-info-label">Nombre de cartes ajoutées :</span>
                        <span class="payment-info-value">{{ $additionalPayment->quantity }} carte{{ $additionalPayment->quantity > 1 ? 's' : '' }}</span>
                    </div>

                    <div class="payment-info-row">
                        <span class="payment-info-label">Prix unitaire :</span>
                        <span class="payment-info-value">{{ number_format($additionalPayment->unit_price, 0, ',', ' ') }} GNF</span>
                    </div>

                    <div class="total-row">
                        <div class="payment-info-row">
                            <span class="payment-info-label">Montant payé :</span>
                            <span class="payment-info-value">{{ number_format($additionalPayment->total_price, 0, ',', ' ') }} GNF</span>
                        </div>
                    </div>

                    <div class="payment-info-row" style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #cbd5e1;">
                        <span class="payment-info-label">Date de paiement :</span>
                        <span class="payment-info-value">{{ $additionalPayment->paid_at ? \Carbon\Carbon::parse($additionalPayment->paid_at)->format('d/m/Y à H:i') : 'N/A' }}</span>
                    </div>
                </div>
            </div>

            <div class="info-section">
                <p style="margin: 0 0 10px; font-weight: bold; color: #1f2937; font-size: 16px;">
                    📦 Informations de livraison
                </p>
                <p style="margin: 0; color: #475569; line-height: 1.6;">
                    Les cartes supplémentaires ont été ajoutées à votre commande et seront livrées avec votre commande principale dans un délai de <strong>48 heures</strong> après validation.
                    La livraison est <strong>gratuite</strong> partout à Conakry.
                </p>
            </div>

            <p style="margin-top: 20px; color: #1f2937; line-height: 1.6;">
                <strong>Prochaines étapes :</strong><br>
                Vous pouvez maintenant paramétrer vos nouvelles cartes en vous connectant à votre espace client.
            </p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ config('app.frontend_url', url('/')) }}/dashboard" class="button" style="text-decoration: none;">
                    Accéder à mon espace
                </a>
            </div>

            <p style="margin-top: 30px; font-size: 14px; color: #64748b; text-align: center;">
                Si vous avez des questions concernant votre commande, n'hésitez pas à nous contacter à
                <a href="mailto:contact@arccenciel.com" style="color: #10b981; text-decoration: none;">contact@arccenciel.com</a>
                ou par téléphone au <a href="tel:+224613615732" style="color: #10b981; text-decoration: none;">+224 613615732</a>.
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
                Cet email transactionnel vous a été envoyé suite à l'ajout de cartes supplémentaires à votre commande #{{ $order->order_number }} sur notre plateforme.
                Si vous n'avez pas effectué cette action, veuillez nous contacter immédiatement à <a href="mailto:contact@arccenciel.com" style="color: #38bdf8; text-decoration: none;">contact@arccenciel.com</a>.
            </p>
        </div>
    </div>
</body>
</html>



