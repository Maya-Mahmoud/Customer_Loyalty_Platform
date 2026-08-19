<?php

namespace App\Enums;

enum VerificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
