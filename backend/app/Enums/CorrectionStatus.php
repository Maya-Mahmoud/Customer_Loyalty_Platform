<?php

namespace App\Enums;

/**
 * A correction only takes effect once a branch manager or the owner approves it
 * — the separation of duties required by BRD BR-012.
 */
enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
