<?php

namespace Civi\Api4\Action\MollieDetail;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_Mollie_ExtensionUtil as E;
use Mollie\Api\Exceptions\ApiException;

/**
 * Fetch a Mollie resource (payment, subscription, or customer) by ID.
 *
 * Detects the resource type from the ID prefix and returns a flattened
 * key-value representation suitable for display.
 */
class Get extends AbstractAction {

  /**
   * The Mollie resource ID (e.g. tr_xxx, sub_xxx, cst_xxx).
   *
   * @var string
   * @required
   */
  protected string $mollieId = '';

  /**
   * Mollie customer ID, required for subscription lookups.
   *
   * @var string
   */
  protected string $customerId = '';

  /**
   * @param Result $result
   */
  public function _run(Result $result): void {
    $type = self::detectType($this->mollieId);

    // Try live key first, fall back to test key.
    $resource = NULL;
    $lastError = NULL;
    foreach ([FALSE, TRUE] as $testMode) {
      try {
        $client = self::getClientForMode($testMode);
        $resource = match ($type) {
          'payment' => $client->payments->get($this->mollieId),
          'subscription' => $client->subscriptions->getForId($this->customerId, $this->mollieId),
          'customer' => $client->customers->get($this->mollieId),
          default => throw new \CRM_Core_Exception(E::ts('Unknown Mollie ID format: %1', [1 => $this->mollieId])),
        };
        break;
      }
      catch (ApiException $e) {
        $lastError = $e;
      }
    }

    if ($resource === NULL) {
      throw new \CRM_Core_Exception(E::ts('Mollie API error: %1', [1 => $lastError->getMessage()]));
    }

    $dashboardUrl = $resource->_links->dashboard->href ?? '';

    $result[] = [
      'type' => $type,
      'mollie_id' => $this->mollieId,
      'dashboard_url' => $dashboardUrl,
      'fields' => self::flattenResource($resource),
    ];
  }

  /**
   * Detect the Mollie resource type from its ID prefix.
   *
   * @param string $mollieId
   *
   * @return string
   *   One of 'payment', 'subscription', 'customer'.
   */
  protected static function detectType(string $mollieId): string {
    return match (TRUE) {
      str_starts_with($mollieId, 'tr_') => 'payment',
      str_starts_with($mollieId, 'sub_') => 'subscription',
      str_starts_with($mollieId, 'cst_') => 'customer',
      default => 'unknown',
    };
  }

  /**
   * Convert a Mollie resource into a nested display structure.
   *
   * Returns an associative array of label => value, where value is either
   * a string (scalar), a JSON string (metadata), or a nested associative
   * array (for objects rendered as sub-tables).
   *
   * @param object $resource
   *
   * @return array
   */
  protected static function flattenResource(object $resource): array {
    $skip = ['_links', '_embedded', 'resource', 'client'];
    $json = ['metadata'];
    $fields = [];

    foreach (get_object_vars($resource) as $key => $value) {
      if (in_array($key, $skip, TRUE)) {
        continue;
      }
      if ($value === NULL) {
        continue;
      }

      $label = self::humanizeKey($key);

      if (in_array($key, $json, TRUE) && (is_object($value) || is_array($value))) {
        $fields[$label] = ['_json' => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
        continue;
      }

      $fields[$label] = self::convertValue($value);
    }

    return $fields;
  }

  /**
   * Convert a value to a display-friendly representation.
   *
   * @param mixed $value
   *
   * @return string|array
   */
  protected static function convertValue(mixed $value): string|array {
    if (is_object($value)) {
      return self::convertObject($value);
    }
    if (is_array($value)) {
      return self::convertArray($value);
    }
    return (string) $value;
  }

  /**
   * Convert a nested object to a display structure.
   *
   * Recognizes Mollie amount objects ({value, currency}) and renders
   * them as a single formatted string.
   *
   * @param object $obj
   *
   * @return string|array
   */
  protected static function convertObject(object $obj): string|array {
    $vars = get_object_vars($obj);

    // Mollie amount pattern: {value: "25.00", currency: "EUR"}
    if (isset($vars['value'], $vars['currency']) && count($vars) === 2) {
      return "{$vars['currency']} {$vars['value']}";
    }

    $fields = [];
    foreach ($vars as $key => $val) {
      if ($val === NULL || $key === '_links') {
        continue;
      }
      $fields[self::humanizeKey($key)] = self::convertValue($val);
    }
    return $fields;
  }

  /**
   * Convert an array value to a display structure.
   *
   * Scalar arrays are joined with commas. Object/array items are
   * numbered and recursed.
   *
   * @param array $arr
   *
   * @return string|array
   */
  protected static function convertArray(array $arr): string|array {
    if (empty($arr)) {
      return '';
    }

    if (!is_object(reset($arr)) && !is_array(reset($arr))) {
      return implode(', ', $arr);
    }

    $fields = [];
    foreach ($arr as $i => $item) {
      $fields['#' . ($i + 1)] = self::convertValue($item);
    }
    return $fields;
  }

  /**
   * Convert a camelCase key to a human-readable label.
   *
   * @param string $key
   *
   * @return string
   */
  protected static function humanizeKey(string $key): string {
    // Insert space before uppercase letters, then capitalize first word
    $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
    return ucfirst($spaced);
  }

  /**
   * Get an authenticated Mollie API client for test or live mode.
   *
   * @param bool $testMode
   *
   * @return \Mollie\Api\MollieApiClient
   */
  protected static function getClientForMode(bool $testMode): \Mollie\Api\MollieApiClient {
    $typeId = \Civi\Api4\PaymentProcessorType::get(FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', 'mollie')
      ->execute()
      ->first()['id'] ?? NULL;

    if ($typeId === NULL) {
      throw new \CRM_Core_Exception(E::ts('Mollie payment processor type not found.'));
    }

    $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('user_name')
      ->addWhere('payment_processor_type_id', '=', $typeId)
      ->addWhere('is_test', '=', $testMode)
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->first();

    if ($processor === NULL || empty($processor['user_name'])) {
      throw new \CRM_Core_Exception(E::ts('No active Mollie payment processor found.'));
    }

    $client = new \Mollie\Api\MollieApiClient();
    $client->setApiKey($processor['user_name']);

    return $client;
  }

}
