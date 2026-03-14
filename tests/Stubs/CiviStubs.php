<?php

/**
 * Minimal CiviCRM class stubs for standalone unit testing.
 *
 * These stubs allow extension classes to be loaded by PHP without
 * requiring the full CiviCRM framework.
 */

namespace Civi\Api4\Generic {
  abstract class AbstractAction {
    public function __construct(string $entityName = '', string $actionName = '') {}
    public function setCheckPermissions(bool $check): static { return $this; }
  }

  class Result implements \ArrayAccess, \Iterator, \Countable {
    private array $data = [];
    private int $pos = 0;

    public function offsetExists(mixed $offset): bool { return isset($this->data[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->data[$offset] ?? NULL; }
    public function offsetSet(mixed $offset, mixed $value): void {
      if ($offset === NULL) { $this->data[] = $value; } else { $this->data[$offset] = $value; }
    }
    public function offsetUnset(mixed $offset): void { unset($this->data[$offset]); }
    public function current(): mixed { return $this->data[$this->pos] ?? NULL; }
    public function key(): int { return $this->pos; }
    public function next(): void { $this->pos++; }
    public function rewind(): void { $this->pos = 0; }
    public function valid(): bool { return isset($this->data[$this->pos]); }
    public function count(): int { return count($this->data); }
  }
}

namespace Civi\Payment\Exception {
  class PaymentProcessorException extends \RuntimeException {}
}

namespace Civi\Payment {
  class PropertyBag {
    private array $data;

    public function __construct(array $data = []) {
      $this->data = $data;
    }

    public static function cast($params): self {
      if ($params instanceof self) {
        return $params;
      }
      return new self(is_array($params) ? $params : []);
    }

    public function getContributionRecurID(): int {
      return (int) ($this->data['contributionRecurID'] ?? $this->data['id'] ?? 0);
    }

    public function getAmount(): float {
      return (float) ($this->data['amount'] ?? 0);
    }

    public function getContributionID(): int {
      return (int) ($this->data['contributionID'] ?? 0);
    }

    public function getContactID(): int {
      return (int) ($this->data['contactID'] ?? 0);
    }

    public function getCurrency(): string {
      return $this->data['currency'] ?? $this->data['currencyID'] ?? 'EUR';
    }
  }
}

// ---------------------------------------------------------------------------
// Api4 mock infrastructure
// ---------------------------------------------------------------------------

namespace Tests\Stubs {

  /**
   * Global registry for Api4 mock results and call tracking.
   */
  class Api4Mock {
    /** @var array<string, array> Keyed by "Entity.action", value is the result data. */
    public static array $results = [];

    /** @var array Captured Api4 calls with entity, action, values, wheres. */
    public static array $calls = [];

    public static function setResult(string $key, $data): void {
      self::$results[$key] = $data;
    }

    public static function reset(): void {
      self::$results = [];
      self::$calls = [];
    }
  }

  /**
   * Fluent Api4 action mock that captures calls and returns configured results.
   */
  class MockApi4Action {
    private string $entity;
    private string $action;
    private array $values = [];
    private array $wheres = [];

    public function __construct(string $entity, string $action) {
      $this->entity = $entity;
      $this->action = $action;
    }

    public function addSelect(string ...$fields): static { return $this; }
    public function selectRowCount(): static { return $this; }
    public function addWhere(string $field, string $op, $value = NULL): static {
      $this->wheres[] = [$field, $op, $value];
      return $this;
    }
    public function addValue(string $field, $value): static {
      $this->values[$field] = $value;
      return $this;
    }
    public function addJoin(mixed ...$args): static { return $this; }
    public function setLimit(int $limit): static { return $this; }

    public function execute(): MockApi4Result {
      $key = "{$this->entity}.{$this->action}";
      Api4Mock::$calls[] = [
        'entity' => $this->entity,
        'action' => $this->action,
        'values' => $this->values,
        'wheres' => $this->wheres,
      ];
      $data = Api4Mock::$results[$key] ?? [];
      return new MockApi4Result(is_array($data) ? $data : []);
    }
  }

  /**
   * Api4 result wrapper supporting first() and iteration.
   */
  class MockApi4Result implements \ArrayAccess, \Iterator, \Countable {
    private array $data;
    private int $pos = 0;

    public function __construct(array $data) {
      $this->data = $data;
    }

    public function first(): ?array {
      return $this->data[0] ?? NULL;
    }

    public function offsetExists(mixed $offset): bool { return isset($this->data[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->data[$offset] ?? NULL; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->data[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->data[$offset]); }
    public function current(): mixed { return $this->data[$this->pos] ?? NULL; }
    public function key(): int { return $this->pos; }
    public function next(): void { $this->pos++; }
    public function rewind(): void { $this->pos = 0; }
    public function valid(): bool { return isset($this->data[$this->pos]); }
    public function count(): int { return count($this->data); }
  }
}

// ---------------------------------------------------------------------------
// Api4 entity stubs
// ---------------------------------------------------------------------------

namespace Civi\Api4 {
  class ContributionRecur {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('ContributionRecur', 'get');
    }
    public static function update($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('ContributionRecur', 'update');
    }
  }

