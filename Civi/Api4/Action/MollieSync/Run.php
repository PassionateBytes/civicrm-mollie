<?php

namespace Civi\Api4\Action\MollieSync;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\MollieCustomer;
use Civi\Payment\System;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Resources\Subscription;

/**
 * Run the Mollie subscription synchronization.
 *
 * Performs bidirectional sync between Mollie subscriptions and CiviCRM
 * ContributionRecur records. Mollie is the source of truth for all
 * subscription-managed fields:
 *
 * - status, next_sched_contribution_date, amount, end_date, cancel_date
 * - Mollie -> CiviCRM: syncs status and scheduling fields from Mollie
 * - CiviCRM -> Mollie: retries cancellations that failed to reach Mollie
 */
class Run extends AbstractAction {
  /**
   * @param Result $result
   */
  public function _run(Result $result): void {
    $stats = [
      'checked' => 0,
      'synced' => 0,
      'completed' => 0,
      'cancelled' => 0,
      'failed' => 0,
      'suspensions_recovered' => 0,
      'cancellations_retried' => 0,
      'cancellations_failed' => 0,
      'errors' => 0,
    ];

    $this->syncFromMollie($stats);
    $this->recoverSuspended($stats);
    $this->retryCancellations($stats);

    \CRM_Mollie_Log::info('MollieSync completed', $stats);

    $result[] = $stats;
  }

