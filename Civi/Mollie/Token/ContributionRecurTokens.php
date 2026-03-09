<?php

namespace Civi\Mollie\Token;

use Civi\Core\Service\AutoService;
use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;
use CRM_Mollie_ExtensionUtil as E;

/**
 * Token subscriber for ContributionRecur fields.
 *
 * Provides {contribution_recur.*} tokens when contributionRecurId is in
 * the token processor schema (set by the RecurringReminder workflow message).
 *
 * @service civi.mollie.token.contribution_recur
 * @internal
 */
class ContributionRecurTokens extends AutoService implements \Symfony\Component\EventDispatcher\EventSubscriberInterface {

  /**
   * Per-request cache for loaded ContributionRecur records.
   *
   * @var array<int, array|null>
   */
  private array $cache = [];

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'civi.token.list' => 'registerTokens',
      'civi.token.eval' => 'evaluateTokens',
    ];
  }

  /**
   * Register contribution_recur tokens.
   *
   * @param TokenRegisterEvent $e
   *   The token registration event.
   */
  public function registerTokens(TokenRegisterEvent $e): void {
    if (!$this->isActive($e->getTokenProcessor())) {
      return;
    }

    $tokens = [
      'amount' => E::ts('Amount'),
      'currency' => E::ts('Currency'),
      'frequency_interval' => E::ts('Frequency Interval'),
      'frequency_unit' => E::ts('Frequency Unit'),
      'next_sched_contribution_date' => E::ts('Next Scheduled Contribution Date'),
    ];

    foreach ($tokens as $name => $label) {
      $e->register([
        'entity' => 'contribution_recur',
        'field' => $name,
        'label' => $label,
      ]);
    }
  }

  /**
   * Evaluate contribution_recur token values for each row.
   *
   * @param TokenValueEvent $e
   *   The token evaluation event.
   */
  public function evaluateTokens(TokenValueEvent $e): void {
    if (!$this->isActive($e->getTokenProcessor())) {
      return;
    }

    foreach ($e->getRows() as $row) {
      $recurId = $row->context['contributionRecurId'] ?? NULL;
      if (!$recurId) {
        continue;
      }

      $recur = $this->loadRecur((int) $recurId);
      if (!$recur) {
        continue;
      }

      $currency = $recur['currency'] ?? 'EUR';
      $amount = $recur['amount'] ?? 0;

      $row->format('text/html')->tokens('contribution_recur', 'amount',
        \CRM_Utils_Money::format($amount, $currency));
      $row->format('text/plain')->tokens('contribution_recur', 'amount',
        \CRM_Utils_Money::format($amount, $currency));

      $row->format('text/html')->tokens('contribution_recur', 'currency', $currency);
      $row->format('text/plain')->tokens('contribution_recur', 'currency', $currency);

      $row->format('text/html')->tokens('contribution_recur', 'frequency_interval',
        (string) ($recur['frequency_interval'] ?? ''));
      $row->format('text/plain')->tokens('contribution_recur', 'frequency_interval',
        (string) ($recur['frequency_interval'] ?? ''));

      $row->format('text/html')->tokens('contribution_recur', 'frequency_unit',
        $recur['frequency_unit'] ?? '');
      $row->format('text/plain')->tokens('contribution_recur', 'frequency_unit',
        $recur['frequency_unit'] ?? '');

      if (!empty($recur['next_sched_contribution_date'])) {
        $formatted = \CRM_Utils_Date::customFormat($recur['next_sched_contribution_date'], '%B %E%f, %Y');
        $row->format('text/html')->tokens('contribution_recur', 'next_sched_contribution_date', $formatted);
        $row->format('text/plain')->tokens('contribution_recur', 'next_sched_contribution_date', $formatted);
      }
      else {
        $row->format('text/html')->tokens('contribution_recur', 'next_sched_contribution_date', '');
        $row->format('text/plain')->tokens('contribution_recur', 'next_sched_contribution_date', '');
      }
    }
  }

  /**
   * Load a ContributionRecur record by ID, with per-request caching.
   *
   * @param int $recurId
   *   The ContributionRecur ID.
   *
   * @return array|null
   *   The recur record or NULL if not found.
   */
  private function loadRecur(int $recurId): ?array {
    if (!array_key_exists($recurId, $this->cache)) {
      $this->cache[$recurId] = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('amount', 'currency', 'frequency_interval', 'frequency_unit', 'next_sched_contribution_date')
        ->addWhere('id', '=', $recurId)
        ->execute()
        ->first();
    }
    return $this->cache[$recurId];
  }

  /**
   * Check if this subscriber should activate for the given processor.
   *
   * @param \Civi\Token\TokenProcessor $processor
   *   The token processor instance.
   *
   * @return bool
   *   TRUE if 'contributionRecurId' is in the processor's schema.
   */
  private function isActive(\Civi\Token\TokenProcessor $processor): bool {
    return in_array('contributionRecurId', $processor->context['schema'] ?? [], TRUE);
  }

}
