<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cookie jeton pointage (profil public)
    |--------------------------------------------------------------------------
    |
    | Le jeton d’enrôlement est lu sur le profil public (souvent un sous-domaine
    | différent du SPA, ex. digicard-api.* vs digicard.*). Le localStorage n’étant
    | pas partagé entre origines, on duplique le jeton (arcc_emp_o_{orderId}) et
    | l’identité appareil en base (arcc_dev_o_{orderId}, JSON u/m) dans des cookies
    | avec un domaine parent commun (ex. .arccenciel.com), lisibles par JavaScript
    | (httpOnly=false), même modèle de risque que le localStorage.
    |
    | Exemple production : EMP_AUTH_COOKIE_DOMAIN=.arccenciel.com
    | En local (même host) : laisser null pour ne pas poser de cookie cross-domain.
    |
    */
    'emp_auth_cookie_domain' => env('EMP_AUTH_COOKIE_DOMAIN', env('SESSION_DOMAIN')),

    /*
    | null = Secure uniquement si la requête courante est en HTTPS
    */
    'emp_auth_cookie_secure' => env('EMP_AUTH_COOKIE_SECURE'),

];
