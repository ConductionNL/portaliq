<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Event\PortalAccountProvisionRequestedEvent;
use OCA\Portaliq\Listener\PortalAccountProvisionListener;
use OCA\Portaliq\Service\Identity\NextcloudAccountProvisioner;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An app provisions an active `nextcloud`-mode account through the provision
 * event (an-app-provisions-a-nextcloud-account). The real event, listener and
 * provisioner run; only the register (reader, writer), the user manager and
 * the published portals are doubles.
 *
 * @spec openspec/changes/an-app-provisions-a-nextcloud-account/specs/portal-identity-space/spec.md#requirement-an-app-may-provision-an-active-account-for-a-nextcloud-user
 */
class NextcloudAccountProvisionerTest extends TestCase {
	/**
	 * The portal the account is for.
	 */
	private const PORTAL = [
		'slug'           => 'warmtepompacademie',
		'organisation'   => 'academie',
		'authentication' => ['modes' => ['nextcloud', 'local']],
	];

	/**
	 * The accounts in the register.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The writer double.
	 *
	 * @var PortalObjectWriter&MockObject
	 */
	private PortalObjectWriter $writer;

	/**
	 * Dispatch the event through the real listener and provisioner.
	 *
	 * @param array<string, mixed> $overrides Event arguments to change.
	 * @param bool                 $userExists Whether the Nextcloud user exists.
	 * @param array<string, mixed> $portal     The published portal.
	 *
	 * @return PortalAccountProvisionRequestedEvent The answered event.
	 */
	private function dispatch(array $overrides = [], bool $userExists = true, array $portal = self::PORTAL): PortalAccountProvisionRequestedEvent {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, ...$rest): array {
				$filter = ($rest[6] ?? []);
				return array_values(
					array_filter(
						$this->rows,
						static fn (array $row): bool => ($row[$scopeField] ?? null) === $subjectRef
							&& ($organisation === '' || ($row['organisation'] ?? '') === $organisation)
							&& (isset($filter['status']) === false || ($row['status'] ?? '') === $filter['status'])
					)
				);
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn($userExists);

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([$portal]);

		$provisioner = new NextcloudAccountProvisioner($reader, $this->writer, $users, $portals);
		$listener = new PortalAccountProvisionListener(
			$this->createMock(PortalAccountService::class),
			$this->createMock(LoggerInterface::class),
			$provisioner
		);

		$arguments = array_merge(
			[
				'appId'        => 'learniq',
				'audience'     => 'participant',
				'organisation' => 'academie',
				'email'        => 'linda@example.org',
				'displayName'  => 'Linda Jansen',
				'nextcloudUid' => 'training-deelnemer-151',
				'portal'       => 'warmtepompacademie',
			],
			$overrides
		);
		$event = new PortalAccountProvisionRequestedEvent(...$arguments);
		$listener->handle(event: $event);
		return $event;
	}//end dispatch()

	/**
	 * Set up the writer double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [];
		$this->writer = $this->createMock(PortalObjectWriter::class);
	}//end setUp()

	/**
	 * A new account is active, its subjectRef the user id, provisioned by the app.
	 *
	 * @return void
	 */
	public function testANewAccountIsActiveUnderTheUserId(): void {
		$this->writer->expects($this->once())
			->method('createObject')
			->with(
				$this->identicalTo('portaliq'),
				$this->identicalTo('portalAccount'),
				$this->identicalTo(''),
				$this->identicalTo(''),
				$this->identicalTo('academie'),
				$this->callback(
					static fn (array $data): bool => $data['subjectRef'] === 'training-deelnemer-151'
						&& $data['status'] === 'active'
						&& $data['audience'] === 'participant'
						&& $data['organisation'] === 'academie'
						&& $data['provisionedBy'] === 'learniq'
						&& $data['verifiedEmail'] === false
						&& isset($data['identityType']) === false
				)
			)
			->willReturn(['uuid' => 'a1']);

		$event = $this->dispatch();

		$this->assertSame('', $event->getRefusal());
		$this->assertSame('training-deelnemer-151', $event->getSubjectRef());
		$this->assertSame('active', $event->getStatus());
	}//end testANewAccountIsActiveUnderTheUserId()

