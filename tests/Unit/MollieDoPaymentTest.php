<?php

namespace Tests\Unit;

use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\Endpoints\CustomerEndpoint;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Payment;
use Civi\Payment\Exception\PaymentProcessorException;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\Api4Mock;

/**
 * Test subclass that controls the Mollie API client and captures redirects.
 */
class DoPaymentTestableMollie extends \CRM_Core_Payment_Mollie {

  public ?MollieApiClient $stubbedMollieClient = NULL;

  /** @var string|null Captured redirect URL. */
  public ?string $lastRedirectUrl = NULL;

  public function __construct(array $processorConfig = []) {
    $this->_paymentProcessor = array_merge(['id' => 1], $processorConfig);
    $this->_mode = ($processorConfig['is_test'] ?? FALSE) ? 'test' : 'live';
  }

  public function callDoPayment(array &$params, string $component = 'contribute'): array {
    return $this->doPayment($params, $component);
  }

  protected function getMollieApiClient(): MollieApiClient {
    return $this->stubbedMollieClient;
  }

  protected function getMollieLocale(int $contactId): ?string {
    return NULL;
  }
}

class MollieDoPaymentTest extends TestCase {

  protected function setUp(): void {
    Api4Mock::reset();
    \Api3Mock::reset();
    \CRM_Utils_System::resetRedirect();
  }

  // -----------------------------------------------------------------------
  // Zero-amount short-circuit
  // -----------------------------------------------------------------------

  public function testZeroAmountCompletesImmediately(): void {
    $processor = new DoPaymentTestableMollie(['user_name' => 'test_abc']);

    $params = [
      'amount' => 0,
      'contributionID' => 100,
      'contactID' => 200,
      'qfKey' => 'abc123',
    ];

    $result = $processor->callDoPayment($params);

    $this->assertEquals('Completed', $result['payment_status']);
    // No Mollie API call should have been made.
    $this->assertNull($processor->stubbedMollieClient);
    // No redirect should have occurred.
    $this->assertNull(\CRM_Utils_System::$redirectUrl);
  }

  // -----------------------------------------------------------------------
  // One-off payment
  // -----------------------------------------------------------------------

