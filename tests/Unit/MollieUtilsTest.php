<?php

namespace Tests\Unit;

use CRM_Mollie_Utils;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\Api4Mock;

class MollieUtilsTest extends TestCase {
  protected function setUp(): void {
    Api4Mock::reset();
    CRM_Mollie_Utils::resetCache();
  }

  // -----------------------------------------------------------------------
  // isMollieProcessor
  // -----------------------------------------------------------------------

  public function testIsMollieProcessorReturnsTrueWhenFound(): void {
    Api4Mock::setResult('PaymentProcessor.get', [
      ['id' => 1],
    ]);

    $this->assertTrue(CRM_Mollie_Utils::isMollieProcessor(1));
  }

  public function testIsMollieProcessorReturnsFalseWhenNotFound(): void {
    Api4Mock::setResult('PaymentProcessor.get', []);

    $this->assertFalse(CRM_Mollie_Utils::isMollieProcessor(99));
  }

  public function testIsMollieProcessorCachesResult(): void {
    Api4Mock::setResult('PaymentProcessor.get', [
      ['id' => 1],
    ]);

    CRM_Mollie_Utils::isMollieProcessor(1);
    CRM_Mollie_Utils::isMollieProcessor(1);

    $ppCalls = array_filter(
      Api4Mock::$calls,
      fn ($c) => $c['entity'] === 'PaymentProcessor',
    );
    $this->assertCount(1, $ppCalls, 'Should only query once per processor ID');
  }

  public function testIsMollieProcessorCachesPerProcessorId(): void {
    Api4Mock::setResult('PaymentProcessor.get', [
      ['id' => 1],
    ]);

    CRM_Mollie_Utils::isMollieProcessor(1);
    CRM_Mollie_Utils::isMollieProcessor(2);

    $ppCalls = array_filter(
      Api4Mock::$calls,
      fn ($c) => $c['entity'] === 'PaymentProcessor',
    );
    $this->assertCount(2, $ppCalls, 'Should query separately for each processor ID');
  }

  // -----------------------------------------------------------------------
  // getMollieCustomerId
  // -----------------------------------------------------------------------

  public function testGetMollieCustomerIdReturnsIdWhenFound(): void {
    Api4Mock::setResult('MollieCustomer.get', [
      ['contact_id' => 10, 'payment_processor_id' => 1, 'mollie_customer_id' => 'cst_abc123'],
    ]);

    $result = CRM_Mollie_Utils::getMollieCustomerId(10, 1);
    $this->assertSame('cst_abc123', $result);
  }

  public function testGetMollieCustomerIdReturnsNullWhenNotFound(): void {
    Api4Mock::setResult('MollieCustomer.get', []);

    $result = CRM_Mollie_Utils::getMollieCustomerId(10, 1);
    $this->assertNull($result);
  }

  public function testGetMollieCustomerIdFiltersbyContactAndProcessor(): void {
    Api4Mock::setResult('MollieCustomer.get', [
      ['contact_id' => 10, 'payment_processor_id' => 1, 'mollie_customer_id' => 'cst_match'],
      ['contact_id' => 20, 'payment_processor_id' => 1, 'mollie_customer_id' => 'cst_other'],
    ]);

    $result = CRM_Mollie_Utils::getMollieCustomerId(10, 1);
    $this->assertSame('cst_match', $result);
  }

  // -----------------------------------------------------------------------
  // getPaymentDashboardUrl
  // -----------------------------------------------------------------------

  public function testGetPaymentDashboardUrl(): void {
    $url = CRM_Mollie_Utils::getPaymentDashboardUrl('tr_WDqYK6vllg');
    $this->assertSame('https://my.mollie.com/dashboard/payments/tr_WDqYK6vllg', $url);
  }

  public function testGetPaymentDashboardUrlEncodesSpecialChars(): void {
    $url = CRM_Mollie_Utils::getPaymentDashboardUrl('tr_foo bar');
    $this->assertSame('https://my.mollie.com/dashboard/payments/tr_foo+bar', $url);
  }

  // -----------------------------------------------------------------------
  // getCustomerDashboardUrl
  // -----------------------------------------------------------------------

  public function testGetCustomerDashboardUrl(): void {
    $url = CRM_Mollie_Utils::getCustomerDashboardUrl('cst_abc123');
    $this->assertSame('https://my.mollie.com/dashboard/customers/cst_abc123', $url);
  }

  public function testGetCustomerDashboardUrlEncodesSpecialChars(): void {
    $url = CRM_Mollie_Utils::getCustomerDashboardUrl('cst_foo bar');
    $this->assertSame('https://my.mollie.com/dashboard/customers/cst_foo+bar', $url);
  }
}
