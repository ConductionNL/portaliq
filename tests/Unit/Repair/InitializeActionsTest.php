<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\InitializeActions;
use OCA\Portaliq\Service\ActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ADR-023 repair step merges lib/actions.seed.json into the stored
 * action matrix on install and upgrade: missing seed actions are added with
 * their seed groups, a stored entry is never changed (#707).
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-an-upgrade-adds-new-actions-without-overwriting-grants-req-ora-004
 */
class InitializeActionsTest extends TestCase {
	/**
	 * The stored `actions` app-config value, as the IAppConfig double holds it.
	 *
	 * @var string
	 */
	private string $stored = '{}';

	/**
	 * How often the repair step wrote the matrix.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * A fresh install (no stored matrix) gets every seed action.
	 *
	 * @return void
	 */
	public function testAnEmptyMatrixIsSeeded(): void {
		$this->runRepair(stored: '{}');

		$this->assertSame($this->seedActions(), $this->storedMatrix());
		$this->assertSame(1, $this->writes);
	}//end testAnEmptyMatrixIsSeeded()

	/**
	 * An admin's grant on an existing action survives the upgrade.
	 *
	 * @return void
	 */
	public function testStoredGrantIsKept(): void {
		$this->runRepair(stored: '{"portal.provision":["admin","Service desk"]}');

		$this->assertSame(['admin', 'Service desk'], $this->storedMatrix()['portal.provision']);
	}//end testStoredGrantIsKept()

	/**
	 * A seed action the stored matrix lacks is added with its seed groups.
	 *
	 * @return void
	 */
	public function testNewSeedActionIsAdded(): void {
		$this->runRepair(stored: '{"portal.provision":["admin","Service desk"]}');

		$matrix = $this->storedMatrix();
		$this->assertSame(['admin'], $matrix['portal.send-emergency-push']);
		$this->assertSame(['admin'], $matrix['portal.create-poll']);
		$this->assertEqualsCanonicalizing(array_keys($this->seedActions()), array_keys($matrix));
	}//end testNewSeedActionIsAdded()

	/**
	 * A stored action that is no longer in the seed is left in place too.
	 *
	 * @return void
	 */
	public function testStoredActionOutsideTheSeedIsKept(): void {
		$this->runRepair(stored: '{"portal.retired":["Service desk"]}');

		$this->assertSame(['Service desk'], $this->storedMatrix()['portal.retired']);
	}//end testStoredActionOutsideTheSeedIsKept()

	/**
	 * A matrix that already holds every seed action is not rewritten.
	 *
	 * @return void
	 */
	public function testACompleteMatrixIsNotRewritten(): void {
		$complete = $this->seedActions();
		$complete['portal.author-news'] = ['admin', 'communicatie'];

		$this->runRepair(stored: json_encode($complete, JSON_THROW_ON_ERROR));

		$this->assertSame(0, $this->writes);
		$this->assertSame(['admin', 'communicatie'], $this->storedMatrix()['portal.author-news']);
	}//end testACompleteMatrixIsNotRewritten()

	/**
	 * Every action a controller gates on is in the seed, so it shows in the
	 * matrix instead of only falling back to admin-only.
	 *
	 * @return void
	 */
	public function testTheSeedListsTheStaffActions(): void {
		$seed = $this->seedActions();
		foreach ([
			\OCA\Portaliq\Controller\ActivityController::ACTION,
			\OCA\Portaliq\Controller\EmergencyPushController::ACTION,
			\OCA\Portaliq\Controller\EventController::ACTION,
			\OCA\Portaliq\Controller\NewsController::ACTION,
			\OCA\Portaliq\Controller\NewsletterController::ACTION,
			\OCA\Portaliq\Controller\PollController::ACTION,
		] as $action) {
			$this->assertSame(['admin'], ($seed[$action] ?? null), $action . ' missing from the seed');
		}
	}//end testTheSeedListsTheStaffActions()

	/**
	 * Run the repair step over an IAppConfig double holding `$stored`.
	 *
	 * @param string $stored The stored `actions` value.
	 *
	 * @return void
	 */
	private function runRepair(string $stored): void {
		$this->stored = $stored;
		$this->writes = 0;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn () => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				$this->writes++;
				return true;
			}
		);

		$repair = new InitializeActions(
			new ActionAuthService($appConfig, $this->createMock(IGroupManager::class)),
			$this->createMock(LoggerInterface::class)
		);
		$repair->run($this->createMock(IOutput::class));
	}//end runRepair()

	/**
	 * The stored matrix, decoded.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function storedMatrix(): array {
		return json_decode($this->stored, true, 512, JSON_THROW_ON_ERROR);
	}//end storedMatrix()

	/**
	 * The shipped seed's actions.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function seedActions(): array {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true, 512, JSON_THROW_ON_ERROR);
		return array_map(
			static fn (mixed $entry): mixed => (is_array($entry) === true && isset($entry['groups']) === true ? $entry['groups'] : $entry),
			$seed['actions']
		);
	}//end seedActions()
}//end class
