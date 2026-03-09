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
      'cancellations_retried' => 0,
      'cancellations_failed' => 0,
      'errors' => 0,
    ];

    $this->syncFromMollie($stats);
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
    $activeRecurs = ContributionRecur::get(FALSE)
      ->addSelect('id', 'processor_id', 'contact_id', 'payment_processor_id',
        'contribution_status_id:name', 'next_sched_contribution_date', 'amount',
        'currency', 'end_date', 'cancel_date')
      ->addWhere('processor_id', 'IS NOT NULL')
      ->addWhere('processor_id', '!=', '')
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Pending'])
      ->addJoin('PaymentProcessorType AS ppt', 'INNER',
        ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"']
      )
      ->execute();

    foreach ($activeRecurs as $recur) {
      $stats['checked']++;

      try {
        $mollieCustomerId = self::getMollieCustomerId($recur['contact_id'], $recur['payment_processor_id']);
        if ($mollieCustomerId === NULL) {
          \CRM_Mollie_Log::warning("Sync: no Mollie customer for ContributionRecur #{$recur['id']}", [
            'contribution_recur_id' => $recur['id'],
          ]);
          continue;
        }

        $client = self::getClientForProcessor($recur['payment_processor_id']);
        $subscription = self::throttledApiCall(
          fn() => $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id'])
        );

        $updates = $this->buildUpdatesFromSubscription($subscription, $recur);
        if (empty($updates)) {
          continue;
        }

        $update = ContributionRecur::update(FALSE)
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
            default => NULL,
          };
        }

        \CRM_Mollie_Log::info("Sync: updated recur #{$recur['id']} from subscription {$recur['processor_id']}", [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
          'updates' => $updates,
        ]);
      }
      catch (\Exception $e) {
        $stats['errors']++;
        \CRM_Mollie_Log::error("Sync: failed to check subscription for recur #{$recur['id']}: {$e->getMessage()}", [
          'contribution_recur_id' => $recur['id'],
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Compare Mollie subscription state with CiviCRM and return needed updates.
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
    if ($newStatus !== NULL && $newStatus !== $recur['contribution_status_id:name']) {
      $updates['contribution_status_id:name'] = $newStatus;

      if ($newStatus === 'Completed') {
        $updates['end_date'] = date('Y-m-d H:i:s');
        $updates['next_sched_contribution_date'] = NULL;
      }
      if ($newStatus === 'Cancelled') {
        $updates['cancel_date'] = $subscription->canceledAt !== NULL
          ? date('Y-m-d H:i:s', strtotime($subscription->canceledAt))
          : date('Y-m-d H:i:s');
        $updates['end_date'] = $updates['cancel_date'];
        $updates['next_sched_contribution_date'] = NULL;
      }
      if ($newStatus === 'Failed') {
        $updates['next_sched_contribution_date'] = NULL;
      }
    }

    // Next payment date — only for active subscriptions.
    if ($subscription->nextPaymentDate !== NULL && in_array($subscription->status, ['active', 'pending'], TRUE)) {
      $mollieNextDate = $subscription->nextPaymentDate . ' 00:00:00';
      $civiNextDate = $recur['next_sched_contribution_date'] !== NULL
        ? date('Y-m-d', strtotime($recur['next_sched_contribution_date'])) . ' 00:00:00'
        : NULL;

      if ($civiNextDate !== $mollieNextDate) {
        $updates['next_sched_contribution_date'] = $mollieNextDate;
      }
    }

    // Amount — Mollie is authoritative if it was changed on their side.
    if ($subscription->amount !== NULL && isset($subscription->amount->value)) {
      $mollieAmount = number_format((float) $subscription->amount->value, 2, '.', '');
      $civiAmount = number_format((float) $recur['amount'], 2, '.', '');

      if ($mollieAmount !== $civiAmount) {
        $updates['amount'] = $mollieAmount;
      }
    }

    return $updates;
  }

  /**
   * Retry cancellations that failed to reach Mollie.
   *
   * @param array $stats
   */
  protected function retryCancellations(array &$stats): void {
    $cancelledRecurs = ContributionRecur::get(FALSE)
      ->addSelect('id', 'processor_id', 'contact_id', 'payment_processor_id')
      ->addWhere('processor_id', 'IS NOT NULL')
      ->addWhere('processor_id', '!=', '')
      ->addWhere('contribution_status_id:name', '=', 'Cancelled')
      ->addJoin('PaymentProcessorType AS ppt', 'INNER',
        ['payment_processor_id.payment_processor_type_id', '=', 'ppt.id'],
        ['ppt.name', '=', '"mollie"']
      )
      ->execute();

    foreach ($cancelledRecurs as $recur) {
      try {
        $mollieCustomerId = self::getMollieCustomerId($recur['contact_id'], $recur['payment_processor_id']);
        if ($mollieCustomerId === NULL) {
          continue;
        }

        $client = self::getClientForProcessor($recur['payment_processor_id']);
        $subscription = self::throttledApiCall(
          fn() => $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id'])
        );

        if (!in_array($subscription->status, ['active', 'pending'], TRUE)) {
          continue;
        }

        self::throttledApiCall(
          fn() => $client->subscriptions->cancelForId($mollieCustomerId, $recur['processor_id'])
        );
        $stats['cancellations_retried']++;

        \CRM_Mollie_Log::info("Sync: retried cancellation of subscription {$recur['processor_id']} for recur #{$recur['id']}", [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
        ]);
      }
      catch (\Exception $e) {
        $stats['cancellations_failed']++;
        \CRM_Mollie_Log::error("Sync: failed to retry cancellation for recur #{$recur['id']}: {$e->getMessage()}", [
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
      'completed' => 'Completed',
      'canceled' => 'Cancelled',
      'suspended' => 'Failed',
      'active', 'pending' => NULL,
      default => NULL,
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
    $result = MollieCustomer::get(FALSE)
      ->addSelect('mollie_customer_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', '=', $processorId)
      ->setLimit(1)
      ->execute();

    return $result->count() > 0 ? $result->first()['mollie_customer_id'] : NULL;
  }

  /**
   * Get an authenticated Mollie API client for a payment processor ID.
   *
   * @param int $processorId
   *
   * @return \Mollie\Api\MollieApiClient
   */
  protected static function getClientForProcessor(int $processorId): \Mollie\Api\MollieApiClient {
    $processor = System::singleton()->getById($processorId);
    $processorConfig = $processor->getPaymentProcessor();
    $apiKey = $processorConfig['user_name'] ?? '';

    $client = new \Mollie\Api\MollieApiClient();
    $client->setApiKey($apiKey);

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
      }
      catch (ApiException $e) {
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
    if ($response !== NULL && $response->hasHeader('Retry-After')) {
      $value = (int) $response->getHeader('Retry-After')[0];
      if ($value > 0 && $value <= 60) {
        return $value;
      }
    }

    return 5;
  }

}
