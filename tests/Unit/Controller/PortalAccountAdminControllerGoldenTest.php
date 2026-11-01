<?php

/**
 * PortalAccountAdminControllerGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountAdminController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Identity\EmailLink\SignInAddressChange;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every staff route of the controller answers an anonymous caller, an
 * ordinary user and a clerk with the provision action exactly as recorded,
 * and hands the collaborators the same values, the clerk's uid included.
 *
 * @covers \OCA\Portaliq\Controller\PortalAccountAdminController
 */
class PortalAccountAdminControllerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/portal-account-admin-controller.json';

	/**
	 * The only principal the action check answers for.
	 */
	private const CLERK = 'clerk-anna';

	/**
	 * Each route, per principal, with the arguments it is called with.
	 */
	private const CALLS = [
		'provision' => ['audience' => 'client', 'organisation' => 'gemeente-x', 'identityType' => 'digid', 'identityRef' => 'bsn-1', 'email' => 'a@example.org', 'verifiedEmail' => true, 'displayName' => 'Anna'],
		'void' => ['subjectRef' => 'subject-1', 'reason' => 'mistake'],
		'void-no-reason' => ['subjectRef' => 'subject-1'],
		'invite' => ['email' => 'b@example.org', 'organisation' => 'gemeente-x', 'audience' => 'member'],
		'invitations' => ['organisation' => 'gemeente-x'],
		'revokeInvitation' => ['id' => 'inv-1', 'organisation' => 'gemeente-x'],
		'resendInvitation' => ['id' => 'inv-2', 'organisation' => 'gemeente-x'],
		'approve' => ['subjectRef' => 'subject-2'],
		'refuse' => ['subjectRef' => 'subject-3', 'reason' => 'unknown address'],
		'refuse-no-reason' => ['subjectRef' => 'subject-3'],
		'signInAddress' => ['subjectRef' => 'subject-4', 'address' => ' New@Example.ORG '],
	];

	/**
	 * The collaborator calls made so far, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Each route answers and delegates as recorded.
	 *
	 * @return void
	 */
	public function testEveryRouteAnswersAsRecorded(): void {
		$actual = [];
		foreach (self::CALLS as $key => $args) {
			$method = explode('-', $key)[0];
			foreach (['anonymous' => null, 'ordinary' => 'resident-bob', 'clerk' => self::CLERK] as $who => $uid) {
				$this->log = [];
				$response = $this->controller(uid: $uid)->{$method}(...$args);
				$actual[$key][$who] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->log];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryRouteAnswersAsRecorded()

	/**
	 * A controller whose session holds the given uid, or nobody.
	 *
	 * @param string|null $uid The signed-in uid, or null for anonymous.
	 *
	 * @return PortalAccountAdminController
	 */
	private function controller(?string $uid): PortalAccountAdminController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(function (IUser $user, string $action): void {
			$this->log[] = ['requireAction' => [$user->getUID(), $action]];
			if ($user->getUID() !== self::CLERK || $action !== 'portal.provision') {
				throw new OCSForbiddenException('no');
			}
		});

		$accounts = $this->getMockBuilder(PortalAccountService::class)->disableOriginalConstructor()
			->onlyMethods(['provision', 'voidPending', 'approvePending', 'findBySubjectRef'])->getMock();
		$this->answer($accounts, 'provision', ['subjectRef' => 'subject-new', 'status' => 'pending']);
		$this->answer($accounts, 'voidPending', true);
		$this->answer($accounts, 'approvePending', true);
		$this->answer($accounts, 'findBySubjectRef', ['subjectRef' => 'subject-4']);

		$invitations = $this->getMockBuilder(PortalInvitationService::class)->disableOriginalConstructor()
			->onlyMethods(['invite', 'sentBy', 'revoke', 'resend'])->getMock();
		$this->answer($invitations, 'invite', ['token' => 'secret-1', 'expiresAt' => '2026-11-08']);
		$this->answer($invitations, 'sentBy', [['id' => 'inv-1', 'state' => 'sent']]);
		$this->answer($invitations, 'revoke', '');
		$this->answer($invitations, 'resend', ['email' => 'c@example.org', 'token' => 'secret-2', 'expiresAt' => '2026-11-09']);

		$mailer = $this->getMockBuilder(PortalIdentityMailer::class)->disableOriginalConstructor()->onlyMethods(['send'])->getMock();
		$this->answer($mailer, 'send', true);

		$change = $this->getMockBuilder(SignInAddressChange::class)->disableOriginalConstructor()->onlyMethods(['change'])->getMock();
		$this->answer($change, 'change', SignInAddressChange::CHANGED);

		return new PortalAccountAdminController(
			$this->createMock(IRequest::class),
			$accounts,
			$auth,
			$session,
			$invitations,
			$mailer,
			$change
		);
	}//end controller()

	/**
	 * Make a mocked method log its arguments by name and answer a fixed value.
	 *
	 * @param \PHPUnit\Framework\MockObject\MockObject $mock   The double.
	 * @param string                                   $method The method.
	 * @param mixed                                    $answer What it answers.
	 *
	 * @return void
	 */
	private function answer(object $mock, string $method, mixed $answer): void {
		$names = array_map(
			static fn (\ReflectionParameter $p): string => $p->getName(),
			(new ReflectionMethod(get_parent_class($mock), $method))->getParameters()
		);
		$mock->method($method)->willReturnCallback(function (mixed ...$args) use ($names, $method, $answer): mixed {
			$named = [];
			foreach ($args as $i => $value) {
				$named[$names[$i] ?? (string)$i] = ($value instanceof IUser) ? 'user:' . $value->getUID() : $value;
			}

			$this->log[] = [$method => $named];
			return $answer;
		});
	}//end answer()
}//end class