  class Contribution {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('Contribution', 'get');
    }
    public static function update($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('Contribution', 'update');
    }
  }

  class Note {
    public static function create($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('Note', 'create');
    }
  }

  class Contact {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('Contact', 'get');
    }
  }

  class MollieCustomer {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('MollieCustomer', 'get');
    }
    public static function create($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('MollieCustomer', 'create');
    }
  }

  class PaymentToken {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('PaymentToken', 'get');
    }
    public static function create($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('PaymentToken', 'create');
    }
  }

  class Activity {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('Activity', 'get');
    }
    public static function create($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('Activity', 'create');
    }
  }

  class OptionValue {
    public static function get($checkPermissions = TRUE): \Tests\Stubs\MockApi4Action {
      return new \Tests\Stubs\MockApi4Action('OptionValue', 'get');
    }
  }
}

// ---------------------------------------------------------------------------
// Global stubs
// ---------------------------------------------------------------------------

namespace {
  class CiviSettingsMock {
    public static array $values = [];

    public function get(string $name): mixed {
      return self::$values[$name] ?? NULL;
    }

    public static function reset(): void {
      self::$values = [];
    }
  }

  class Civi {
    public static function log(string $channel = ''): object {
      return new class {
        public function warning(string $message, array $context = []): void {}
        public function info(string $message, array $context = []): void {}
        public function error(string $message, array $context = []): void {}
        public function debug(string $message, array $context = []): void {}
      };
    }

    public static function settings(): CiviSettingsMock {
      return new CiviSettingsMock();
    }

    public static function lockManager(): CiviLockManagerMock {
      return new CiviLockManagerMock();
    }
  }

  class CiviLockManagerMock {
    /** @var bool Controls whether acquire() succeeds. */
    public static bool $acquireSucceeds = TRUE;

    /** @var array Captured lock names. */
    public static array $acquiredLocks = [];

    public function acquire(string $name, ?int $timeout = NULL): CiviLockMock {
      self::$acquiredLocks[] = $name;
      return new CiviLockMock(self::$acquireSucceeds);
    }

    public static function reset(): void {
      self::$acquireSucceeds = TRUE;
      self::$acquiredLocks = [];
    }
  }

  class CiviLockMock {
    private bool $acquired;

    public function __construct(bool $acquired) {
      $this->acquired = $acquired;
    }

    public function isAcquired(): bool {
      return $this->acquired;
    }

    public function release(): void {}
  }

  class CRM_Core_Payment {
    protected $_mode;
    protected $_paymentProcessor;
    protected $_component;

    const BILLING_MODE_NOTIFY = 4;

    public function __construct($mode = '', &$paymentProcessor = []) {
      $this->_mode = $mode;
      $this->_paymentProcessor = $paymentProcessor;
    }

    protected function setStatusPaymentCompleted(array $params): array {
      return array_merge($params, ['payment_status_id' => 1, 'payment_status' => 'Completed']);
    }

    protected function setStatusPaymentPending(array $params): array {
      return array_merge($params, ['payment_status_id' => 2, 'payment_status' => 'Pending']);
    }

    protected function getReturnSuccessUrl(string $qfKey): string {
      return "https://example.com/return?qfKey={$qfKey}";
    }

    protected function getNotifyUrl(): string {
      return 'https://example.com/webhook';
    }
  }

  class CRM_Utils_System {
    /** @var string|null Captured redirect URL (test override instead of exit). */
    public static ?string $redirectUrl = NULL;

    public static function redirect(string $url): void {
      self::$redirectUrl = $url;
    }

    public static function resetRedirect(): void {
      self::$redirectUrl = NULL;
    }
  }

  class CRM_Mollie_ExtensionUtil {
    public static function ts(string $text, array $params = []): string {
      foreach ($params as $key => $value) {
        $text = str_replace("%{$key}", (string) $value, $text);
      }
      return $text;
    }
  }

  /**
   * Registry for civicrm_api3 mock calls.
   */
  class Api3Mock {
    public static array $calls = [];
    public static $result = ['is_error' => 0];

    public static function reset(): void {
      self::$calls = [];
      self::$result = ['is_error' => 0];
    }
  }

  class CRM_Utils_Date {
    public static function customFormat(string $date, ?string $format = NULL): string {
      return $date;
    }
  }

  class CRM_Mollie_WorkflowMessage_RecurringReminder {
    /** @var bool Controls whether sendTemplate succeeds in tests. */
    public static bool $sendResult = TRUE;

    /** @var array Captured sendTemplate calls. */
    public static array $sendCalls = [];

    private array $config;

    public function __construct(array $config = []) {
      $this->config = $config;
    }

    public function sendTemplate(array $params): array {
      self::$sendCalls[] = $params;
      return [self::$sendResult, NULL, NULL, NULL, self::$sendResult ? NULL : 'Send failed'];
    }

    public static function reset(): void {
      self::$sendResult = TRUE;
      self::$sendCalls = [];
    }
  }

  if (!function_exists('civicrm_api3')) {
    function civicrm_api3(string $entity, string $action, array $params = []) {
      Api3Mock::$calls[] = compact('entity', 'action', 'params');
      return Api3Mock::$result;
    }
  }
}
