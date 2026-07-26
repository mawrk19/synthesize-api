<?php

namespace App\Modules\Orchestration\Enums;

enum ReviewApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
