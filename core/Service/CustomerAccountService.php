<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Database\Database;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\CustomerAccountRepository;
use Gfh\Core\Repository\CustomerAddressRepository;
use Gfh\Core\Support\Validation;

/**
 * What a signed-in customer may do with their own account: read and edit the profile, keep up to ten delivery
 * addresses, and change the password. Every method acts on the CustomerAccount it is given, which the controller takes
 * from the session; no id in a request body or URL ever chooses whose data is touched.
 *
 * Orders keep their own copy of the delivery address (orders.ship_to), so changing or deleting a saved address
 * never changes an order.
 *
 * Audit entries never hold a password, a hash or a token, and never hold address text: they name the fields that
 * changed, not their values.
 */
final class CustomerAccountService
{
    public const MAX_ADDRESSES = 10;
    public const WRONG_CURRENT_PASSWORD = 'The current password is not correct.';

    public function __construct(
        private readonly \PDO $core,
        private readonly CustomerAccountRepository $customers,
        private readonly CustomerAddressRepository $addresses,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
        private readonly PasswordPolicy $passwords,
    ) {
    }

    // ------------------------------------------------------------------ profile

    /** @return array{customer: array<string, mixed>} */
    public function profile(CustomerAccount $customer): array
    {
        return ['customer' => $this->presentProfile($this->load($customer))];
    }

