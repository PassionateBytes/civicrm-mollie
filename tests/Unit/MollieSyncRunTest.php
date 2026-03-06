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
  use PHPUnit\Framework\Attributes\DataProvider;
  use PHPUnit\Framework\TestCase;
  use Psr\Http\Message\ResponseInterface;

  /**
   * Test subclass to expose protected methods.
   */
  class TestableMollieSyncRun extends Run {

    public function __construct() {
      // Skip parent constructor.
    }

    public static function exposedMapMollieStatusToCiviCrm(string $status): ?string {
      return self::mapMollieStatusToCiviCrm($status);
    }

    public function exposedBuildUpdatesFromSubscription(Subscription $sub, array $recur): array {
      return $this->buildUpdatesFromSubscription($sub, $recur);
    }

    public static function exposedGetRetryAfterSeconds(ApiException $e): int {
      return self::getRetryAfterSeconds($e);
    }

    public static function exposedThrottledApiCall(callable $apiCall): mixed {
      return self::throttledApiCall($apiCall);
    }
  }

  class MollieSyncRunTest extends TestCase {

    /** @var int[] */
    public static array $sleepCalls = [];

    protected function setUp(): void {
      self::$sleepCalls = [];
    }

    // -----------------------------------------------------------------------
    // mapMollieStatusToCiviCrm
    // -----------------------------------------------------------------------

    public static function mollieStatusProvider(): array {
      return [
        'completed' => ['completed', 'Completed'],
        'canceled'  => ['canceled', 'Cancelled'],
        'suspended' => ['suspended', 'Failed'],
        'active'    => ['active', NULL],
        'pending'   => ['pending', NULL],
        'unknown'   => ['unknown_status', NULL],
        'empty'     => ['', NULL],
      ];
    }

    #[DataProvider('mollieStatusProvider')]
    public function testMapMollieStatusToCiviCrm(string $mollieStatus, ?string $expected): void {
      $this->assertSame($expected, TestableMollieSyncRun::exposedMapMollieStatusToCiviCrm($mollieStatus));
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
        'canceledAt' => NULL,
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
        'nextPaymentDate' => NULL,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => NULL,
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
        'nextPaymentDate' => NULL,
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
        'nextPaymentDate' => NULL,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => NULL,
      ]);
      $recur = [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => NULL,
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
        'nextPaymentDate' => NULL,
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => NULL,
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

    public function testBuildUpdatesNextDateChanged(): void {
      $sub = $this->makeSubscription([
        'status' => 'active',
        'nextPaymentDate' => '2026-05-01',
        'amount' => $this->makeAmount('25.00'),
        'canceledAt' => NULL,
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
        'canceledAt' => NULL,
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
        'canceledAt' => NULL,
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
        'canceledAt' => NULL,
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
    // getRetryAfterSeconds
    // -----------------------------------------------------------------------

    private function makeApiExceptionWithResponse(?ResponseInterface $response): ApiException {
      $e = new ApiException('rate limited', 429);
      if ($response !== NULL) {
        $ref = new \ReflectionProperty(ApiException::class, 'response');
        $ref->setValue($e, $response);
      }
      return $e;
    }

    public function testGetRetryAfterSecondsNullResponse(): void {
      $e = new ApiException('rate limited', 429);
      $this->assertSame(5, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e));
    }

    public function testGetRetryAfterSecondsValidHeader(): void {
      $response = $this->createMock(ResponseInterface::class);
      $response->method('hasHeader')->with('Retry-After')->willReturn(TRUE);
      $response->method('getHeader')->with('Retry-After')->willReturn(['10']);

      $e = $this->makeApiExceptionWithResponse($response);
      $this->assertSame(10, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e));
    }

    public function testGetRetryAfterSecondsZeroFallsBackToDefault(): void {
      $response = $this->createMock(ResponseInterface::class);
      $response->method('hasHeader')->with('Retry-After')->willReturn(TRUE);
      $response->method('getHeader')->with('Retry-After')->willReturn(['0']);

      $e = $this->makeApiExceptionWithResponse($response);
      $this->assertSame(5, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e));
    }

    public function testGetRetryAfterSecondsTooHighFallsBackToDefault(): void {
      $response = $this->createMock(ResponseInterface::class);
      $response->method('hasHeader')->with('Retry-After')->willReturn(TRUE);
      $response->method('getHeader')->with('Retry-After')->willReturn(['61']);

      $e = $this->makeApiExceptionWithResponse($response);
      $this->assertSame(5, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e));
    }

    public function testGetRetryAfterSecondsNoHeader(): void {
      $response = $this->createMock(ResponseInterface::class);
      $response->method('hasHeader')->with('Retry-After')->willReturn(FALSE);

      $e = $this->makeApiExceptionWithResponse($response);
      $this->assertSame(5, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e));
    }

    public function testGetRetryAfterSecondsBoundaryValues(): void {
      $response1 = $this->createMock(ResponseInterface::class);
      $response1->method('hasHeader')->willReturn(TRUE);
      $response1->method('getHeader')->willReturn(['1']);
      $e1 = $this->makeApiExceptionWithResponse($response1);
      $this->assertSame(1, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e1));

      $response60 = $this->createMock(ResponseInterface::class);
      $response60->method('hasHeader')->willReturn(TRUE);
      $response60->method('getHeader')->willReturn(['60']);
      $e60 = $this->makeApiExceptionWithResponse($response60);
      $this->assertSame(60, TestableMollieSyncRun::exposedGetRetryAfterSeconds($e60));
    }

    // -----------------------------------------------------------------------
    // throttledApiCall
    // -----------------------------------------------------------------------

    public function testThrottledApiCallSuccess(): void {
      $result = TestableMollieSyncRun::exposedThrottledApiCall(fn() => 'ok');

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
      $this->assertSame(5, self::$sleepCalls[0]);
    }

    public function testThrottledApiCallExhaustsRetries(): void {
      $attempts = 0;

      $this->expectException(ApiException::class);
      $this->expectExceptionCode(429);

      TestableMollieSyncRun::exposedThrottledApiCall(function () use (&$attempts) {
        $attempts++;
        throw new ApiException('rate limited', 429);
      });
    }

    public function testThrottledApiCallDoesNotRetryNon429(): void {
      $this->expectException(ApiException::class);
      $this->expectExceptionCode(500);

      TestableMollieSyncRun::exposedThrottledApiCall(function () {
        throw new ApiException('server error', 500);
      });
    }

    public function testThrottledApiCallRetriesMaxThreeTimes(): void {
      $attempts = 0;

      try {
        TestableMollieSyncRun::exposedThrottledApiCall(function () use (&$attempts) {
          $attempts++;
          throw new ApiException('rate limited', 429);
        });
      }
      catch (ApiException) {
        // Expected.
      }

      // 4 total attempts: initial + 3 retries.
      $this->assertSame(4, $attempts);
      $this->assertCount(3, self::$sleepCalls);
    }
  }
}
