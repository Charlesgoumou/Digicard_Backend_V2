<!doctype html>
<html lang="fr">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>DigiCard</title>

    <meta
      name="description"
      content="DigiCard - La Carte de visite aux possibilités de personnalisation infinies. Générez votre contenu et partagez avec le monde en un simple geste sans contact."
    />

    <meta property="og:title" content="DigiCard" />
    <meta
      property="og:description"
      content="DigiCard - La Carte de visite aux possibilités de personnalisation infinies. Générez votre contenu et partagez avec le monde en un simple geste sans contact."
    />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://digicard.arccenciel.com/" />
    <meta property="og:image" content="https://digicard.arccenciel.com/logo2-512.png" />
    <meta property="og:image:width" content="512" />
    <meta property="og:image:height" content="512" />

    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="DigiCard" />
    <meta
      name="twitter:description"
      content="DigiCard - La Carte de visite aux possibilités de personnalisation infinies. Générez votre contenu et partagez avec le monde en un simple geste sans contact."
    />
    <meta name="twitter:image" content="https://digicard.arccenciel.com/logo2-512.png" />

    <link rel="icon" type="image/png" sizes="16x16" href="/logo2-16.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="/logo2-32.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="/logo2-180.png" />
    <link rel="icon" type="image/png" sizes="192x192" href="/logo2-192.png" />
    <link rel="icon" type="image/png" sizes="512x512" href="/logo2-512.png" />
    
    {{-- CSRF Token pour les requêtes AJAX --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
  </head>
  <body>
    <div id="app"></div>
    
    {{-- Charger l'application Vue.js depuis le serveur de développement Vite du frontend --}}
    {{-- Le frontend Vue.js doit être démarré avec 'npm run dev' dans le dossier frontend --}}
    {{-- Par défaut, Vite sert sur http://localhost:5173 --}}
    <script type="module">
      // ✅ MODIFICATION: Détecter si on vient d'un retour de paiement depuis Chap Chap Pay
      // et rediriger vers le frontend si nécessaire (car Chap Chap Pay peut rediriger vers le backend)
      const urlParams = new URLSearchParams(window.location.search);
      const paymentSuccess = urlParams.get('payment') === 'success';
      const orderId = urlParams.get('order_id');
      
      // ✅ Détecter l'URL du frontend selon l'environnement
      // En développement local : http://localhost:5173
      // En production : même domaine que le backend (APP_URL) ou FRONTEND_URL si défini
      let frontendUrl = '{{ env("FRONTEND_URL", "") }}';
      if (!frontendUrl) {
        @if(app()->environment('production'))
          // En production, utiliser APP_URL (même domaine)
          frontendUrl = '{{ config("app.url") }}';
        @else
          // En développement local, utiliser localhost:5173
          frontendUrl = 'http://localhost:5173';
        @endif
      }
      
      // Si on vient d'un retour de paiement, rediriger immédiatement vers le frontend
      if (paymentSuccess && orderId) {
        const redirectUrl = `${frontendUrl}/mes-commandes?payment=success&order_id=${orderId}`;
        console.log('Redirection vers le frontend après paiement:', redirectUrl);
        // Rediriger immédiatement sans charger Vue.js
        window.location.replace(redirectUrl);
        // Arrêter l'exécution du script
        return;
      }
      
      // Sinon, charger normalement l'application Vue.js depuis le serveur Vite du frontend
      // En développement local, charger depuis localhost:5173
      // En production, le frontend doit être compilé et servi depuis le même domaine
      let viteUrl = '{{ env("VITE_DEV_SERVER_URL", "") }}';
      if (!viteUrl) {
        @if(app()->environment('production'))
          // En production, le frontend est compilé et servi depuis le même domaine
          // Ne pas charger depuis un serveur de développement
          viteUrl = '';
        @else
          // En développement local, utiliser localhost:5173
          viteUrl = 'http://localhost:5173';
        @endif
      }
      
      if (viteUrl) {
        // Charger depuis le serveur de développement Vite
        import(viteUrl + '/src/main.js').catch(err => {
          console.error('Erreur lors du chargement de l\'application Vue.js:', err);
          console.error('Assurez-vous que le serveur de développement du frontend est démarré (npm run dev dans le dossier frontend)');
        });
      } else {
        // En production, le frontend doit être compilé et les fichiers doivent être dans public/
        // Charger depuis les assets compilés
        console.warn('Environnement de production détecté : le frontend doit être compilé et servi depuis public/');
      }
    </script>
  </body>
</html>
