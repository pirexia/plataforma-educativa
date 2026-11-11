<?php

namespace App\Modules\Alum\Http\Requests;

/** REQ-ALUM/api.md §3, `POST /group-change-requests`: `enrollment`, `to_group` (`public_id`) y `reason`. */
class StoreGroupChangeRequest extends GroupChangeBodyRequest
{
    protected function allowedKeys(): array
    {
        return ['enrollment', 'to_group', 'reason'];
    }
}
