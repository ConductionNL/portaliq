<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityContributionService;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivityStore;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\ShillinqContributionRaiser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Billing an activity's confirmed places through shillinq
 * (activity-offer-contract-fix): the activity is the chargeable, the child the
 * beneficiary, the guardian the debtor, and portaliq writes each returned
 * reference into the sign-up. The store is in memory and the raiser a double
 * that records every payload.
 *
 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
 */
class ActivityContributionServiceTest extends TestCase {
	/**
	 * What staff type into the raise.
	 */
	private const CHARGE = ['amount' => '25.00', 'voluntary' => true, 'administrationId' => 'adm-school-1', 'dueDate' => '2026-11-01'];

	/**
	 * The payloads the raiser received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $payloads = [];

	/**
	 * A store holding the chess club and its sign-ups.
	 *
	 * @param array<int, array<string, mixed>> $signups The sign-ups.
	 * @param bool $paymentRequested Whether the club asks a contribution.
	 *
	 * @return InMemoryActivityStore
	 */
	private function store(array $signups, bool $paymentRequested = true): InMemoryActivityStore {
		return new InMemoryActivityStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			[
				ActivityStore::OFFER => [['id' => 'activity-schaakclub-najaar', 'title' => 'Schaakclub najaar', 'status' => 'open', 'paymentRequested' => $paymentRequested]],
				ActivityStore::SIGNUP => $signups,
			]
		);
	}//end store()

	/**
	 * One sign-up for the chess club.
	 *
	 * @param string $id The sign-up id.
	 * @param string $child The child.
	 * @param string $guardian The guardian.
	 * @param string $status The status.
	 * @param string $reference An existing payment request reference.
	 *
	 * @return array<string, mixed>
	 */
	private function signup(string $id, string $child, string $guardian, string $status = 'confirmed', string $reference = ''): array {
		$row = ['id' => $id, 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => $child, 'guardianRef' => $guardian, 'status' => $status];
		if ($reference !== '') {
			$row['paymentRequestRef'] = $reference;
		}

		return $row;
	}//end signup()

	/**
	 * The guardians' portal accounts; `guardian-noor` has no email.
	 *
	 * @return PortalAccountLookup
	 */
	private function accounts(): PortalAccountLookup {
		$accounts = [
			'guardian-anna' => ['subjectRef' => 'guardian-anna', 'displayName' => 'Anna de Vries', 'email' => 'anna@example.nl'],
			'guardian-piet' => ['subjectRef' => 'guardian-piet', 'displayName' => 'Piet Bakker', 'email' => '', 'verifiedEmail' => 'piet@example.nl'],
			'guardian-noor' => ['subjectRef' => 'guardian-noor', 'displayName' => 'Noor Yilmaz', 'email' => ''],
		];
		$lookup = $this->createMock(PortalAccountLookup::class);
		$lookup->method('bySubjectRef')->willReturnCallback(static fn (string $ref) => ($accounts[$ref] ?? null));

		return $lookup;
	}//end accounts()

	/**
	 * A raiser double answering each recipient with the given results.
	 *
	 * @param callable $answer `(array $payload) => array` shillinq's answer.
	 *
	 * @return ShillinqContributionRaiser
	 */
	private function raiser(callable $answer): ShillinqContributionRaiser {
		$raiser = $this->createMock(ShillinqContributionRaiser::class);
		$raiser->method('raise')->willReturnCallback(
			function (array $payload) use ($answer): array {
				$this->payloads[] = $payload;
				return $answer($payload);
			}
		);

		return $raiser;
	}//end raiser()

	/**
	 * Shillinq raising every recipient, with request ids `pr-<childRef>`.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function raisedAll(array $payload): array {
		$results = [];
		foreach ($payload['recipients'] as $index => $recipient) {
			$results[] = ['index' => $index, 'status' => 'raised', 'paymentRequestId' => 'pr-' . $recipient['beneficiary']['id']];
		}

		return ['batchId' => 'ctb-1', 'results' => $results];
	}//end raisedAll()

	/**
	 * The service over the doubles.
	 *
	 * @param InMemoryActivityStore $store The store.
	 * @param ShillinqContributionRaiser $raiser The raiser double.
	 *
	 * @return ActivityContributionService
	 */
	private function service(InMemoryActivityStore $store, ShillinqContributionRaiser $raiser): ActivityContributionService {
		return new ActivityContributionService($store, new ActivityPlaces(), $this->accounts(), $raiser);
	}//end service()

	/**
	 * Two unbilled confirmed places are sent once, with the activity as
	 * chargeable, the child as beneficiary and the guardian as debtor, and
	 * each gets its reference. A waitlisted place and a billed one are left.
	 *
	 * @return void
	 */
	public function testConfirmedPlacesAreRaisedAndReferenced(): void {
		$store = $this->store(
			[
				$this->signup('s1', 'child-devries-lars', 'guardian-anna'),
				$this->signup('s2', 'child-bakker-sem', 'guardian-piet'),
				$this->signup('s3', 'child-jansen-eva', 'guardian-anna', 'waitlisted'),
				$this->signup('s4', 'child-devries-mila', 'guardian-anna', 'confirmed', 'pr-earlier'),
			]
		);

		$result = $this->service($store, $this->raiser([self::class, 'raisedAll']))->raise('activity-schaakclub-najaar', self::CHARGE);

		$this->assertCount(1, $this->payloads);
		$payload = $this->payloads[0];
		$this->assertSame(
			['app' => 'portaliq', 'type' => 'activity-offer', 'register' => 'portaliq', 'schema' => 'activityOffer', 'id' => 'activity-schaakclub-najaar'],
			$payload['chargeable']
		);
		$this->assertSame('activity', $payload['kind']);
		$this->assertSame('Schaakclub najaar', $payload['description']);
		$this->assertSame(25.0, $payload['amount']);
		$this->assertTrue($payload['voluntary']);
		$this->assertSame('adm-school-1', $payload['administrationId']);
		$this->assertSame('2026-11-01', $payload['dueDate']);
		$this->assertSame(
			[
				['debtor' => ['portalSubjectRef' => 'guardian-anna', 'name' => 'Anna de Vries', 'email' => 'anna@example.nl'], 'beneficiary' => ['type' => 'learner', 'id' => 'child-devries-lars']],
				['debtor' => ['portalSubjectRef' => 'guardian-piet', 'name' => 'Piet Bakker', 'email' => 'piet@example.nl'], 'beneficiary' => ['type' => 'learner', 'id' => 'child-bakker-sem']],
			],
			$payload['recipients']
		);

		$this->assertSame('pr-child-devries-lars', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-devries-lars')[0]['paymentRequestRef']);
		$this->assertSame('pr-child-bakker-sem', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-bakker-sem')[0]['paymentRequestRef']);
		$this->assertArrayNotHasKey('paymentRequestRef', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-jansen-eva')[0]);
		$this->assertSame('pr-earlier', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-devries-mila')[0]['paymentRequestRef']);
		$this->assertSame(['raised' => 2, 'skipped' => 0, 'failed' => 0], array_slice($result, 0, 3, true));

		// No amount lands on any portaliq row.
		foreach ($store->saves as $save) {
			$this->assertArrayNotHasKey('amount', $save['data']);
		}
	}//end testConfirmedPlacesAreRaisedAndReferenced()

	/**
	 * A place shillinq already billed answers `skipped` with the standing
	 * request, and that id is written, healing a failed earlier write.
	 *
	 * @return void
	 */
	public function testASkippedResultStillWritesTheStandingReference(): void {
		$store = $this->store([$this->signup('s1', 'child-devries-lars', 'guardian-anna')]);
		$raiser = $this->raiser(static fn (array $payload): array => ['results' => [['index' => 0, 'status' => 'skipped', 'reason' => 'already-raised', 'paymentRequestId' => 'pr-standing']]]);

		$result = $this->service($store, $raiser)->raise('activity-schaakclub-najaar', self::CHARGE);

		$this->assertSame('pr-standing', $store->where(ActivityStore::SIGNUP, 'id', 's1')[0]['paymentRequestRef']);
		$this->assertSame(1, $result['skipped']);
		$this->assertSame('skipped', $result['results'][0]['status']);
		$this->assertSame('pr-standing', $result['results'][0]['paymentRequestRef']);
	}//end testASkippedResultStillWritesTheStandingReference()

	/**
	 * A guardian without an email is reported and not sent; the other place
	 * is raised. A place shillinq fails keeps no reference.
	 *
	 * @return void
	 */
	public function testAGuardianWithoutContactDetailsIsReportedAndNotSent(): void {
		$store = $this->store(
			[
				$this->signup('s1', 'child-yilmaz-noor', 'guardian-noor'),
				$this->signup('s2', 'child-devries-lars', 'guardian-anna'),
				$this->signup('s3', 'child-onbekend', 'guardian-unknown'),
			]
		);
		$raiser = $this->raiser(static fn (array $payload): array => ['results' => [['index' => 0, 'status' => 'failed', 'reason' => 'no customer']]]);

		$result = $this->service($store, $raiser)->raise('activity-schaakclub-najaar', self::CHARGE);

		$this->assertCount(1, $this->payloads[0]['recipients']);
		$this->assertSame('guardian-anna', $this->payloads[0]['recipients'][0]['debtor']['portalSubjectRef']);
		$this->assertSame(['raised' => 0, 'skipped' => 0, 'failed' => 3], array_slice($result, 0, 3, true));
		$this->assertSame(['signupId' => 's1', 'childRef' => 'child-yilmaz-noor', 'status' => 'failed', 'reason' => 'no_contact_details'], $result['results'][0]);
		$this->assertSame('no customer', $result['results'][2]['reason']);
		$this->assertSame([], $store->saves);
	}//end testAGuardianWithoutContactDetailsIsReportedAndNotSent()

	/**
	 * Refusals before any call: unknown activity, no contribution asked, an
	 * incomplete charge, unreadable sign-ups; and shillinq's own refusal is
	 * passed on with nothing written.
	 *
	 * @return void
	 */
	public function testRefusalsNeverReachShillinqOrWrite(): void {
		$never = $this->raiser(static fn (array $payload): array => ['results' => []]);
		$store = $this->store([$this->signup('s1', 'child-devries-lars', 'guardian-anna')]);

		$this->assertSame(['error' => 'not_found'], $this->service($store, $never)->raise('ghost', self::CHARGE));
		$this->assertSame(['error' => 'payment_not_requested'], $this->service($this->store([], false), $never)->raise('activity-schaakclub-najaar', self::CHARGE));
		foreach ([['amount' => 0] + self::CHARGE, ['voluntary' => 'yes'] + self::CHARGE, ['administrationId' => ' '] + self::CHARGE] as $charge) {
			$this->assertSame(['error' => 'invalid_charge'], $this->service($store, $never)->raise('activity-schaakclub-najaar', $charge));
		}

		$store->unreadable = [ActivityStore::SIGNUP];
		$this->assertSame(['error' => 'activity_unavailable'], $this->service($store, $never)->raise('activity-schaakclub-najaar', self::CHARGE));
		$this->assertSame([], $this->payloads);

		$store->unreadable = [];
		$refused = $this->raiser(static fn (array $payload): array => ['error' => ShillinqContributionRaiser::ERROR_FORBIDDEN]);
		$this->assertSame(['error' => 'forbidden'], $this->service($store, $refused)->raise('activity-schaakclub-najaar', self::CHARGE));
		$this->assertSame([], $store->saves);
	}//end testRefusalsNeverReachShillinqOrWrite()

	/**
	 * More than 200 places go in chunks of 200, and results map back by the
	 * index within their own chunk.
	 *
	 * @return void
	 */
	public function testRecipientsGoInChunksOfTwoHundred(): void {
		$signups = [];
		for ($i = 0; $i < 201; $i++) {
			$signups[] = $this->signup('s' . $i, 'child-' . $i, 'guardian-anna');
		}

		$store = $this->store($signups);
		$result = $this->service($store, $this->raiser([self::class, 'raisedAll']))->raise('activity-schaakclub-najaar', self::CHARGE);

		$this->assertCount(2, $this->payloads);
		$this->assertCount(200, $this->payloads[0]['recipients']);
		$this->assertCount(1, $this->payloads[1]['recipients']);
		$this->assertSame(201, $result['raised']);
		$this->assertSame('pr-child-200', $store->where(ActivityStore::SIGNUP, 'id', 's200')[0]['paymentRequestRef']);
	}//end testRecipientsGoInChunksOfTwoHundred()
}//end class
