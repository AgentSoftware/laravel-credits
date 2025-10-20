<?php

namespace AgentSoftware\Credits\Traits;

use AgentSoftware\Credits\Events\CreditsAdded;
use AgentSoftware\Credits\Events\CreditsDeducted;
use AgentSoftware\Credits\Events\CreditsTransferred;
use AgentSoftware\Credits\Exceptions\InsufficientCreditsException;
use AgentSoftware\Credits\Models\Credit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

trait HasCredits
{
    /**
     * Get all credit transactions for this model.
     */
    public function credits(): MorphMany
    {
        return $this->morphMany(Credit::class, 'creditable');
    }

    /**
     * Get all credit transactions for this model.
     *
     * @deprecated Use credits() instead. Will be removed in v2.0
     */
    public function creditTransactions(): MorphMany
    {
        trigger_error('Method creditTransactions() is deprecated. Use credits() instead.', E_USER_DEPRECATED);

        return $this->credits();
    }

    /**
     * Create a credit transaction for the model, update its running balance, and dispatch a CreditsAdded event.
     *
     * @param  float  $amount  The amount to add; must be greater than 0.
     * @param  string|null  $description  Optional human-readable description for the transaction.
     * @param  string|null  $creditType  Optional credit type/category for the transaction.
     * @param  array  $metadata  Optional arbitrary metadata stored with the transaction.
     * @return \AgentSoftware\Credits\Models\Credit The created Credit record with the updated running balance.
     *
     * @throws \InvalidArgumentException If $amount is not greater than 0.
     */
    public function creditAdd(float $amount, ?string $description = null, ?string $creditType = null, array $metadata = []): Credit
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0.');
        }

        return DB::transaction(function () use ($amount, $description, $creditType, $metadata) {
            $lastBalance = (float) ($this->credits()
                ->lockForUpdate()
                ->latest('id')
                ->value('running_balance') ?? 0.0);
            $newBalance = $lastBalance + $amount;

            $credit = $this->credits()->create([
                'amount' => $amount,
                'description' => $description,
                'type' => 'credit',
                'credit_type' => $creditType,
                'metadata' => $metadata,
                'running_balance' => $newBalance,
            ]);

            event(new CreditsAdded(
                creditable: $this,
                transactionId: $credit->id,
                amount: $amount,
                newBalance: $newBalance,
                description: $description,
                metadata: $metadata,
                creditType: $creditType
            ));

            return $credit;
        }, 5); // Retry up to 5 times on deadlock/transient errors
    }

    /**
     * Add credits to the model.
     *
     * @deprecated Use creditAdd() instead. Will be removed in v2.0
     */
    public function addCredits(float $amount, ?string $description = null, ?string $creditType = null, array $metadata = []): Credit
    {
        trigger_error('Method addCredits() is deprecated. Use creditAdd() instead.', E_USER_DEPRECATED);

        return $this->creditAdd($amount, $description, $creditType, $metadata);
    }

    /**
     * Deducts credits from the model and records a debit transaction.
     *
     * @param  float  $amount  The amount to deduct; must be greater than 0.
     * @param  string|null  $description  Optional description for the transaction.
     * @param  string|null  $creditType  Optional credit type/category for the transaction.
     * @param  array  $metadata  Optional metadata to attach to the transaction.
     * @return Credit The created Credit record representing the debit and its resulting running balance.
     *
     * @throws \InvalidArgumentException If $amount is not greater than 0.
     * @throws InsufficientCreditsException If negative balances are disallowed and the model has insufficient credits.
     */
    public function creditDeduct(float $amount, ?string $description = null, ?string $creditType = null, array $metadata = []): Credit
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0.');
        }

        return DB::transaction(function () use ($amount, $description, $creditType, $metadata) {
            $lastBalance = (float) ($this->credits()
                ->lockForUpdate()
                ->latest('id')
                ->value('running_balance') ?? 0.0);
            $newBalance = $lastBalance - $amount;

            if (! config('credits.allow_negative_balance') && $newBalance < 0) {
                throw new InsufficientCreditsException($amount, $lastBalance);
            }

            $credit = $this->credits()->create([
                'amount' => $amount,
                'description' => $description,
                'type' => 'debit',
                'credit_type' => $creditType,
                'metadata' => $metadata,
                'running_balance' => $newBalance,
            ]);

            event(new CreditsDeducted(
                creditable: $this,
                transactionId: $credit->id,
                amount: $amount,
                newBalance: $newBalance,
                description: $description,
                metadata: $metadata,
                creditType: $creditType
            ));

            return $credit;
        }, 5); // Retry up to 5 times on deadlock/transient errors
    }

    /**
     * Deduct credits from the model.
     *
     * @deprecated Use creditDeduct() instead. Will be removed in v2.0
     */
    public function deductCredits(float $amount, ?string $description = null, ?string $creditType = null, array $metadata = []): Credit
    {
        trigger_error('Method deductCredits() is deprecated. Use creditDeduct() instead.', E_USER_DEPRECATED);

        return $this->creditDeduct($amount, $description, $creditType, $metadata);
    }

    /**
     * Retrieve the model's current credit balance.
     *
     * @param  string|null  $creditType  Optional credit type to filter by. Pass null explicitly to filter by transactions with no credit type.
     * @return float The most recent `running_balance` as a float, or 0.0 if no transactions exist.
     */
    public function creditBalance(?string $creditType = null): float
    {
        // Check if this is a call without any parameter (total balance) vs explicit null (filter by null credit_type)
        $args = func_get_args();
        $isFilteringByType = count($args) > 0;

        if (!$isFilteringByType) {
            // For total balance, get the latest running_balance
            return (float) ($this->credits()
                ->latest('id')
                ->value('running_balance') ?? 0.0);
        }

        // For specific credit type (including explicit null), calculate sum of amounts for that type
        $query = $this->credits();

        if ($creditType === null) {
            $query->whereNull('credit_type');
        } else {
            $query->where('credit_type', $creditType);
        }

        $credits = (clone $query)->where('type', 'credit')->sum('amount');
        $debits = (clone $query)->where('type', 'debit')->sum('amount');

        return (float) ($credits - $debits);
    }

    /**
     * Get the current balance of the model.
     *
     * @deprecated Use creditBalance() instead. Will be removed in v2.0
     */
    public function getCurrentBalance(?string $creditType = null): float
    {
        trigger_error('Method getCurrentBalance() is deprecated. Use creditBalance() instead.', E_USER_DEPRECATED);

        return $this->creditBalance($creditType);
    }

    /**
     * Transfer credits from this model to another model.
     *
     * The transfer is executed inside a database transaction with deterministic
     * row locking and retry (up to 5 attempts) to prevent deadlocks when concurrent
     * transfers occur in opposite directions.
     *
     * @param  self  $recipient  The model receiving the credits.
     * @param  float  $amount  The amount of credits to transfer (must be greater than zero).
     * @param  string|null  $description  Optional human-readable description for the transaction.
     * @param  string|null  $creditType  Optional credit type/category for the transaction.
     * @param  array  $metadata  Optional arbitrary metadata to attach to the transaction.
     * @return array{
     *     sender_balance: float,
     *     recipient_balance: float
     * } Associative array containing the sender's and recipient's balances after the transfer.
     */
    public function creditTransfer(self $recipient, float $amount, ?string $description = null, ?string $creditType = null, array $metadata = []): array
    {
        $result = [];

        $lastTransaction = DB::transaction(function () use ($recipient, $amount, $description, $creditType, $metadata, &$result) {
            // Pre-lock both models in deterministic order to prevent deadlocks
            // Sort by model type first, then by ID to ensure consistent lock acquisition order
            $models = collect([$this, $recipient])
                ->sortBy(function ($model) {
                    return [get_class($model), $model->getKey()];
                })
                ->values();

            // Acquire locks in deterministic order
            foreach ($models as $model) {
                $model->credits()->lockForUpdate()->latest('id')->value('running_balance');
            }

            // Now perform the actual transfer operations
            $this->creditDeduct($amount, $description, $creditType, $metadata);
            $transaction = $recipient->creditAdd($amount, $description, $creditType, $metadata);

            $senderBalance = $this->creditBalance();
            $recipientBalance = $recipient->creditBalance();

            $result = [
                'sender_balance' => $senderBalance,
                'recipient_balance' => $recipientBalance,
            ];

            return $transaction;
        }, 5); // Retry up to 5 times on deadlock

        event(new CreditsTransferred(
            transactionId: $lastTransaction->id,
            sender: $this,
            recipient: $recipient,
            amount: $amount,
            senderNewBalance: $result['sender_balance'],
            recipientNewBalance: $result['recipient_balance'],
            description: $description,
            metadata: $metadata,
            creditType: $creditType
        ));

        return $result;
    }

    /**
     * Transfer credits from the model to another model.
     *
     * @deprecated Use creditTransfer() instead. Will be removed in v2.0
     */
    public function transferCredits(self $recipient, float $amount, ?string $description = null, ?string $creditType = null, array $metadata = []): array
    {
        trigger_error('Method transferCredits() is deprecated. Use creditTransfer() instead.', E_USER_DEPRECATED);

        return $this->creditTransfer($recipient, $amount, $description, $creditType, $metadata);
    }

    /**
     * Retrieve credit transactions for the model.
     *
     * The results are ordered by `created_at` and then `id` using the specified direction.
     * The `$order` parameter is normalized to `'asc'` or `'desc'` (defaults to `'desc'` if invalid).
     * The `$limit` parameter is clamped to the range 1..1000.
     *
     * @param  int  $limit  Maximum number of records to return (clamped to 1..1000).
     * @param  string  $order  Sort direction, either `'asc'` or `'desc'` (invalid values default to `'desc'`).
     * @param  string|null  $creditType  Optional credit type to filter by.
     * @return \Illuminate\Database\Eloquent\Collection|EloquentCollection A collection of Credit records matching the query.
     */
    public function creditHistory(int $limit = 10, string $order = 'desc', ?string $creditType = null): EloquentCollection
    {
        // Sanitize order direction - only allow 'asc' or 'desc'
        $order = strtolower($order);
        if (! in_array($order, ['asc', 'desc'], true)) {
            $order = 'desc';
        }

        // Clamp limit to a positive integer between 1 and 1000
        $limit = min(max((int) $limit, 1), 1000);

        $query = $this->credits();

        if ($creditType !== null) {
            $query->where('credit_type', $creditType);
        }

        return $query
            ->orderBy('created_at', $order)
            ->orderBy('id', $order) // Tie-break on ID for deterministic ordering
            ->limit($limit)
            ->get();
    }

    /**
     * Retrieve the model's credit transaction history.
     *
     * @param  int  $limit  Maximum number of records to return (clamped to 1..1000).
     * @param  string  $order  Sort direction, either 'asc' or 'desc' (defaults to 'desc').
     * @param  string|null  $creditType  Optional credit type to filter by.
     * @return \Illuminate\Database\Eloquent\Collection Collection of Credit records.
     *
     * @deprecated Use creditHistory() instead. Will be removed in v2.0.
     */
    public function getTransactionHistory(int $limit = 10, string $order = 'desc', ?string $creditType = null): EloquentCollection
    {
        trigger_error('Method getTransactionHistory() is deprecated. Use creditHistory() instead.', E_USER_DEPRECATED);

        return $this->creditHistory($limit, $order, $creditType);
    }

    /**
     * Check if the model has enough credits.
     *
     * @param  float  $amount  The amount to check for.
     * @param  string|null  $creditType  Optional credit type to filter by.
     */
    public function hasCredits(float $amount, ?string $creditType = null): bool
    {
        return $this->creditBalance($creditType) >= $amount;
    }

    /**
     * Check if the model has enough credits.
     *
     * @deprecated Use hasCredits() instead. Will be removed in v2.0
     */
    public function hasEnoughCredits(float $amount, ?string $creditType = null): bool
    {
        trigger_error('Method hasEnoughCredits() is deprecated. Use hasCredits() instead.', E_USER_DEPRECATED);

        return $this->hasCredits($amount, $creditType);
    }

    /**
     * Retrieve the model's credit balance as of a given date/time or Unix timestamp.
     *
     * Accepts a DateTimeInterface or an integer Unix timestamp (seconds or milliseconds; milliseconds are auto-detected).
     *
     * @param  \DateTimeInterface|int  $dateTime  The target date/time or Unix timestamp to query the balance at.
     * @param  string|null  $creditType  Optional credit type to filter by.
     * @return float The running balance at or before the specified date/time, or 0.0 if no transactions exist.
     */
    public function creditBalanceAt($dateTime, ?string $creditType = null): float
    {
        if (is_int($dateTime)) {
            // Auto-detect millisecond timestamps (values > 9999999999 are likely milliseconds)
            if ($dateTime > 9999999999) {
                $dateTime = (int) floor($dateTime / 1000);
            }
            $dateTime = Carbon::createFromTimestamp($dateTime);
        } elseif ($dateTime instanceof \DateTimeInterface) {
            $dateTime = Carbon::instance($dateTime);
        }

        // Check if this is a call with just dateTime (total balance) vs explicit creditType parameter
        $args = func_get_args();
        $isFilteringByType = count($args) > 1;

        if (!$isFilteringByType) {
            // For total balance, get the latest running_balance at the specified date
            return (float) ($this->credits()
                ->where('created_at', '<=', $dateTime)
                ->latest('id')
                ->value('running_balance') ?? 0.0);
        }

        // For specific credit type (including explicit null), calculate sum of amounts for that type at the specified date
        $baseQuery = $this->credits()->where('created_at', '<=', $dateTime);

        if ($creditType === null) {
            $baseQuery->whereNull('credit_type');
        } else {
            $baseQuery->where('credit_type', $creditType);
        }

        $credits = (clone $baseQuery)->where('type', 'credit')->sum('amount');
        $debits = (clone $baseQuery)->where('type', 'debit')->sum('amount');

        return (float) ($credits - $debits);
    }

    /**
     * Get the balance of the model as of a specific date and time or timestamp.
     *
     * @param  \DateTimeInterface|int  $dateTime
     * @param  string|null  $creditType  Optional credit type to filter by.
     *
     * @deprecated Use creditBalanceAt() instead. Will be removed in v2.0
     */
    public function getBalanceAsOf($dateTime, ?string $creditType = null): float
    {
        trigger_error('Method getBalanceAsOf() is deprecated. Use creditBalanceAt() instead.', E_USER_DEPRECATED);

        return $this->creditBalanceAt($dateTime, $creditType);
    }
}
