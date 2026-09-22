<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tasks;

use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * partner-tasks-in-the-portal: the guard in front of an ask. It fails closed on
 * every uncertainty, and the object read it makes runs with RBAC on, so the
 * answer is about this user's rights rather than portaliq's.
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */
class PortalCaseAccessGuardTest extends TestCase {

	/**
	 * The flags the object read was made with.
	 *
	 * @var array<string, mixed>
	 */
	private array $readFlags = [];

	protected function setUp(): void {
		$this->readFlags = [];

	}//end setUp()

	public function testAUserWithoutTheActionIsRefusedWithoutAnyRead(): void {
		$guard = $this->guard(allowed: false, rows: [['id' => 'zaak-1']]);

		$this->assertFalse($guard->mayAsk(user: $this->user(), register: 'dossiq', schema: 'zaak', id: 'zaak-1'));
		$this->assertSame([], $this->readFlags);

	}//end testAUserWithoutTheActionIsRefusedWithoutAnyRead()

	public function testAUserWithTheActionAndTheCaseMayAsk(): void {
		$guard = $this->guard(allowed: true, rows: [['id' => 'zaak-1']]);

		$this->assertTrue($guard->mayAsk(user: $this->user(), register: 'dossiq', schema: 'zaak', id: 'zaak-1'));

	}//end testAUserWithTheActionAndTheCaseMayAsk()

	public function testTheReadRunsWithRbacAndMultitenancyOn(): void {
		$guard = $this->guard(allowed: true, rows: [['id' => 'zaak-1']]);

		$guard->mayAsk(user: $this->user(), register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		// The one read portaliq makes as the user rather than as itself.
		$this->assertTrue($this->readFlags['_rbac']);
		$this->assertTrue($this->readFlags['_multitenancy']);

	}//end testTheReadRunsWithRbacAndMultitenancyOn()

	public function testACaseTheUserCannotSeeIsARefusal(): void {
		$guard = $this->guard(allowed: true, rows: []);

		$this->assertFalse($guard->mayAsk(user: $this->user(), register: 'dossiq', schema: 'zaak', id: 'zaak-1'));

	}//end testACaseTheUserCannotSeeIsARefusal()

	public function testAReadThatThrowsIsARefusal(): void {
		$guard = $this->guard(allowed: true, rows: null);

		$this->assertFalse($guard->mayAsk(user: $this->user(), register: 'dossiq', schema: 'zaak', id: 'zaak-1'));

	}//end testAReadThatThrowsIsARefusal()

	public function testWithoutOpenRegisterNobodyMayAsk(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));

		$guard = new PortalCaseAccessGuard($this->actionAuth(allowed: true), $container);

		$this->assertFalse($guard->mayAsk(user: $this->user(), register: 'dossiq', schema: 'zaak', id: 'zaak-1'));

	}//end testWithoutOpenRegisterNobodyMayAsk()

	public function testAnEmptyCaseTupleIsARefusal(): void {
		$guard = $this->guard(allowed: true, rows: [['id' => 'zaak-1']]);
		$user = $this->user();

		$this->assertFalse($guard->mayAsk(user: $user, register: '', schema: 'zaak', id: 'zaak-1'));
		$this->assertFalse($guard->mayAsk(user: $user, register: 'dossiq', schema: '', id: 'zaak-1'));
		$this->assertFalse($guard->mayAsk(user: $user, register: 'dossiq', schema: 'zaak', id: ''));

	}//end testAnEmptyCaseTupleIsARefusal()

	/**
	 * The guard over an object service answering the given rows.
	 *
	 * @param bool $allowed Whether the action matrix lets the user through.
	 * @param array<int, array<string, mixed>>|null $rows The rows the read answers,
	 *                                                    or null to make it throw.
	 *
	 * @return PortalCaseAccessGuard
	 */
	private function guard(bool $allowed, ?array $rows): PortalCaseAccessGuard {
		$objectService = new class($this->readFlags, $rows) {
			/**
			 * @param array<string, mixed> $flags The recorded flags, by reference.
			 * @param array<int, array<string, mixed>>|null $rows The rows to answer.
			 */
			public function __construct(private array &$flags, private readonly ?array $rows) {
			}

			/**
			 * @param array<string, mixed> $config The read configuration.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = false, bool $_multitenancy = false): array {
				$this->flags = ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy, 'config' => $config];
				if ($this->rows === null) {
					throw new RuntimeException('refused');
				}

				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new PortalCaseAccessGuard($this->actionAuth(allowed: $allowed), $container);
	}//end guard()

	/**
	 * An action service that allows or refuses.
	 *
	 * @param bool $allowed Whether it allows.
	 *
	 * @return ActionAuthService
	 */
	private function actionAuth(bool $allowed): ActionAuthService {
		$actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		if ($allowed === false) {
			$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));
		}

		return $actionAuth;
	}//end actionAuth()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler-anna');

		return $user;
	}//end user()

}//end class
