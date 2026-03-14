<?php

// Override sleep() in the Run class's namespace to avoid real delays.

namespace Civi\Api4\Action\MollieSync {

  function sleep(int $seconds): int {
    \Tests\Unit\MollieSyncRunTest::$sleepCalls[] = $seconds;
    return 0;
  }
}

namespace Tests\Unit {

  use Civi\Api4\Action\MollieSync\Run;
  use Mollie\Api\Exceptions\ApiException;
  use Mollie\Api\MollieApiClient;
  use Mollie\Api\Resources\Subscription;
  use PHPUnit\Framework\TestCase;

  /**
   * Test subclass to expose protected methods.
   */
  class TestableMollieSyncRun extends Run {
    public function __construct() {
      // Skip parent constructor.
    }

    public function exposedBuildUpdatesFromSubscription(Subscription $sub, array $recur): array {
      return $this->buildUpdatesFromSubscription($sub, $recur);
    }

    public static function exposedThrottledApiCall(callable $apiCall): mixed {
      return self::throttledApiCall($apiCall);
    }
  }

  /**
   * Testable subclass for end-to-end sync flow testing.
   *
   * Overrides static helpers to inject mock Mollie clients and customer IDs
   * without requiring real CiviCRM API calls.
   */
  class IntegrationTestableSyncRun extends Run {
    /** @var array<int, string> Map of contact_id → mollie_customer_id. */
    public static array $customerMap = [];

    /** @var MollieApiClient|null Shared mock client. */
    public static ?MollieApiClient $mockClient = null;

    public function __construct() {
      parent::__construct('MollieSync', 'run');
    }

    public function callRun(): array {
      $result = new \Civi\Api4\Generic\Result();
      $this->_run($result);
      return $result[0] ?? [];
    }

    protected static function getMollieCustomerId(int $contactId, int $processorId): ?string {
      return self::$customerMap[$contactId] ?? null;
    }

    protected static function getClientForProcessor(int $processorId): MollieApiClient {
      return self::$mockClient;
    }

    protected static function throttledApiCall(callable $apiCall): mixed {
      return $apiCall();
    }

    public static function reset(): void {
      self::$customerMap = [];
      self::$mockClient = null;
    }
  }

  class MollieSyncRunTest extends TestCase {
    /** @var int[] */
    public static array $sleepCalls = [];

    protected function setUp(): void {
      self::$sleepCalls = [];
    }

    // -----------------------------------------------------------------------
    // buildUpdatesFromSubscription
    // -----------------------------------------------------------------------

    private function makeSubscription(array $props): Subscription {
      $client = $this->createMock(MollieApiClient::class);
      $sub = new Subscription($client);
      foreach ($props as $k => $v) {
        $sub->{$k} = $v;
      }
      return $sub;
    }

    private function makeAmount(string $value, string $currency = 'EUR'): \stdClass {
      $amount = new \stdClass();
      $amount->value = $value;
      $amount->currency = $currency;
      return $amount;
    }

    public function testBuildUpdatesNoChanges(): void {
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-04-01',
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame([], $updates);
    }

