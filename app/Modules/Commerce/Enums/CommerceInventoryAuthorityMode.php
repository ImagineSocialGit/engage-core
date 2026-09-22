<?php

namespace App\Modules\Commerce\Enums;

enum CommerceInventoryAuthorityMode: string
{
    case AdjustmentRequired = 'adjustment_required';
    case AuthorityAlreadyApplied = 'authority_already_applied';
    case AuthorityOperationPending = 'authority_operation_pending';
}