<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Services\CustomerExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporting the customer base (BRD 7.2, customers.export).
 *
 * Behind a gate the owner alone holds. BRD BR-019 forbids a sales rep taking the
 * list away, and this is the one route in the system that hands over more than one
 * customer at a time — so it is also the one route whose use is worth recording.
 */
class CustomerExportController extends Controller
{
    public function __construct(private readonly CustomerExportService $export)
    {
    }

    public function __invoke(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            /*
             * Section 16: a merchant sending a campaign needs the customers who
             * agreed to be contacted, not everyone they ever served. The flag is
             * theirs to set, because the same file also serves purposes consent has
             * no bearing on — reconciling balances, or moving to another system.
             */
            'only_consented' => ['nullable', 'boolean'],
        ]);

        return $this->export->stream([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'only_consented' => $request->boolean('only_consented'),
        ], $request->user());
    }
}
