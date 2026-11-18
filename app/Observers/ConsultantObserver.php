<?php

namespace App\Observers;

use App\Models\Consultant;
use App\Services\HiddenAccountService;

class ConsultantObserver
{
    public function __construct(private HiddenAccountService $hiddenAccounts)
    {
    }

    /**
     * When a real consultant is created, spin up their two hidden service-group
     * accounts. Hidden accounts are skipped so this never recurses.
     */
    public function created(Consultant $consultant): void
    {
        if (! $consultant->is_hidden) {
            $this->hiddenAccounts->ensureFor($consultant);
        }
    }
}
