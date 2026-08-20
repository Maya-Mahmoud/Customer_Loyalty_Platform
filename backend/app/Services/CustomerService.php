<?php

namespace App\Services;

use App\Enums\ConsentStatus;
use App\Models\Customer;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

/**
 * Customer records, created by a sales rep at the point of sale (BRD 8.4 step 4).
 *
 * The customer is never a user of this system and never holds an account
 * (BR-001). Everything here is done on their behalf by staff, which is what makes
 * the consent handling in section 16 the rep's responsibility rather than a
 * checkbox the customer ticks.
 */
class CustomerService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Finds a customer by the number the rep typed.
     *
     * The mobile number is the identifier inside one merchant (BR-002), so the
     * global scope alone decides what is visible — the same number at another
     * store is a separate record with its own balance.
     */
    public function findByPhone(string $phone): ?Customer
    {
        $normalized = PhoneNumber::normalize($phone);

        if ($normalized === null) {
            return null;
        }

        return Customer::where('phone', $normalized)->first();
    }

    /**
     * Registers a customer on the spot. Nothing is asked of them beyond a name and
     * a number, spoken aloud (BR-011).
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, User $registeredBy): Customer
    {
        $phone = PhoneNumber::normalize($data['phone']);

        $this->guardNotStaffNumber($phone);
        $this->guardNotAlreadyRegistered($phone);

        $customer = Customer::create([
            'phone' => $phone,
            'name' => $data['name'],
            // Who captured the record and where — what makes the collusion
            // controls of AF-03 and AF-11 possible at all.
            'registered_by_user_id' => $registeredBy->getKey(),
            'registered_at_branch_id' => $registeredBy->branch_id ?? ($data['branch_id'] ?? null),

            /*
             * BRD FR-CUS-07: the rep records the customer's spoken agreement to
             * receive messages. It is stored as the customer's consent because
             * nobody else can give it, and section 16 makes withdrawing it a right.
             */
            'consent_status' => ($data['consent_given'] ?? false)
                ? ConsentStatus::Granted
                : ConsentStatus::NotCollected,
            'consent_recorded_at' => ($data['consent_given'] ?? false) ? now() : null,
        ]);

        $this->audit->record(
            action: 'customer.registered',
            entity: $customer,
            after: $customer->only(['phone', 'name', 'consent_status']),
            actor: $registeredBy,
        );

        return $customer;
    }

    /**
     * Records or withdraws the customer's agreement to be contacted.
     *
     * Section 16 requires consent to be withdrawable at any time, and the customer
     * cannot do it themselves — they have no account — so staff must be able to on
     * their behalf.
     */
    public function setConsent(Customer $customer, bool $granted, User $actor): Customer
    {
        $original = $customer->getOriginal();

        $customer->update([
            'consent_status' => $granted ? ConsentStatus::Granted : ConsentStatus::Withdrawn,
            'consent_recorded_at' => now(),
        ]);

        $this->audit->recordChange(
            $granted ? 'customer.consent_granted' : 'customer.consent_withdrawn',
            $customer,
            $original,
        );

        return $customer;
    }

    /**
     * BRD AF-04. Section 12.2 calls this the cheapest and most effective of the
     * anti-fraud controls, and it is the one that closes the most obvious hole:
     * a rep accumulating walk-in customers' purchases onto a number they control,
     * then collecting the rewards themselves.
     */
    private function guardNotStaffNumber(?string $phone): void
    {
        if ($phone === null) {
            return;
        }

        $belongsToStaff = User::where('phone', $phone)->exists();

        if ($belongsToStaff) {
            throw ValidationException::withMessages([
                'phone' => __('This number belongs to a staff account and cannot be registered as a customer.'),
            ]);
        }
    }

    private function guardNotAlreadyRegistered(?string $phone): void
    {
        if ($phone !== null && Customer::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => __('This number is already registered. Search for it instead.'),
            ]);
        }
    }
}