  public function testOneOffPaymentCreatesMolliePaymentAndRedirects(): void {
    $processor = new DoPaymentTestableMollie(['user_name' => 'test_abc']);

    $molliePayment = new Payment($this->createMockClient());
    $molliePayment->id = 'tr_test123';
    $molliePayment->_links = (object) [
      'checkout' => (object) ['href' => 'https://checkout.mollie.com/pay/test123'],
    ];

    $mockClient = $this->createMockClient();
    $mockClient->payments = $this->createMock(PaymentEndpoint::class);
    $mockClient->payments
      ->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $params) {
        // Verify amount formatting.
        $this->assertEquals('EUR', $params['amount']['currency']);
        $this->assertEquals('25.00', $params['amount']['value']);
        // Verify metadata.
        $this->assertEquals(100, $params['metadata']['civicrm']['contribution_id']);
        $this->assertEquals(200, $params['metadata']['civicrm']['contact_id']);
        // Verify no recurring params.
        $this->assertArrayNotHasKey('sequenceType', $params);
        $this->assertArrayNotHasKey('customerId', $params);
        return TRUE;
      }))
      ->willReturn($molliePayment);

    $processor->stubbedMollieClient = $mockClient;

    $params = [
      'amount' => 25.00,
      'currency' => 'EUR',
      'contributionID' => 100,
      'contactID' => 200,
      'qfKey' => 'abc123',
    ];

    $processor->callDoPayment($params);

    // Verify trxn_id was stored via Api4 update.
    $contributionUpdates = array_filter(Api4Mock::$calls, fn($c) =>
      $c['entity'] === 'Contribution' && $c['action'] === 'update'
    );
    $this->assertNotEmpty($contributionUpdates);
    $update = array_values($contributionUpdates)[0];
    $this->assertEquals('tr_test123', $update['values']['trxn_id']);

    // Verify redirect to checkout.
    $this->assertEquals('https://checkout.mollie.com/pay/test123', \CRM_Utils_System::$redirectUrl);
  }

  // -----------------------------------------------------------------------
  // Recurring first payment
  // -----------------------------------------------------------------------

  public function testRecurringPaymentAddsSequenceTypeAndCustomerId(): void {
    $processor = new DoPaymentTestableMollie(['user_name' => 'test_abc']);

    // Mock: existing MollieCustomer found.
    Api4Mock::setResult('MollieCustomer.get', [
      ['mollie_customer_id' => 'cst_existing'],
    ]);

    $molliePayment = new Payment($this->createMockClient());
    $molliePayment->id = 'tr_recur_first';
    $molliePayment->_links = (object) [
      'checkout' => (object) ['href' => 'https://checkout.mollie.com/pay/recur'],
    ];

    $mockClient = $this->createMockClient();
    $mockClient->payments = $this->createMock(PaymentEndpoint::class);
    $mockClient->payments
      ->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $params) {
        $this->assertEquals('first', $params['sequenceType']);
        $this->assertEquals('cst_existing', $params['customerId']);
        $this->assertEquals(42, $params['metadata']['civicrm']['contribution_recur_id']);
        return TRUE;
      }))
      ->willReturn($molliePayment);

    $processor->stubbedMollieClient = $mockClient;

    $params = [
      'amount' => 10.00,
      'currency' => 'EUR',
      'contributionID' => 101,
      'contactID' => 200,
      'qfKey' => 'abc123',
      'is_recur' => TRUE,
      'contributionRecurID' => 42,
    ];

    $processor->callDoPayment($params);

    $this->assertEquals('https://checkout.mollie.com/pay/recur', \CRM_Utils_System::$redirectUrl);
  }

  public function testRecurringPaymentCreatesNewMollieCustomerWhenNoneExists(): void {
    $processor = new DoPaymentTestableMollie(['user_name' => 'test_abc']);

    // Mock: no existing MollieCustomer.
    Api4Mock::setResult('MollieCustomer.get', []);
    // Mock: contact lookup.
    Api4Mock::setResult('Contact.get', [
      ['display_name' => 'Test Donor', 'email_primary.email' => 'donor@example.com'],
    ]);

    $mollieCustomer = new Customer($this->createMockClient());
    $mollieCustomer->id = 'cst_new';

    $molliePayment = new Payment($this->createMockClient());
    $molliePayment->id = 'tr_recur_new';
    $molliePayment->_links = (object) [
      'checkout' => (object) ['href' => 'https://checkout.mollie.com/pay/new'],
    ];

    $mockClient = $this->createMockClient();

    $mockClient->customers = $this->createMock(CustomerEndpoint::class);
    $mockClient->customers
      ->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $params) {
        $this->assertEquals('Test Donor', $params['name']);
        $this->assertEquals('donor@example.com', $params['email']);
        return TRUE;
      }))
      ->willReturn($mollieCustomer);

    $mockClient->payments = $this->createMock(PaymentEndpoint::class);
    $mockClient->payments
      ->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $params) {
        $this->assertEquals('cst_new', $params['customerId']);
        return TRUE;
      }))
      ->willReturn($molliePayment);

    $processor->stubbedMollieClient = $mockClient;

    $params = [
      'amount' => 10.00,
      'currency' => 'EUR',
      'contributionID' => 102,
      'contactID' => 200,
      'qfKey' => 'abc123',
      'is_recur' => TRUE,
      'contributionRecurID' => 43,
    ];

    $processor->callDoPayment($params);

    // Verify MollieCustomer was created in CiviCRM.
    $customerCreates = array_filter(Api4Mock::$calls, fn($c) =>
      $c['entity'] === 'MollieCustomer' && $c['action'] === 'create'
    );
    $this->assertNotEmpty($customerCreates);
    $create = array_values($customerCreates)[0];
    $this->assertEquals('cst_new', $create['values']['mollie_customer_id']);
    $this->assertEquals(200, $create['values']['contact_id']);
  }

  // -----------------------------------------------------------------------
  // API failure
  // -----------------------------------------------------------------------

  public function testMollieApiFailureThrowsPaymentProcessorException(): void {
    $processor = new DoPaymentTestableMollie(['user_name' => 'test_abc']);

    $mockClient = $this->createMockClient();
    $mockClient->payments = $this->createMock(PaymentEndpoint::class);
    $mockClient->payments
      ->method('create')
      ->willThrowException(new ApiException('Unauthorized', 401));

    $processor->stubbedMollieClient = $mockClient;

    $params = [
      'amount' => 25.00,
      'currency' => 'EUR',
      'contributionID' => 103,
      'contactID' => 200,
      'qfKey' => 'abc123',
    ];

    $this->expectException(PaymentProcessorException::class);
    $processor->callDoPayment($params);
  }

  // -----------------------------------------------------------------------
  // Helpers
  // -----------------------------------------------------------------------

  private function createMockClient(): MollieApiClient {
    return $this->createMock(MollieApiClient::class);
  }

}