    /**
     * Replaces full_name and phone, with the rules registration uses. A phone that is left out or empty is cleared.
     * Any other field in the input (email, status, password) is ignored, so this can never change them.
     *
     * @param array<string, mixed> $input
     * @return array{customer: array<string, mixed>}
     * @throws HttpException 422 with field messages
     */
    public function updateProfile(CustomerAccount $customer, array $input): array
    {
        $current = $this->load($customer);

        $errors = [];
        $name = Validation::personName($input['full_name'] ?? null);
        $phone = Validation::phone($input['phone'] ?? null);
        if ($name === null) {
            $errors['full_name'] = 'Enter your full name (2 to 120 characters).';
        }
        if ($phone === null) {
            $errors['phone'] = 'Enter a valid phone number, or leave it empty.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        $changed = [];
        if ($name !== (string) $current['full_name']) {
            $changed[] = 'full_name';
        }
        if ((string) $phone !== (string) ($current['phone'] ?? '')) {
            $changed[] = 'phone';
        }

        if ($changed !== []) {
            Database::transaction($this->core, function () use ($customer, $name, $phone, $changed): void {
                $this->customers->updateProfile($customer->id, (string) $name, $phone);
                $this->audit->core('customer.profile_updated', null, null, 'customer', $customer->id, ['fields' => $changed]);
            });
        }

        return ['customer' => $this->presentProfile($this->load($customer))];
    }

    // ------------------------------------------------------------------ addresses

    /**
     * The default address first, then the newest. At most ten, so the list is not paginated.
     *
     * @return array{items: list<array<string, mixed>>, max_addresses: int}
     */
    public function listAddresses(CustomerAccount $customer): array
    {
        return [
            'items' => array_map($this->presentAddress(...), $this->addresses->listForCustomer($customer->id)),
            'max_addresses' => self::MAX_ADDRESSES,
        ];
    }

    /**
     * The first address a customer saves becomes their default. Whether the new address is the default is never taken
     * from the input.
     *
     * @param array<string, mixed> $input
     * @return array{address: array<string, mixed>}
     * @throws HttpException 422 with field messages, 409 when the customer already has ten addresses
     */
    public function createAddress(CustomerAccount $customer, array $input): array
    {
        $fields = $this->validateAddress($input);

        $id = Database::transaction($this->core, function () use ($customer, $fields): string {
            // The lock makes the count below and the insert one step per customer, so two requests sent together
            // cannot both slip under the limit or both believe they are the first address.
            $this->lockCustomer($customer);

            $count = $this->addresses->countForCustomer($customer->id);
            if ($count >= self::MAX_ADDRESSES) {
                throw HttpException::conflict(sprintf('You can save up to %d addresses. Delete one to add another.', self::MAX_ADDRESSES));
            }

            $id = $this->addresses->create($customer->id, $fields, $count === 0);
            $this->audit->core('customer.address_created', null, null, 'customer_address', $id, ['customer_id' => $customer->id, 'is_default' => $count === 0]);

            return $id;
        });

        return ['address' => $this->presentAddress($this->ownAddress($customer, $id))];
    }

    /**
     * Replaces the editable fields (an optional field that is left out is cleared). The default flag is not changed here.
     * Ownership is checked before the input, so someone else's address answers 404 even for a bad body.
     *
     * @param array<string, mixed> $input
     * @return array{address: array<string, mixed>}
     * @throws HttpException 404 when the address is not the customer's, 422 with field messages
     */
    public function updateAddress(CustomerAccount $customer, string $addressId, array $input): array
    {
        $this->ownAddress($customer, $addressId);
        $fields = $this->validateAddress($input);

        Database::transaction($this->core, function () use ($customer, $addressId, $fields): void {
            if (!$this->addresses->update($customer->id, $addressId, $fields)) {
                throw HttpException::notFound();
            }
            $this->audit->core('customer.address_updated', null, null, 'customer_address', $addressId, ['customer_id' => $customer->id]);
        });

        return ['address' => $this->presentAddress($this->ownAddress($customer, $addressId))];
    }

    /**
     * Makes this address the only default. The old default is cleared first, in the same transaction, because the
     * database allows one default per customer at any moment.
     *
     * @return array{address: array<string, mixed>}
     * @throws HttpException 404 when the address is not the customer's
     */
    public function setDefaultAddress(CustomerAccount $customer, string $addressId): array
    {
        Database::transaction($this->core, function () use ($customer, $addressId): void {
            $this->lockCustomer($customer);
            $address = $this->ownAddress($customer, $addressId);

            if ($this->isTrue($address['is_default'])) {
                return;
            }

            $this->addresses->clearDefault($customer->id);
            if (!$this->addresses->markDefault($customer->id, $addressId)) {
                throw HttpException::notFound();
            }
            $this->audit->core('customer.address_default_changed', null, null, 'customer_address', $addressId, ['customer_id' => $customer->id]);
        });

        return ['address' => $this->presentAddress($this->ownAddress($customer, $addressId))];
    }

    /**
     * Deletes one address. Deleting the default promotes the most recently created address that is left.
     *
     * @return array{id: string, deleted: bool}
     * @throws HttpException 404 when the address is not the customer's
     */
    public function deleteAddress(CustomerAccount $customer, string $addressId): array
    {
        Database::transaction($this->core, function () use ($customer, $addressId): void {
            $this->lockCustomer($customer);
            $address = $this->ownAddress($customer, $addressId);

            if (!$this->addresses->delete($customer->id, $addressId)) {
                throw HttpException::notFound();
            }

            $promoted = null;
            if ($this->isTrue($address['is_default'])) {
                $promoted = $this->addresses->newestId($customer->id);
                if ($promoted !== null) {
                    $this->addresses->markDefault($customer->id, $promoted);
                }
            }

            $this->audit->core('customer.address_deleted', null, null, 'customer_address', $addressId, [
                'customer_id' => $customer->id,
                'was_default' => $this->isTrue($address['is_default']),
                'promoted_address_id' => $promoted,
            ]);
        });

        return ['id' => $addressId, 'deleted' => true];
    }

    // ------------------------------------------------------------------ password

    /**
     * Changes the password after checking the current one. A wrong current password is recorded in the same sign-in
     * log, scope and lockout as a wrong password at sign-in, so this cannot be used to guess it without limit. The
     * controller regenerates the session id afterwards. Sessions are PHP sessions, so other devices stay signed in.
     *
     * @throws HttpException 429 when locked out, 422 with field messages
     */
    public function changePassword(CustomerAccount $customer, string $currentPassword, string $newPassword, string $confirmation, string $ip): void
    {
        $row = $this->load($customer);
        $email = (string) $row['email'];

        // Refused before anything else, as at sign-in: a locked-out customer cannot test a guess, right or wrong.
        $this->auth->assertCustomerNotLocked($email, $ip);

        $errors = [];

        if ($currentPassword === '') {
            // Nothing was guessed, so a blank field is a form mistake and does not count towards the lockout.
            $errors['current_password'] = 'Enter your current password.';
        } elseif (!$this->passwords->verify($currentPassword, (string) $row['password_hash'])) {
            $this->auth->recordCustomerPasswordCheck($email, $ip, false);
            $this->audit->core('customer.password_change_failed', null, null, 'customer', $customer->id, ['ip' => $ip]);
            $errors['current_password'] = self::WRONG_CURRENT_PASSWORD;
        }

        try {
            $this->passwords->assertValid($newPassword, $email, 'new_password');
        } catch (HttpException $exception) {
            $errors += $exception->fields();
        }
        if ($newPassword !== $confirmation) {
            $errors['new_password_confirmation'] = 'The confirmation does not match the new password.';
        }

        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        Database::transaction($this->core, function () use ($customer, $newPassword, $ip): void {
            $this->customers->setPassword($customer->id, $this->passwords->hash($newPassword));
            // The detail holds the address of the request only: never the password, the hash or any token.
            $this->audit->core('customer.password_changed', null, null, 'customer', $customer->id, ['ip' => $ip]);
        });

        // As at sign-in, a correct password clears the earlier failures.
        $this->auth->recordCustomerPasswordCheck($email, $ip, true);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     * @throws HttpException 401 when the account no longer exists
     */
    private function load(CustomerAccount $customer): array
    {
        return $this->customers->findById($customer->id) ?? throw HttpException::unauthorized();
    }

    private function lockCustomer(CustomerAccount $customer): void
    {
        if (!$this->customers->lock($customer->id)) {
            throw HttpException::unauthorized();
        }
    }

    /**
     * The customer's own address. An unknown id, a malformed id and another customer's id all give the same plain
     * 404 (the API's default not-found message), so the answer never shows that an address exists.
     *
     * @return array<string, mixed>
     */
    private function ownAddress(CustomerAccount $customer, string $addressId): array
    {
        return $this->addresses->findForCustomer($customer->id, $addressId) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{label: ?string, line1: string, barangay: ?string, city: string, province: string, postal_code: ?string}
     */
    private function validateAddress(array $input): array
    {
        $errors = [];

        $label = $this->text($input['label'] ?? null, 40, false);
        $line1 = $this->text($input['line1'] ?? null, 200, true);
        $barangay = $this->text($input['barangay'] ?? null, 100, false);
        $city = $this->text($input['city'] ?? null, 100, true);
        $province = $this->text($input['province'] ?? null, 100, true);
        $postal = $this->postalCode($input['postal_code'] ?? null);

        if ($label === false) {
            $errors['label'] = 'Use at most 40 characters for the label.';
        }
        if ($line1 === false || $line1 === null) {
            $errors['line1'] = 'Enter the street address (up to 200 characters).';
        }
        if ($barangay === false) {
            $errors['barangay'] = 'Use at most 100 characters for the barangay.';
        }
        if ($city === false || $city === null) {
            $errors['city'] = 'Enter the city or municipality (up to 100 characters).';
        }
        if ($province === false || $province === null) {
            $errors['province'] = 'Enter the province (up to 100 characters).';
        }
        if ($postal === false) {
            $errors['postal_code'] = 'Enter a 4-digit postal code, or leave it empty.';
        }
        if ($errors !== []) {
            throw HttpException::unprocessable($errors);
        }

        return [
            'label' => $label,
            'line1' => (string) $line1,
            'barangay' => $barangay,
            'city' => (string) $city,
            'province' => (string) $province,
            'postal_code' => $postal,
        ];
    }

    /**
     * Trimmed text with runs of whitespace collapsed to one space.
     *
     * @return string|false|null the text; null when empty and allowed to be; false when it is not acceptable
     *                           (not a string, too long, a control character, or empty when required)
     */
    private function text(mixed $value, int $maxLength, bool $required): string|false|null
    {
        if ($value === null) {
            return $required ? false : null;
        }
        if (!is_string($value)) {
            return false;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value));
        if ($clean === null || preg_match('/[\x00-\x1F\x7F]/', $clean) === 1) {
            return false;
        }
        $clean = trim($clean);
        if ($clean === '') {
            return $required ? false : null;
        }

        return mb_strlen($clean) > $maxLength ? false : $clean;
    }

    /** @return string|false|null exactly four digits; null when left empty; false for anything else (send it as a string) */
    private function postalCode(mixed $value): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return (is_string($value) && preg_match('/^[0-9]{4}$/', trim($value)) === 1) ? trim($value) : false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentProfile(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'full_name' => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'phone' => isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentAddress(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'label' => $row['label'] === null ? null : (string) $row['label'],
            'line1' => (string) $row['line1'],
            'barangay' => $row['barangay'] === null ? null : (string) $row['barangay'],
            'city' => (string) $row['city'],
            'province' => (string) $row['province'],
            'postal_code' => $row['postal_code'] === null ? null : (string) $row['postal_code'],
            'is_default' => $this->isTrue($row['is_default']),
            'created_at' => $this->iso($row['created_at']),
            'updated_at' => $this->iso($row['updated_at']),
        ];
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    private function iso(mixed $value): string
    {
        return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }
}