  /**
   * Sync Mollie subscription state into CiviCRM.
   *
   * For each active/pending recurring contribution with a Mollie subscription,
   * fetches the subscription from Mollie and updates CiviCRM fields to match.
   *
   * @param array $stats
   */
  protected function syncFromMollie(array &$stats): void {
    $activeRecurs = ContributionRecur::get(false)
      ->addSelect(
        'id',
        'processor_id',
        'contact_id',
        'payment_processor_id',
        'contribution_status_id:name',
        'next_sched_contribution_date',
        'amount',
        'currency',
        'end_date',
        'cancel_date',
      )
      ->addWhere('processor_id', 'LIKE', 'sub_%')
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Pending'])
      ->addWhere('is_test', 'IN', [0, 1])
      ->addJoin(
        'PaymentProcessorType AS ppt',
        'INNER',
        ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"'],
      )
      ->execute();

    foreach ($activeRecurs as $recur) {
      $stats['checked']++;

      try {
        $mollieCustomerId = static::getMollieCustomerId($recur['contact_id'], $recur['payment_processor_id']);
        if ($mollieCustomerId === null) {
          \CRM_Mollie_Log::warning("Sync: no Mollie customer for ContributionRecur #{$recur['id']}", [
            'contribution_recur_id' => $recur['id'],
          ]);
          continue;
        }

        $client = static::getClientForProcessor($recur['payment_processor_id']);
        $subscription = static::throttledApiCall(
          fn () => $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id']),
        );

        $updates = $this->buildUpdatesFromSubscription($subscription, $recur);
        if (empty($updates)) {
          continue;
        }

        $update = ContributionRecur::update(false)
          ->addWhere('id', '=', $recur['id']);
        foreach ($updates as $field => $value) {
          $update->addValue($field, $value);
        }
        $update->execute();

        $stats['synced']++;

        if (isset($updates['contribution_status_id:name'])) {
          match ($updates['contribution_status_id:name']) {
            'Completed' => $stats['completed']++,
            'Cancelled' => $stats['cancelled']++,
            'Failed' => $stats['failed']++,
            default => null,
          };
        }

        \CRM_Mollie_Log::info("Sync: updated ContributionRecur #{$recur['id']} from subscription {$recur['processor_id']}", [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
          'updates' => $updates,
        ]);
      } catch (\Exception $e) {
        $stats['errors']++;
        \CRM_Mollie_Log::error("Sync: failed to check subscription for ContributionRecur #{$recur['id']}: {$e->getMessage()}", [
          'contribution_recur_id' => $recur['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Compare Mollie subscription state with CiviCRM and return needed updates.
   *
   * When the status transitions to a terminal state (Completed, Cancelled,
   * Failed), sets the appropriate date fields. When recovering to In Progress
   * (e.g., a Failed subscription becomes active again), clears stale
   * cancel_date, cancel_reason, and end_date to avoid data inconsistency.
   *
   * @param Subscription $subscription
   *   Mollie subscription resource.
   * @param array $recur
   *   CiviCRM ContributionRecur record.
   *
   * @return array
   *   Field => value pairs to update on the ContributionRecur, empty if no changes.
   */
  protected function buildUpdatesFromSubscription(Subscription $subscription, array $recur): array {
    $updates = [];

    // Status.
    $newStatus = self::mapMollieStatusToCiviCrm($subscription->status);
    if ($newStatus !== null && $newStatus !== $recur['contribution_status_id:name']) {
      $updates['contribution_status_id:name'] = $newStatus;

      if ($newStatus === 'Completed') {
        $updates['end_date'] = date('Y-m-d H:i:s');
        $updates['next_sched_contribution_date'] = null;
      }
      if ($newStatus === 'Cancelled') {
        $updates['cancel_date'] = $subscription->canceledAt !== null
          ? date('Y-m-d H:i:s', strtotime($subscription->canceledAt))
          : date('Y-m-d H:i:s');
        $updates['end_date'] = $updates['cancel_date'];
        $updates['next_sched_contribution_date'] = null;
      }
      if ($newStatus === 'Failed') {
        $updates['next_sched_contribution_date'] = null;
      }
      // Clear stale failure/cancellation fields when recovering to an active
      // state. These may have been set by a prior Cancelled or Failed
      // transition (either from this sync job or from the webhook handler's
      // failContributionRecur). Leaving them would create an inconsistency
      // where the record is In Progress but still shows a cancel/end date.
      if ($newStatus === 'In Progress') {
        $updates['cancel_date'] = null;
        $updates['cancel_reason'] = null;
        $updates['end_date'] = null;
      }
    }

    // Next payment date — only for active subscriptions.
    if ($subscription->nextPaymentDate !== null && in_array($subscription->status, ['active', 'pending'], true)) {
      $mollieNextDate = $subscription->nextPaymentDate . ' 00:00:00';
      $civiNextDate = $recur['next_sched_contribution_date'] !== null
        ? date('Y-m-d', strtotime($recur['next_sched_contribution_date'])) . ' 00:00:00'
        : null;

      if ($civiNextDate !== $mollieNextDate) {
        $updates['next_sched_contribution_date'] = $mollieNextDate;
      }
    }

    // Amount — Mollie is authoritative if it was changed on their side.
    if ($subscription->amount !== null && isset($subscription->amount->value)) {
      $mollieAmount = number_format((float) $subscription->amount->value, 2, '.', '');
      $civiAmount = number_format((float) $recur['amount'], 2, '.', '');

      if ($mollieAmount !== $civiAmount) {
        $updates['amount'] = $mollieAmount;
      }
    }

    return $updates;
  }

  /**
   * Check recently failed subscriptions for reactivation on Mollie.
   *
   * When Mollie suspends a subscription (e.g., failed mandate), it maps to
   * CiviCRM Failed status. If the mandate is later fixed, Mollie may
   * reactivate the subscription. This method detects that and recovers
   * the CiviCRM record. Limited to 30 days to avoid unbounded growth.
   *
   * @param array $stats
   */
  protected function recoverSuspended(array &$stats): void {
    $failedRecurs = ContributionRecur::get(false)
      ->addSelect(
        'id',
        'processor_id',
        'contact_id',
        'payment_processor_id',
        'contribution_status_id:name',
        'next_sched_contribution_date',
        'amount',
        'currency',
        'end_date',
        'cancel_date',
      )
      ->addWhere('processor_id', 'LIKE', 'sub_%')
      ->addWhere('contribution_status_id:name', '=', 'Failed')
      ->addWhere('modified_date', '>=', date('Y-m-d', strtotime('-30 days')))
      ->addWhere('is_test', 'IN', [0, 1])
      ->addJoin(
        'PaymentProcessorType AS ppt',
        'INNER',
        ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"'],
      )
      ->execute();

    foreach ($failedRecurs as $recur) {
      try {
        $mollieCustomerId = static::getMollieCustomerId($recur['contact_id'], $recur['payment_processor_id']);
        if ($mollieCustomerId === null) {
          continue;
        }

        $client = static::getClientForProcessor($recur['payment_processor_id']);
        $subscription = static::throttledApiCall(
          fn () => $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id']),
        );

        if ($subscription->status !== 'active') {
          continue;
        }

        $updates = $this->buildUpdatesFromSubscription($subscription, $recur);
        if (empty($updates)) {
          continue;
        }

        $update = ContributionRecur::update(false)
          ->addWhere('id', '=', $recur['id']);
        foreach ($updates as $field => $value) {
          $update->addValue($field, $value);
        }
        $update->execute();

        $stats['suspensions_recovered']++;

        \CRM_Mollie_Log::info("Sync: recovered suspended subscription {$recur['processor_id']} for ContributionRecur #{$recur['id']}", [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
        ]);
      } catch (\Exception $e) {
        $stats['errors']++;
        \CRM_Mollie_Log::error("Sync: failed to check suspended subscription for ContributionRecur #{$recur['id']}: {$e->getMessage()}", [
          'contribution_recur_id' => $recur['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Retry cancellations that failed to reach Mollie.
   *
   * @param array $stats
   */
  protected function retryCancellations(array &$stats): void {
    // Only check recently cancelled subscriptions to avoid scanning the full
    // history on every run. 7 days is generous enough to survive a few days of
    // cron downtime while keeping the query bounded as cancellations accumulate.
    $cancelledRecurs = ContributionRecur::get(false)
      ->addSelect('id', 'processor_id', 'contact_id', 'payment_processor_id')
      ->addWhere('processor_id', 'LIKE', 'sub_%')
      ->addWhere('contribution_status_id:name', '=', 'Cancelled')
      ->addWhere('cancel_date', '>=', date('Y-m-d', strtotime('-7 days')))
      ->addWhere('is_test', 'IN', [0, 1])
      ->addJoin(
        'PaymentProcessorType AS ppt',
        'INNER',
        ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"'],
      )
      ->execute();

    foreach ($cancelledRecurs as $recur) {
      try {
        $mollieCustomerId = static::getMollieCustomerId($recur['contact_id'], $recur['payment_processor_id']);
        if ($mollieCustomerId === null) {
          continue;
        }

        $client = static::getClientForProcessor($recur['payment_processor_id']);
        $subscription = static::throttledApiCall(
          fn () => $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id']),
        );

        if (!in_array($subscription->status, ['active', 'pending', 'suspended'], true)) {
          continue;
        }

        static::throttledApiCall(
          fn () => $client->subscriptions->cancelForId($mollieCustomerId, $recur['processor_id']),
        );
        $stats['cancellations_retried']++;

        \CRM_Mollie_Log::info("Sync: retried cancellation of subscription {$recur['processor_id']} for ContributionRecur #{$recur['id']}", [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
        ]);
      } catch (\Exception $e) {
        $stats['cancellations_failed']++;
        \CRM_Mollie_Log::error("Sync: failed to retry cancellation for ContributionRecur #{$recur['id']}: {$e->getMessage()}", [
          'contribution_recur_id' => $recur['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Map Mollie subscription status to CiviCRM ContributionRecur status.
   *
   * @param string $mollieStatus
   *
   * @return string|null
   */
  protected static function mapMollieStatusToCiviCrm(string $mollieStatus): ?string {
    return match ($mollieStatus) {
      'active' => 'In Progress',
      'completed' => 'Completed',
      'canceled' => 'Cancelled',
      'suspended' => 'Failed',
      // 'pending' means Mollie hasn't attempted the first subscription charge yet.
      // By the time we create the subscription, the initial payment already succeeded
      // and CiviCRM recur is already 'In Progress' — no status change needed.
      'pending' => null,
      default => null,
    };
  }

  /**
   * Get the Mollie customer ID for a contact and processor.
   *
   * @param int $contactId
   * @param int $processorId
   *
   * @return string|null
   */
  protected static function getMollieCustomerId(int $contactId, int $processorId): ?string {
    $result = MollieCustomer::get(false)
      ->addSelect('mollie_customer_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', '=', $processorId)
      ->setLimit(1)
      ->execute();

    return $result->count() > 0 ? $result->first()['mollie_customer_id'] : null;
  }

  /**
   * Get an authenticated Mollie API client for a payment processor ID.
   *
   * @param int $processorId
   *
   * @return \Mollie\Api\MollieApiClient
   */
  private const CLIENT_CACHE_TTL = 300;

  /** @var array<int, array{client: \Mollie\Api\MollieApiClient, expires: int}> */
  protected static array $clientCache = [];

  protected static function getClientForProcessor(int $processorId): \Mollie\Api\MollieApiClient {
    if (isset(self::$clientCache[$processorId])
        && self::$clientCache[$processorId]['expires'] > time()) {
      return self::$clientCache[$processorId]['client'];
    }

    $processor = System::singleton()->getById($processorId);
    $processorConfig = $processor->getPaymentProcessor();
    $apiKey = $processorConfig['user_name'] ?? '';

    $client = new \Mollie\Api\MollieApiClient();
    $client->setApiKey($apiKey);
    self::$clientCache[$processorId] = [
      'client' => $client,
      'expires' => time() + self::CLIENT_CACHE_TTL,
    ];

    return $client;
  }

  /**
   * Execute a Mollie API call with 429 rate limit handling.
   *
   * Retries with backoff if Mollie returns HTTP 429, using the Retry-After
   * header when available.
   *
   * @param callable $apiCall
   *
   * @return mixed
   *   The return value of the API call.
   *
   * @throws ApiException
   *   Re-thrown after max retries exhausted.
   */
  protected static function throttledApiCall(callable $apiCall): mixed {
    $maxRetries = 3;
    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
      try {
        return $apiCall();
      } catch (ApiException $e) {
        if ($e->getCode() !== 429 || $attempt >= $maxRetries) {
          throw $e;
        }

        $retryAfter = self::getRetryAfterSeconds($e);
        \CRM_Mollie_Log::warning('Sync: rate limited by Mollie, retrying', [
          'attempt' => $attempt + 1,
          'retry_after_seconds' => $retryAfter,
        ]);
        sleep($retryAfter);
      }
    }

    // Unreachable, but satisfies static analysis.
    throw new \RuntimeException('Exhausted retries');
  }

  /**
   * Extract Retry-After seconds from a 429 response, with fallback.
   *
   * @param ApiException $e
   *
   * @return int
   */
  protected static function getRetryAfterSeconds(ApiException $e): int {
    $response = $e->getResponse();
    if ($response !== null && $response->hasHeader('Retry-After')) {
      $value = (int) $response->getHeader('Retry-After')[0];
      if ($value > 0 && $value <= 60) {
        return $value;
      }
    }

    return 5;
  }

}
