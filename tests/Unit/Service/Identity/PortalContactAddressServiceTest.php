<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Event\PortalContactDetailsChangedEvent;
use OCA\Portaliq\Service\Identity\ContactAddressBook;
use OCA\Portaliq\Service\Identity\PortalContactAddressService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * identity-profile-page REQ-IPP-002 to REQ-IPP-004 over the fake store: a new
 * address waits for its link, a preferred one becomes the address
 * notifications go to, and a changed channel is announced once.
 *
 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md
 */
class PortalContactAddressServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * The events the service dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $events = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->events = [];
		$this->seedRow('portalAccount', [
			'subjectRef' => 'subject-1',
			'organisation' => 'gemeente-x',
			'audience' => 'client',
			'displayName' => 'Ans de Vries',
			'email' => 'old@example.nl',
			'status' => 'active',
		]);

	}//end setUp()

	public function testAChangedAddressWaitsForTheLinkAndNotificationsStayOnTheOldOne(): void {
		$added = $this->service()->addAddress(subjectRef: 'subject-1', kind: 'email', value: 'new@example.nl');

		$this->assertSame('', $added['refusal']);
		$this->assertNotSame('', $added['confirmationToken']);
		$account = $this->account();
		$this->assertSame('old@example.nl', $account['email']);
		$this->assertSame('new@example.nl', $account['pendingEmail']);
		$this->assertSame('add', $account['pendingEmailMode']);

		$this->selfService()->confirmEmail(token: $added['confirmationToken']);
		$account = $this->account();
		$this->assertSame('old@example.nl', $account['email'], 'an added address does not take over on confirmation');
		$this->assertSame(
			['kind' => 'email', 'value' => 'new@example.nl', 'confirmed' => true, 'preferred' => false],
			$account['contactAddresses'][1]
		);
		$this->assertNotSame('', $account['pendingEmailExpiresAt'], 'an empty string is not a date-time: the schema would refuse the row');
		$this->assertNull($this->selfService()->confirmEmail(token: $added['confirmationToken']), 'the same link works once');

	}//end testAChangedAddressWaitsForTheLinkAndNotificationsStayOnTheOldOne()

	public function testASecondConfirmedAddressMarkedPreferredIsWhereNotificationsGo(): void {
		$added = $this->service()->addAddress(subjectRef: 'subject-1', kind: 'email', value: 'b@example.nl');
		$this->selfService()->confirmEmail(token: $added['confirmationToken']);

		$this->assertSame('', $this->service()->preferAddress(subjectRef: 'subject-1', kind: 'email', value: 'b@example.nl'));

		$this->assertSame('b@example.nl', $this->account()['email']);

	}//end testASecondConfirmedAddressMarkedPreferredIsWhereNotificationsGo()

	public function testAnUnconfirmedAddressCannotBePreferred(): void {
		$this->service()->addAddress(subjectRef: 'subject-1', kind: 'email', value: 'c@example.nl');

		$this->assertSame('confirm_first', $this->service()->preferAddress(subjectRef: 'subject-1', kind: 'email', value: 'c@example.nl'));
		$this->assertSame('old@example.nl', $this->account()['email']);

	}//end testAnUnconfirmedAddressCannotBePreferred()

	public function testRemovingThePendingAddressAlsoStopsItsLink(): void {
		$added = $this->service()->addAddress(subjectRef: 'subject-1', kind: 'email', value: 'c@example.nl');

		$this->assertSame('', $this->service()->removeAddress(subjectRef: 'subject-1', kind: 'email', value: 'c@example.nl'));

		$this->assertSame('', $this->account()['pendingEmail']);
		$this->assertNull($this->selfService()->confirmEmail(token: $added['confirmationToken']));

	}//end testRemovingThePendingAddressAlsoStopsItsLink()

	public function testAPhoneNumberIsKeptWithoutAMail(): void {
		$added = $this->service()->addAddress(subjectRef: 'subject-1', kind: 'phone', value: '06-12345678');

		$this->assertSame('', $added['confirmationToken']);
		$this->assertSame('+31612345678', $added['value']);
		$this->assertSame('+31612345678', $this->account()['contactAddresses'][1]['value']);
		$this->assertSame('invalid', $this->service()->addAddress(subjectRef: 'subject-1', kind: 'fax', value: '0201234567')['refusal']);

	}//end testAPhoneNumberIsKeptWithoutAMail()

	public function testChoosingPostIsRecordedAndAnnouncedOnce(): void {
		$this->assertSame('', $this->service()->chooseChannel(subjectRef: 'subject-1', channel: 'post'));
		$this->assertSame('', $this->service()->chooseChannel(subjectRef: 'subject-1', channel: 'post'));

		$this->assertSame('post', $this->account()['contactChannel']);
		$this->assertCount(1, $this->events, 'an unchanged save announces nothing');
		$event = $this->events[0];
		$this->assertInstanceOf(PortalContactDetailsChangedEvent::class, $event);
		$this->assertSame('subject-1', $event->getSubjectRef());
		$this->assertSame('gemeente-x', $event->getOrganisation());
		$this->assertSame('post', $event->getChannel());
		$this->assertTrue($event->hasPreferredEmail());
		$this->assertFalse($event->hasPreferredPhone());
		$this->assertSame('invalid', $this->service()->chooseChannel(subjectRef: 'subject-1', channel: 'pigeon'));

	}//end testChoosingPostIsRecordedAndAnnouncedOnce()

	public function testSomebodyElsesAccountIsNeverTouched(): void {
		$this->assertSame('no_account', $this->service()->addAddress(subjectRef: 'someone-else', kind: 'email', value: 'x@example.nl')['refusal']);
		$this->assertSame('no_account', $this->service()->chooseChannel(subjectRef: 'someone-else', channel: 'post'));
		$this->assertArrayNotHasKey('contactAddresses', $this->account());

	}//end testSomebodyElsesAccountIsNeverTouched()

	/**
	 * The account row as it now stands.
	 *
	 * @return array<string, mixed>
	 */
	private function account(): array {
		return $this->storedRows('portalAccount')[0];
	}//end account()

	/**
	 * An account service that reads the fake store.
	 *
	 * @return PortalAccountService
	 */
	private function accounts(): PortalAccountService {
		$accounts = $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef'])
			->getMock();
		$accounts->method('findBySubjectRef')->willReturnCallback(
			function (string $subjectRef): ?array {
				foreach ($this->storedRows('portalAccount') as $row) {
					if (($row['subjectRef'] ?? '') === $subjectRef) {
						return $row;
					}
				}

				return null;
			}
		);

		return $accounts;
	}//end accounts()

	/**
	 * The service under test, over the fake store, with a recording dispatcher.
	 *
	 * @return PortalContactAddressService
	 */
	private function service(): PortalContactAddressService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		return new PortalContactAddressService($this->accounts(), $this->fakeWriter(), $this->fakeRandom(), $dispatcher, new ContactAddressBook());
	}//end service()

	/**
	 * The real self-service service, which follows the confirmation link.
	 *
	 * @return PortalSelfServiceService
	 */
	private function selfService(): PortalSelfServiceService {
		return new PortalSelfServiceService($this->accounts(), $this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end selfService()
}//end class
