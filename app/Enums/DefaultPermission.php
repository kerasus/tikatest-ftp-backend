<?php

namespace App\Enums;

enum DefaultPermission: string
{
    case MANAGE_USERS = 'manage_users';
    case MANAGE_PERMISSIONS = 'manage_permissions';
    case FULL_ACCESS = 'full_access';
}
