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
    public function offsetExists(mixed $offset): bool { return FALSE; }
    public function offsetGet(mixed $offset): mixed { return NULL; }
    public function offsetSet(mixed $offset, mixed $value): void {}
    public function offsetUnset(mixed $offset): void {}
    public function current(): mixed { return NULL; }
    public function key(): mixed { return NULL; }
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool { return FALSE; }
    public function count(): int { return 0; }
  }
}

namespace Civi\Payment\Exception {
  class PaymentProcessorException extends \RuntimeException {}
}

namespace {
  class Civi {
    public static function log(string $channel = ''): object {
      return new class {
        public function warning(string $message, array $context = []): void {}
        public function info(string $message, array $context = []): void {}
        public function error(string $message, array $context = []): void {}
        public function debug(string $message, array $context = []): void {}
      };
    }

    public static function settings(): object {
      return new class {
        public function get(string $name): mixed { return NULL; }
      };
    }
  }

  class CRM_Core_Payment {
    protected $_mode;
    protected $_paymentProcessor;
    public function __construct($mode = '', &$paymentProcessor = []) {
      $this->_mode = $mode;
      $this->_paymentProcessor = $paymentProcessor;
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
}
