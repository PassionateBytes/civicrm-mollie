<?php

namespace Civi\Api4\Action\MollieSync;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\MollieCustomer;
use Civi\Payment\System;

/**
 * Run the Mollie subscription synchronization.
 *
 * Performs bidirectional sync between Mollie subscription statuses
 * and CiviCRM ContributionRecur records:
 * - Mollie -> CiviCRM: detects completed, cancelled, and suspended subscriptions.
 * - CiviCRM -> Mollie: retries cancellations that failed to reach Mollie.
 */
class Run extends AbstractAction {

  /**
   * @param Result $result
   */
  public function _run(Result $result): void {
    $stats = [
      'checked' => 0,
      'completed' => 0,
      'cancelled' => 0,
      'failed' => 0,
      'cancellations_retried' => 0,
      'cancellations_failed' => 0,
      'errors' => 0,
    ];

    $this->syncMollieStatusesToCiviCrm($stats);
    $this->retryCancellations($stats);

    \Civi::log('mollie')->info('MollieSync completed', $stats);

    $result[] = $stats;
  }

  /**
   * Sync Mollie subscription statuses into CiviCRM.
   *
   * @param array $stats
   */
  protected function syncMollieStatusesToCiviCrm(array &$stats): void {
    $activeRecurs = ContributionRecur::get(FALSE)
      ->addSelect('id', 'processor_id', 'contact_id', 'payment_processor_id', 'contribution_status_id:name')
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
          \Civi::log('mollie')->warning('Sync: no Mollie customer for ContributionRecur', [
            'contribution_recur_id' => $recur['id'],
          ]);
          continue;
        }

        $client = self::getClientForProcessor($recur['payment_processor_id']);
        $subscription = $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id']);
        $mollieStatus = $subscription->status;

        $newStatus = self::mapMollieStatusToCiviCrm($mollieStatus);
        if ($newStatus === NULL || $newStatus === $recur['contribution_status_id:name']) {
          continue;
        }

        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recur['id'])
          ->addValue('contribution_status_id:name', $newStatus)
          ->execute();

        match ($newStatus) {
          'Completed' => $stats['completed']++,
          'Cancelled' => $stats['cancelled']++,
          'Failed' => $stats['failed']++,
          default => NULL,
        };

        \Civi::log('mollie')->info('Sync: updated ContributionRecur status', [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
          'mollie_status' => $mollieStatus,
          'new_civicrm_status' => $newStatus,
        ]);
      }
      catch (\Exception $e) {
        $stats['errors']++;
        \Civi::log('mollie')->error('Sync: failed to check subscription', [
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
        $subscription = $client->subscriptions->getForId($mollieCustomerId, $recur['processor_id']);

        if (!in_array($subscription->status, ['active', 'pending'], TRUE)) {
          continue;
        }

        $client->subscriptions->cancelForId($mollieCustomerId, $recur['processor_id']);
        $stats['cancellations_retried']++;

        \Civi::log('mollie')->info('Sync: retried cancellation on Mollie', [
          'contribution_recur_id' => $recur['id'],
          'subscription_id' => $recur['processor_id'],
        ]);
      }
      catch (\Exception $e) {
        $stats['cancellations_failed']++;
        \Civi::log('mollie')->error('Sync: failed to retry cancellation', [
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

}
