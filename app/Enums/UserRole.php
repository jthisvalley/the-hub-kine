<?php

namespace App\Enums;

enum UserRole: string
{
    case KINE = 'kine';
    case PATIENT = 'patient';
    case ADMIN = 'admin';
}
