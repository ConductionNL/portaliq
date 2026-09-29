<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountSelfController;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use OCA\Portaliq\Service\Notifications\MessageBoxChannel;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases, the bearer's own account:
 * every surface refuses a caller with no session, and the confirmation secret
 * for a new address is never readable from the old one.
 *
 * Moved here with PortalAccountSelfController when it was split out of
 * PortalIdentityController.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalAccountSelfControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testEveryAccountSurfaceRefusesACallerWithNoSession(): void {
		$controller = $this->controller(subject: null);
		$this->doubles['selfService']->expects($this->never())->method('updateDetails');
		$this->doubles['selfService']->expects($this->never())->method('removeAccount');
		$this->doubles['accessRequests']->expects($this->never())->method('request');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->updateDetails(displayName: 'Iemand anders')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->removeAccount()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->requestAccess(reason: 'omdat')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->myAccessRequests()->getStatus());

	}//end testEveryAccountSurfaceRefusesACallerWithNoSession()

	public function testTheConfirmationSecretIsNotReadableFromTheOldSession(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertTrue($data['confirmationPending']);
		$this->assertArrayNotHasKey('confirmationToken', $data);

	}//end testTheConfirmationSecretIsNotReadableFromTheOldSession()

	/**
	 * portaliq#795. The confirmation secret used to be minted and dropped, so
	 * a new address could never be confirmed. It now goes to the NEW address,
	 * inside the mailed link, and the answer says only that it went.
	 *
	 * @return void
	 */
	public function testTheConfirmationSecretIsMailedToTheNewAddress(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);
		$this->doubles['mailer']->expects($this->once())
			->method('send')
			->with(
				$this->equalTo(PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION),
				$this->equalTo('nieuw@example.org'),
				$this->equalTo('secret-1'),
				$this->equalTo('gemeente-x')
			)
			->willReturn(true);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertSame(['updated' => true, 'confirmationPending' => true, 'confirmationSent' => true], $data);
		$this->assertStringNotContainsString('secret-1', (string)json_encode($data));

	}//end testTheConfirmationSecretIsMailedToTheNewAddress()

	/**
	 * portaliq#795. A confirmation mail that did not leave is said so, so the
	 * page can ask to try again; the secret never falls back into the answer.
	 *
	 * @return void
	 */
	public function testAFailedConfirmationMailIsReportedWithoutTheSecret(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);
		$this->doubles['mailer']->method('send')->willReturn(false);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertSame(['updated' => true, 'confirmationPending' => true, 'confirmationSent' => false], $data);

	}//end testAFailedConfirmationMailIsReportedWithoutTheSecret()

	/**
	 * A change that parks no new address mails nothing.
	 *
	 * @return void
	 */
	public function testANameChangeSendsNoMail(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => '']);
		$this->doubles['mailer']->expects($this->never())->method('send');

		$data = $controller->updateDetails(displayName: 'Anna')->getData();

		$this->assertFalse($data['confirmationPending']);

	}//end testANameChangeSendsNoMail()

	/**
	 * notification-preferences-per-role: the channel opt-out is forwarded
	 * to the service exactly as given, alongside the other optional fields.
	 *
	 * @return void
	 */
	public function testTheChannelPreferenceIsForwardedToTheService(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->expects($this->once())
			->method('updateDetails')
			->with(
				$this->equalTo(value: 'subject-1'),
				$this->equalTo(value: ''),
				$this->equalTo(value: ''),
				$this->equalTo(value: false)
			)
			->willReturn(['updated' => true, 'confirmationToken' => '']);

		$response = $controller->updateDetails(emailNotifications: false);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

	}//end testTheChannelPreferenceIsForwardedToTheService()

	/**
	 * The message language reaches the service as given (translated-message-notice).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function testTheMessageLanguageIsForwardedToTheService(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'school-x']);
		$this->doubles['selfService']->expects($this->once())
			->method('updateDetails')
			->with('subject-1', '', '', null, 'tr')
			->willReturn(['updated' => true, 'confirmationToken' => '']);

		$this->assertSame(Http::STATUS_OK, $controller->updateDetails(messageLanguage: 'tr')->getStatus());

	}//end testTheMessageLanguageIsForwardedToTheService()

	/**
	 * The details come from the bearer's own subject only; no subject is 401,
	 * no account is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function testDetailsAreTheBearersOwn(): void {
		$details = ['displayName' => 'Ans', 'email' => 'a@example.org', 'emailNotifications' => true, 'messageLanguage' => 'ar'];

		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'school-x']);
		$this->doubles['selfService']->expects($this->once())->method('details')->with('subject-1')->willReturn($details);
		$response = $controller->details();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($details, $response->getData());

		$missing = $this->controller(subject: ['subjectRef' => 'subject-2', 'organisation' => 'school-x']);
		$this->doubles['selfService']->method('details')->willReturn(null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->details()->getStatus());

		$anonymous = $this->controller(subject: null);
		$this->doubles['selfService']->expects($this->never())->method('details');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->details()->getStatus());

	}//end testDetailsAreTheBearersOwn()

	/**
	 * The controller over doubles, all of which can only answer methods the
	 * real classes have.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject.
	 *
	 * @return PortalAccountSelfController
	 */
	private function controller(?array $subject): PortalAccountSelfController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn($subject);

		$this->doubles = [
			'selfService' => $this->double(PortalSelfServiceService::class, ['updateDetails', 'confirmEmail', 'removeAccount', 'details']),
			'accessRequests' => $this->double(PortalAccessRequestService::class, ['request', 'madeBy']),
			'mailer' => $this->double(PortalIdentityMailer::class, ['send']),
		];

		return new PortalAccountSelfController(
			$request,
			$session,
			$this->doubles['selfService'],
			$this->doubles['accessRequests'],
			$this->doubles['mailer']
		);
	}//end controller()

	/**
	 * A double of one class, limited to the methods it really has.
	 *
	 * @param string $class The class to double.
	 * @param array<int, string> $methods The methods to stub.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		return $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods($methods)
			->getMock();
	}//end double()


	/**
	 * A controller over the REAL self-service service, whose account store is
	 * the given account and whose push store holds the given subscriptions.
	 *
	 * @param array<string, mixed>             $account       The caller's account.
	 * @param array<int, array<string, mixed>> $written       Captured updates.
	 * @param array<int, array<string, mixed>> $subscriptions Push subscriptions.
	 *
	 * @return PortalAccountSelfController
	 */
	private function preferencesController(array $account, array &$written, array $subscriptions = [], ?array $messageBox = null): PortalAccountSelfController {
		$request = $this->createMock(IRequest::class);
		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn(['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);

		$accounts = $this->double(PortalAccountService::class, ['findBySubjectRef']);
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static fn (string $subjectRef): ?array => ($subjectRef === 'subject-1' ? $account : ['uuid' => 'account-other', 'subjectRef' => $subjectRef])
		);
		$reader = $this->double(PortalObjectReader::class, ['readCollection']);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef): array => ($schema === 'pushSubscription' && $subjectRef === 'subject-1' ? $subscriptions : [])
		);
		$writer = $this->double(PortalObjectWriter::class, ['updateObject']);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$written): array {
				$written[] = ['schema' => $schema, 'id' => $id, 'data' => $data];
				return $data;
			}
		);

		$selfService = new PortalSelfServiceService(
			accounts: $accounts,
			reader: $reader,
			writer: $writer,
			random: $this->createMock(ISecureRandom::class)
		);

		return new PortalAccountSelfController(
			$request,
			$session,
			$selfService,
			$this->double(PortalAccessRequestService::class, ['request', 'madeBy']),
			$this->double(PortalIdentityMailer::class, ['send']),
			$this->messageBoxOffer(offer: $messageBox)
		);
	}//end preferencesController()

	/**
	 * An organisation configuration that offers the message box to
	 * `gemeente-x` with the given offer, or not at all.
	 *
	 * @param array<string, string>|null $offer The offer.
	 *
	 * @return PortalOrganisationConfigService
	 */
	private function messageBoxOffer(?array $offer): PortalOrganisationConfigService {
		$orgConfig = $this->double(PortalOrganisationConfigService::class, ['messageBox']);
		$orgConfig->method('messageBox')->willReturnCallback(
			static fn (string $orgSlug): ?array => ($orgSlug === 'gemeente-x' ? $offer : null)
		);

		return $orgConfig;
	}//end messageBoxOffer()

	/**
	 * The preferences read and written are the caller's own, whatever the
	 * body names (REQ-NAP-007).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function testPreferencesAreTheCallersOwn(): void {
		$written = [];
		$controller = $this->preferencesController(
			account: ['uuid' => 'account-1', 'subjectRef' => 'subject-1', 'notificationPreferences' => ['message.created' => ['push' => false]]],
			written: $written
		);

		$read = $controller->notificationPreferences()->getData();
		$this->assertSame(['email' => true, 'push' => true], $read['preferences']['case.updated'], 'a missing choice means on');
		$this->assertSame(['email' => true, 'push' => false], $read['preferences']['message.created']);

		$saved = $controller->updateNotificationPreferences(preferences: ['case.updated' => ['email' => false], 'subjectRef' => 'subject-2', 'accountRef' => 'account-other'])->getData();

		$this->assertCount(1, $written);
		$this->assertSame('account-1', $written[0]['id'], 'only the caller\'s account is written');
		$this->assertSame('portalAccount', $written[0]['schema']);
		$this->assertSame(
			['case.updated' => ['email' => false, 'push' => true], 'message.created' => ['email' => true, 'push' => false]],
			$written[0]['data']['notificationPreferences']
		);
		$this->assertSame(false, $saved['preferences']['case.updated']['email']);
	}//end testPreferencesAreTheCallersOwn()

	/**
	 * Unknown kinds, unknown channels and non-boolean values are ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function testUnknownKindIsIgnored(): void {
		$written = [];
		$controller = $this->preferencesController(account: ['uuid' => 'account-1', 'subjectRef' => 'subject-1'], written: $written);

		$controller->updateNotificationPreferences(
			preferences: ['task.due' => ['email' => false], 'message.created' => ['sms' => true, 'email' => 'no', 'push' => false]]
		);

		$this->assertSame(
			['case.updated' => ['email' => true, 'push' => true], 'message.created' => ['email' => true, 'push' => false]],
			$written[0]['data']['notificationPreferences']
		);
		$this->assertSame(['notificationPreferences'], array_keys($written[0]['data']), 'nothing but the preferences is written');
	}//end testUnknownKindIsIgnored()

	/**
	 * The push column shows only when the account registered a device.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-choices-live-on-the-inbox-page-req-nap-008
	 */
	public function testPushAvailableFollowsTheSubscription(): void {
		$written = [];
		$without = $this->preferencesController(account: ['uuid' => 'account-1', 'subjectRef' => 'subject-1'], written: $written);
		$this->assertFalse($without->notificationPreferences()->getData()['pushAvailable']);

		$with = $this->preferencesController(account: ['uuid' => 'account-1', 'subjectRef' => 'subject-1'], written: $written, subscriptions: [['endpoint' => 'https://push.example/1']]);
		$this->assertTrue($with->notificationPreferences()->getData()['pushAvailable']);

		$anonymous = $this->controller(subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->notificationPreferences()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->updateNotificationPreferences(preferences: [])->getStatus());
	}//end testPushAvailableFollowsTheSubscription()

	/**
	 * The message box choice exists only when the organisation offers the
	 * channel; switching it off is stored as `messageBox.enabled` false on
	 * the caller's own account, and the channel then queues nothing for them
	 * (inbox-berichtenbox-channel, REQ-MBC-001, REQ-MBC-005).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-can-switch-the-channel-off-req-mbc-005
	 */
	public function testTheMessageBoxChoiceOnlyWhenOffered(): void {
		$written = [];
		$notOffered = $this->preferencesController(account: ['uuid' => 'account-1', 'subjectRef' => 'subject-1'], written: $written);
		$this->assertNull($notOffered->notificationPreferences()->getData()['messageBox'], 'no channel, no choice');

		$offer = ['sourceId' => 'berichtenbox-x', 'label' => 'MijnOverheid Berichtenbox'];
		$offered = $this->preferencesController(account: ['uuid' => 'account-1', 'subjectRef' => 'subject-1'], written: $written, messageBox: $offer);
		$read = $offered->notificationPreferences()->getData();
		$this->assertSame(['label' => 'MijnOverheid Berichtenbox'], $read['messageBox'], 'the label only, never the source');

		$saved = $offered->updateNotificationPreferences(preferences: ['messageBox' => ['enabled' => false]])->getData();
		$this->assertSame(['enabled' => false], $written[0]['data']['notificationPreferences']['messageBox']);
		$this->assertSame(['enabled' => false], $saved['preferences']['messageBox']);
		$this->assertSame(['label' => 'MijnOverheid Berichtenbox'], $saved['messageBox']);

		$stored = ['uuid' => 'account-1', 'subjectRef' => 'subject-1', 'organisation' => 'gemeente-x', 'notificationPreferences' => $written[0]['data']['notificationPreferences']];
		$channel = new MessageBoxChannel(orgConfig: $this->messageBoxOffer(offer: $offer), jobList: $this->createMock(IJobList::class), logger: $this->createMock(LoggerInterface::class));
		$this->assertFalse($channel->wants(account: $stored), 'switched off: the channel queues nothing');

		$written = [];
		$again = $this->preferencesController(account: $stored, written: $written, messageBox: $offer);
		$this->assertSame(['enabled' => false], $again->notificationPreferences()->getData()['preferences']['messageBox']);
		$again->updateNotificationPreferences(preferences: ['messageBox' => ['enabled' => 'yes']]);
		$this->assertSame(['enabled' => false], $written[0]['data']['notificationPreferences']['messageBox'], 'a non-boolean changes nothing');
	}//end testTheMessageBoxChoiceOnlyWhenOffered()
}//end class
