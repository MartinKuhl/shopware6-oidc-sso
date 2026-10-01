<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

/**
 * Which side a login, provider scope or account belongs to (L11). Values are
 * the strings stored in `login_type`/`user_type` columns and flow contexts.
 */
enum LoginType: string
{
    case Admin = 'admin';
    case Customer = 'customer';
}