    public function testBuildUpdatesStatusCompleted(): void {
      $sub = $this->makeSubscription([
        'status' => 'completed',
        'nextPaymentDate' => null,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('Completed', $updates['contribution_status_id:name']);
      $this->assertStringStartsWith(date('Y-m-d'), $updates['end_date']);
      $this->assertNull($updates['next_sched_contribution_date']);
    }

    public function testBuildUpdatesStatusCanceledWithDate(): void {
      $sub = $this->makeSubscription([
        'status' => 'canceled',
        'nextPaymentDate' => null,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => '2026-03-01T10:00:00+00:00',
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('Cancelled', $updates['contribution_status_id:name']);
      $this->assertStringStartsWith('2026-03-01', $updates['cancel_date']);
      $this->assertSame($updates['cancel_date'], $updates['end_date']);
      $this->assertNull($updates['next_sched_contribution_date']);
    }

    public function testBuildUpdatesStatusCanceledWithoutDate(): void {
      $sub = $this->makeSubscription([
        'status' => 'canceled',
        'nextPaymentDate' => null,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => null,
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('Cancelled', $updates['contribution_status_id:name']);
      $this->assertStringStartsWith(date('Y-m-d'), $updates['cancel_date']);
    }

    public function testBuildUpdatesStatusSuspended(): void {
      $sub = $this->makeSubscription([
        'status' => 'suspended',
        'nextPaymentDate' => null,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('Failed', $updates['contribution_status_id:name']);
      $this->assertNull($updates['next_sched_contribution_date']);
    }

    public function testBuildUpdatesStatusActiveRecoversSuspended(): void {
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-04-15',
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'Failed',
        'next_sched_contribution_date' => null,
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('In Progress', $updates['contribution_status_id:name']);
      $this->assertSame('2026-04-15 00:00:00', $updates['next_sched_contribution_date']);
      // Stale failure/cancellation fields must be cleared on recovery.
      $this->assertNull($updates['cancel_date']);
      $this->assertNull($updates['cancel_reason']);
      $this->assertNull($updates['end_date']);
    }

    public function testBuildUpdatesNextDateChanged(): void {
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-05-01',
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('2026-05-01 00:00:00', $updates['next_sched_contribution_date']);
      $this->assertArrayNotHasKey('contribution_status_id:name', $updates);
    }

    public function testBuildUpdatesAmountChanged(): void {
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-04-01',
        'amount' => $this->makeAmount('50.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertSame('50.00', $updates['amount']);
    }

    public function testBuildUpdatesAmountFormattingNoFalsePositive(): void {
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-04-01',
        'amount' => $this->makeAmount('10.0'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '10.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertArrayNotHasKey('amount', $updates);
    }

    public function testBuildUpdatesCompletedDoesNotSyncNextDate(): void {
      $sub = $this->makeSubscription([
        'status' => 'completed',
        'nextPaymentDate' => '2026-05-01',
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
      ];

      $run = new TestableMollieSyncRun();
      $updates = $run->exposedBuildUpdatesFromSubscription($sub, $recur);

      $this->assertNull($updates['next_sched_contribution_date']);
    }

    // -----------------------------------------------------------------------
    // throttledApiCall
    // -----------------------------------------------------------------------

    public function testThrottledApiCallSuccess(): void {
      $result = TestableMollieSyncRun::exposedThrottledApiCall(fn () => 'ok');

      $this->assertSame('ok', $result);
      $this->assertSame([], self::$sleepCalls);
    }

    public function testThrottledApiCallRetryOn429ThenSuccess(): void {
      $attempts = 0;
      $result = TestableMollieSyncRun::exposedThrottledApiCall(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
          throw new ApiException('rate limited', 429);
        }
        return 'ok';
      });

      $this->assertSame('ok', $result);
      $this->assertSame(2, $attempts);
      $this->assertCount(1, self::$sleepCalls);
    }

    public function testThrottledApiCallExhaustsRetries(): void {
      $attempts = 0;

      try {
        TestableMollieSyncRun::exposedThrottledApiCall(function () use (&$attempts) {
          $attempts++;
          throw new ApiException('rate limited', 429);
        });
        $this->fail('Expected ApiException');
      } catch (ApiException) {
        // 4 total attempts: initial + 3 retries.
        $this->assertSame(4, $attempts);
        $this->assertCount(3, self::$sleepCalls);
      }
    }

    public function testThrottledApiCallDoesNotRetryNon429(): void {
      $this->expectException(ApiException::class);
      $this->expectExceptionCode(500);

      TestableMollieSyncRun::exposedThrottledApiCall(function () {
        throw new ApiException('server error', 500);
      });
    }

    // -----------------------------------------------------------------------
    // syncFromMollie — end-to-end
    // -----------------------------------------------------------------------

    public function testSyncUpdatesRecurFromMollieSubscription(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      // Mock: one active recur needing sync.
      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [[
        'id' => 10,
        'processor_id' => 'sub_abc',
        'contact_id' => 100,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => null,
      ]]);

      IntegrationTestableSyncRun::$customerMap = [100 => 'cst_test'];

      // Subscription has a new next date and different amount.
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-05-01',
        'amount' => $this->makeAmount('30.00'),
        'canceledAt' => null,
      ]);

      $mockSubEndpoint = $this->createMock(\Mollie\Api\Endpoints\SubscriptionEndpoint::class);
      $mockSubEndpoint->method('getForId')->willReturn($sub);

      $mockClient = $this->createMock(MollieApiClient::class);
      $mockClient->subscriptions = $mockSubEndpoint;
      IntegrationTestableSyncRun::$mockClient = $mockClient;

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(1, $stats['checked']);
      $this->assertEquals(1, $stats['synced']);

      // Verify ContributionRecur was updated.
      $updates = array_values(array_filter(
        \Tests\Stubs\Api4Mock::$calls,
        fn ($c) =>
        $c['entity'] === 'ContributionRecur' && $c['action'] === 'update',
      ));
      $this->assertNotEmpty($updates);
      $this->assertEquals('30.00', $updates[0]['values']['amount']);
      $this->assertEquals('2026-05-01 00:00:00', $updates[0]['values']['next_sched_contribution_date']);
    }

    public function testSyncSkipsRecurWithNoMollieCustomer(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [[
        'id' => 10,
        'processor_id' => 'sub_abc',
        'contact_id' => 100,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => null,
      ]]);

      // No customer mapping → should skip.
      IntegrationTestableSyncRun::$customerMap = [];

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(1, $stats['checked']);
      $this->assertEquals(0, $stats['synced']);
    }

    public function testSyncCountsApiErrorAndContinues(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [[
        'id' => 10,
        'processor_id' => 'sub_abc',
        'contact_id' => 100,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => null,
      ]]);

      IntegrationTestableSyncRun::$customerMap = [100 => 'cst_test'];

      $mockSubEndpoint = $this->createMock(\Mollie\Api\Endpoints\SubscriptionEndpoint::class);
      $mockSubEndpoint->method('getForId')
        ->willThrowException(new ApiException('Not found', 404));

      $mockClient = $this->createMock(MollieApiClient::class);
      $mockClient->subscriptions = $mockSubEndpoint;
      IntegrationTestableSyncRun::$mockClient = $mockClient;

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(1, $stats['checked']);
      $this->assertEquals(1, $stats['errors']);
      $this->assertEquals(0, $stats['synced']);
    }

    public function testSyncCompletedSubscriptionUpdatesStatus(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [[
        'id' => 10,
        'processor_id' => 'sub_abc',
        'contact_id' => 100,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2026-04-01 00:00:00',
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => null,
      ]]);

      IntegrationTestableSyncRun::$customerMap = [100 => 'cst_test'];

      $sub = $this->makeSubscription([
        'status' => 'completed',
        'nextPaymentDate' => null,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);

      $mockSubEndpoint = $this->createMock(\Mollie\Api\Endpoints\SubscriptionEndpoint::class);
      $mockSubEndpoint->method('getForId')->willReturn($sub);

      $mockClient = $this->createMock(MollieApiClient::class);
      $mockClient->subscriptions = $mockSubEndpoint;
      IntegrationTestableSyncRun::$mockClient = $mockClient;

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(1, $stats['synced']);
      $this->assertEquals(1, $stats['completed']);

      $updates = array_values(array_filter(
        \Tests\Stubs\Api4Mock::$calls,
        fn ($c) =>
        $c['entity'] === 'ContributionRecur' && $c['action'] === 'update',
      ));
      $this->assertEquals('Completed', $updates[0]['values']['contribution_status_id:name']);
      $this->assertNull($updates[0]['values']['next_sched_contribution_date']);
    }

    // -----------------------------------------------------------------------
    // recoverSuspended — end-to-end
    // -----------------------------------------------------------------------

    public function testRecoverSuspendedReactivatesFailedSubscription(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      $failedRecur = [
        'id' => 30,
        'processor_id' => 'sub_suspended',
        'contact_id' => 300,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'Failed',
        'next_sched_contribution_date' => null,
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => null,
      ];

      // The Failed status is filtered by WHERE clauses in the mock:
      // syncFromMollie (IN ['In Progress', 'Pending']) → no match
      // recoverSuspended (= 'Failed') → match
      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [$failedRecur]);

      IntegrationTestableSyncRun::$customerMap = [300 => 'cst_suspended'];

      // Subscription is now active again on Mollie (mandate fixed).
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-04-15',
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);

      $mockSubEndpoint = $this->createMock(\Mollie\Api\Endpoints\SubscriptionEndpoint::class);
      $mockSubEndpoint->method('getForId')->willReturn($sub);

      $mockClient = $this->createMock(MollieApiClient::class);
      $mockClient->subscriptions = $mockSubEndpoint;
      IntegrationTestableSyncRun::$mockClient = $mockClient;

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(0, $stats['checked']);
      $this->assertEquals(1, $stats['suspensions_recovered']);

      $updates = array_values(array_filter(
        \Tests\Stubs\Api4Mock::$calls,
        fn ($c) =>
        $c['entity'] === 'ContributionRecur' && $c['action'] === 'update',
      ));
      $this->assertNotEmpty($updates);
      $this->assertEquals('In Progress', $updates[0]['values']['contribution_status_id:name']);
      $this->assertEquals('2026-04-15 00:00:00', $updates[0]['values']['next_sched_contribution_date']);
    }

    public function testRecoverSuspendedSkipsStillSuspended(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [[
        'id' => 30,
        'processor_id' => 'sub_suspended',
        'contact_id' => 300,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'Failed',
        'next_sched_contribution_date' => null,
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => null,
      ]]);

      IntegrationTestableSyncRun::$customerMap = [300 => 'cst_suspended'];

      // Subscription is still suspended on Mollie.
      $sub = $this->makeSubscription([
        'status' => 'suspended',
        'nextPaymentDate' => null,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => null,
      ]);

      $mockSubEndpoint = $this->createMock(\Mollie\Api\Endpoints\SubscriptionEndpoint::class);
      $mockSubEndpoint->method('getForId')->willReturn($sub);

      $mockClient = $this->createMock(MollieApiClient::class);
      $mockClient->subscriptions = $mockSubEndpoint;
      IntegrationTestableSyncRun::$mockClient = $mockClient;

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(0, $stats['suspensions_recovered']);

      $updates = array_values(array_filter(
        \Tests\Stubs\Api4Mock::$calls,
        fn ($c) =>
        $c['entity'] === 'ContributionRecur' && $c['action'] === 'update',
      ));
      $this->assertEmpty($updates);
    }

    // -----------------------------------------------------------------------
    // retryCancellations — end-to-end
    // -----------------------------------------------------------------------

    public function testRetryCancellationCancelsActiveSubscriptionOnMollie(): void {
      \Tests\Stubs\Api4Mock::reset();
      IntegrationTestableSyncRun::reset();

      $cancelledRecur = [
        'id' => 20,
        'processor_id' => 'sub_cancel',
        'contact_id' => 200,
        'payment_processor_id' => 1,
        'contribution_status_id:name' => 'Cancelled',
        'next_sched_contribution_date' => null,
        'amount' => '25.00',
        'currency' => 'EUR',
        'end_date' => null,
        'cancel_date' => '2026-03-10 00:00:00',
      ];

      // The Cancelled status is filtered by WHERE clauses in the mock:
      // syncFromMollie (IN ['In Progress', 'Pending']) → no match
      // recoverSuspended (= 'Failed') → no match
      // retryCancellations (= 'Cancelled') → match
      \Tests\Stubs\Api4Mock::setResult('ContributionRecur.get', [$cancelledRecur]);

      IntegrationTestableSyncRun::$customerMap = [200 => 'cst_cancel'];

      // Subscription is still active on Mollie.
      $sub = $this->makeSubscription(['status' => 'active']);

      $mockSubEndpoint = $this->createMock(\Mollie\Api\Endpoints\SubscriptionEndpoint::class);
      $mockSubEndpoint->method('getForId')->willReturn($sub);
      $mockSubEndpoint->expects($this->once())->method('cancelForId')
        ->with('cst_cancel', 'sub_cancel');

      $mockClient = $this->createMock(MollieApiClient::class);
      $mockClient->subscriptions = $mockSubEndpoint;
      IntegrationTestableSyncRun::$mockClient = $mockClient;

      $runner = new IntegrationTestableSyncRun();
      $stats = $runner->callRun();

      $this->assertEquals(1, $stats['cancellations_retried']);
      // syncFromMollie should not have processed the Cancelled recur.
      $this->assertEquals(0, $stats['checked']);
    }
  }
}
