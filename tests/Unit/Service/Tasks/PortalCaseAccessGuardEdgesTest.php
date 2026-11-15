<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tasks;

use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * The guard's exact collaborator calls and the answers it fails closed on:
 * which action it asks for, which record it reads, and what it makes of a
 * service or a read that answers something unexpected.
 */
#[CoversClass(PortalCaseAccessGuard::class)]
class PortalCaseAccessGuardEdgesTest extends TestCase {
	/**
	 * Asking a partner needs exactly the ask action, for this user.
	 *
	 * @return void
	 */
	public function testAskingNeedsTheAskAction(): void {
		$user = $this->createMock(IUser::class);
		$actionAuth = $this->actionAuth();
		$actionAuth->expects($this->once())->method('requireAction')->with($user, 'portal.ask-partner');

		$guard = new PortalCaseAccessGuard($actionAuth, $this->container(service: $this->objectService(answer: [['id' => 'zaak-1']])));

		$this->assertTrue($guard->mayAsk(user: $user, register: 'dossiq', schema: 'zaak', id: 'zaak-1'));
	}//end testAskingNeedsTheAskAction()

	/**
	 * Any other act checks the action it is given, not the ask action.
	 *
	 * @return void
	 */
	public function testActingChecksTheGivenAction(): void {
		$user = $this->createMock(IUser::class);
		$actionAuth = $this->actionAuth();
		$actionAuth->expects($this->once())->method('requireAction')->with($user, PortalCaseAccessGuard::ACTION_REVIEW_PROPOSAL);

		$guard = new PortalCaseAccessGuard($actionAuth, $this->container(service: $this->objectService(answer: [['id' => 'zaak-1']])));

		$this->assertTrue(
			$guard->mayAct(user: $user, register: 'dossiq', schema: 'zaak', id: 'zaak-1', action: PortalCaseAccessGuard::ACTION_REVIEW_PROPOSAL)
		);
	}//end testActingChecksTheGivenAction()

	/**
	 * The read asks OpenRegister's object service for exactly the one record.
	 *
	 * @return void
	 */
	public function testTheReadAsksForExactlyTheOneRecord(): void {
		$service = $this->objectService(answer: [['id' => 'zaak-7']]);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($service);

		$guard = new PortalCaseAccessGuard($this->actionAuth(), $container);

		$this->assertTrue($guard->mayRead(register: 'reg-3', schema: 'melding', id: 'zaak-7'));
		$this->assertSame(
			['filters' => ['register' => 'reg-3', 'schema' => 'melding', 'id' => 'zaak-7'], 'limit' => 1, 'offset' => 0],
			$service->config
		);
	}//end testTheReadAsksForExactlyTheOneRecord()

	/**
	 * A container that answers something other than an object is treated as
	 * OpenRegister being absent: nothing is readable.
	 *
	 * @return void
	 */
	public function testAServiceThatIsNotAnObjectReadsNothing(): void {
		$guard = new PortalCaseAccessGuard($this->actionAuth(), $this->container(service: 'not a service'));

		$this->assertFalse($guard->mayRead(register: 'dossiq', schema: 'zaak', id: 'zaak-1'));
	}//end testAServiceThatIsNotAnObjectReadsNothing()

	/**
	 * A read answering anything but a list of rows is a refusal.
	 *
	 * @return void
	 */
	public function testAReadThatAnswersNoListIsARefusal(): void {
		foreach ([null, new stdClass(), 'zaak-1', 1] as $answer) {
			$guard = new PortalCaseAccessGuard($this->actionAuth(), $this->container(service: $this->objectService(answer: $answer)));

			$this->assertFalse($guard->mayRead(register: 'dossiq', schema: 'zaak', id: 'zaak-1'), get_debug_type($answer));
		}
	}//end testAReadThatAnswersNoListIsARefusal()

	/**
	 * An incomplete record tuple is refused before OpenRegister is even looked up.
	 *
	 * @return void
	 */
	public function testAnIncompleteTupleNeverLooksUpTheService(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$guard = new PortalCaseAccessGuard($this->actionAuth(), $container);

		$this->assertFalse($guard->mayRead(register: '', schema: 'zaak', id: 'zaak-1'));
		$this->assertFalse($guard->mayRead(register: 'dossiq', schema: '', id: 'zaak-1'));
	}//end testAnIncompleteTupleNeverLooksUpTheService()

	/**
	 * An action service double that allows by default.
	 *
	 * @return ActionAuthService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function actionAuth(): ActionAuthService {
		return $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
	}//end actionAuth()

	/**
	 * A container answering the given service.
	 *
	 * @param mixed $service What the container answers.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return $container;
	}//end container()

	/**
	 * An object service whose read answers the given value and keeps its config.
	 *
	 * @param mixed $answer What findAll answers.
	 *
	 * @return object
	 */
	private function objectService(mixed $answer): object {
		return new class($answer) {
			/**
			 * The config the last read was made with.
			 *
			 * @var array<string, mixed>
			 */
			public array $config = [];

			/**
			 * @param mixed $answer What findAll answers.
			 */
			public function __construct(private readonly mixed $answer) {
			}

			/**
			 * @param array<string, mixed> $config The read configuration.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return mixed
			 */
			public function findAll(array $config, bool $_rbac = false, bool $_multitenancy = false): mixed {
				$this->config = $config;

				return $this->answer;
			}
		};
	}//end objectService()
}//end class
