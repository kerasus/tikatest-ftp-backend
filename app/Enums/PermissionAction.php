<?php

namespace App\Enums;

enum PermissionAction: string
{
    case READ = 'read';
    case WRITE = 'write';
    case RENAME = 'rename';
    case DELETE = 'delete'; // برای آینده
}