	/**
	 * A second call answers the same account and writes nothing.
	 *
	 * @return void
	 */
	public function testASecondCallWritesNothing(): void {
		$this->rows[] = ['subjectRef' => 'training-deelnemer-151', 'audience' => 'participant', 'organisation' => 'academie', 'status' => 'active'];
		$this->writer->expects($this->never())->method('createObject');

		$event = $this->dispatch();

		$this->assertSame('training-deelnemer-151', $event->getSubjectRef());
		$this->assertSame('active', $event->getStatus());
	}//end testASecondCallWritesNothing()

	/**
	 * An account under the same id in another tenant or audience is never taken over.
	 *
	 * @return void
	 */
	public function testAnotherTenantsOrAudiencesAccountIsRefused(): void {
		$this->writer->expects($this->never())->method('createObject');

		$this->rows = [['subjectRef' => 'training-deelnemer-151', 'audience' => 'participant', 'organisation' => 'gemeente-x', 'status' => 'active']];
		$this->assertSame('conflict', $this->dispatch()->getRefusal());

		$this->rows = [['subjectRef' => 'training-deelnemer-151', 'audience' => 'employer', 'organisation' => 'academie', 'status' => 'active']];
		$this->assertSame('conflict', $this->dispatch()->getRefusal());
	}//end testAnotherTenantsOrAudiencesAccountIsRefused()

	/**
	 * An account a clerk closed stays closed.
	 *
	 * @return void
	 */
	public function testAClosedAccountIsNotReopened(): void {
		$this->rows[] = ['subjectRef' => 'training-deelnemer-151', 'audience' => 'participant', 'organisation' => 'academie', 'status' => 'void'];
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame('not_active', $this->dispatch()->getRefusal());
	}//end testAClosedAccountIsNotReopened()

	/**
	 * A waiting account for the same address under another subjectRef is not
	 * taken over, and no second account is written beside it.
	 *
	 * @return void
	 */
	public function testAWaitingAccountForTheAddressIsNotTakenOver(): void {
		$this->rows[] = ['subjectRef' => 'minted-1', 'email' => 'linda@example.org', 'audience' => 'participant', 'organisation' => 'academie', 'status' => 'pending'];
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame('conflict', $this->dispatch()->getRefusal());
	}//end testAWaitingAccountForTheAddressIsNotTakenOver()

	/**
	 * The user must exist; the portal must be published, the tenant's, and offer the mode.
	 *
	 * @return void
	 */
	public function testTheUserAndThePortalAreChecked(): void {
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame('unknown_user', $this->dispatch(userExists: false)->getRefusal());
		$this->assertSame('unknown_portal', $this->dispatch(overrides: ['portal' => 'elders'])->getRefusal());
		$this->assertSame('portal_mismatch', $this->dispatch(overrides: ['organisation' => 'gemeente-x'])->getRefusal());
		$this->assertSame(
			'mode_not_offered',
			$this->dispatch(portal: array_merge(self::PORTAL, ['authentication' => ['modes' => ['digid']]]))->getRefusal()
		);
		$this->assertSame('refused', $this->dispatch(overrides: ['portal' => ''])->getRefusal());
		$this->assertSame('unknown_app', $this->dispatch(overrides: ['appId' => ''])->getRefusal());
	}//end testTheUserAndThePortalAreChecked()

	/**
	 * Without a Nextcloud user id the event provisions a pending account as before.
	 *
	 * @return void
	 */
	public function testWithoutAUserIdThePendingPathRuns(): void {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->expects($this->once())->method('provision')->willReturn(['subjectRef' => 'minted-2', 'isNew' => true, 'status' => 'pending']);
		$provisioner = $this->createMock(NextcloudAccountProvisioner::class);
		$provisioner->expects($this->never())->method('provision');

		$event = new PortalAccountProvisionRequestedEvent(appId: 'learniq', audience: 'participant', organisation: 'academie', email: 'linda@example.org');
		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class), $provisioner))->handle(event: $event);

		$this->assertSame('pending', $event->getStatus());
	}//end testWithoutAUserIdThePendingPathRuns()
}//end class
