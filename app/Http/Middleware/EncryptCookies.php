<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

/**
 * Les cookies arcc_emp_o_* / arcc_dev_o_* sont lus côté client (document.cookie)
 * puis renvoyés dans le JSON verify-identity. Le middleware Laravel les chiffrait
 * par défaut → chaîne ~400 car. et échec de validation max:128 sur emp_auth_token.
 */
class EncryptCookies extends Middleware
{
    /**
     * {@inheritdoc}
     */
    public function isDisabled($name)
    {
        if (is_string($name)) {
            if (str_starts_with($name, 'arcc_emp_o_') || str_starts_with($name, 'arcc_dev_o_')) {
                return true;
            }
        }

        return parent::isDisabled($name);
    }
}
