<?php

namespace Civi\Mollie\Api4;

use Civi\Api4\ContributionRecur;
use Civi\Api4\MollieCustomer;
use Civi\Payment\System;
use CRM_Mollie_ExtensionUtil as E;

/**
 * MollieSync API action.
 *
 * Scheduled job that synchronizes Mollie subscription statuses with
 * CiviCRM ContributionRecur records. Detects completed, cancelled,
 * and suspended subscriptions that may not have triggered webhooks.
 */
class MollieSync {

  /**
   * Run the sync job.
   *
   * @return array
   *   Summary of actions taken.
   */
  public static function run(): array {
    $stats = ['checked' => 0, 'completed' => 0, 'cancelled' => 0, 'failed' => 0, 'errors' => 0];

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

        $processor = System::singleton()->getById($recur['payment_processor_id']);
        $client = self::getClientForProcessor($processor);

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

    \Civi::log('mollie')->info('MollieSync completed', $stats);

    return $stats;
  }

  /**
   * Map Mollie subscription status to CiviCRM ContributionRecur status.
   *
   * @param string $mollieStatus
   *
   * @return string|null
   *   CiviCRM status name, or null if no change needed.
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
   * Get an authenticated Mollie API client for a payment processor.
   *
   * @param \CRM_Core_Payment_Mollie $processor
   *
   * @return \Mollie\Api\MollieApiClient
   */
  protected static function getClientForProcessor($processor): \Mollie\Api\MollieApiClient {
    // Use reflection to access the protected getMollieApiClient method,
    // or call the public-facing method if available.
    $method = new \ReflectionMethod($processor, 'getMollieApiClient');
    $method->setAccessible(TRUE);
    return $method->invoke($processor);
  }

}
