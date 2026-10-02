<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\RowWhenNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A row action's `rowWhen` condition is checked before it reaches the site
 * (update-row-action-condition REQ-URC-002). The manifests below follow
 * learniq's cancel action on the guardian's conference times.
 *
 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
 */
class RowWhenNormaliserTest extends TestCase {
	/**
	 * Learniq's cancel action, an update row action.
	 *
	 * @param array<string, mixed> $overrides Keys to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function cancel(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'cancelConferenceTime',
				'type' => 'update',
				'schema' => 'conference-slot',
				'fields' => ['lifecycle'],
				'set' => ['lifecycle' => 'cancelled'],
				'rowWhen' => ['field' => 'lifecycle', 'in' => ['booked', 'acknowledged']],
			],
			$overrides
		);
	}//end cancel()

	/**
	 * A well-formed condition on an update action is kept as declared.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedConditionOnAnUpdateAction(): void {
		$result = (new RowWhenNormaliser())->normalise(actions: [$this->cancel()]);

		self::assertSame([$this->cancel()], $result['actions']);
		self::assertSame([], $result['dropped']);
	}//end testKeepsAWellFormedConditionOnAnUpdateAction()

	/**
	 * An unknown operator is dropped with a reason; the known part stays.
	 *
	 * @return void
	 */
	public function testDropsAnUnknownOperatorAndKeepsTheRest(): void {
		$result = (new RowWhenNormaliser())->normalise(
			actions: [$this->cancel(['rowWhen' => ['field' => 'lifecycle', 'in' => ['booked'], 'before' => 'bookingClosesAt']])]
		);

		self::assertSame(['field' => 'lifecycle', 'in' => ['booked']], $result['actions'][0]['rowWhen']);
		self::assertCount(1, $result['dropped']);
		self::assertStringContainsString('before', $result['dropped'][0]);
		self::assertStringContainsString('cancelConferenceTime', $result['dropped'][0]);
	}//end testDropsAnUnknownOperatorAndKeepsTheRest()

	/**
	 * A malformed condition on an update action is removed with a reason: no
	 * field name, an empty list, a nested value, or no object at all.
	 *
	 * @return void
	 */
	public function testDropsAMalformedConditionOnAnUpdateAction(): void {
		$result = (new RowWhenNormaliser())->normalise(
			actions: [
				$this->cancel(['rowWhen' => ['field' => 'life cycle', 'in' => ['booked']]]),
				$this->cancel(['rowWhen' => ['field' => 'lifecycle', 'in' => []]]),
				$this->cancel(['rowWhen' => ['field' => 'lifecycle', 'in' => [['booked']]]]),
				$this->cancel(['rowWhen' => 'booked']),
				$this->cancel(['rowWhen' => ['notIn' => ['cancelled']]]),
			]
		);

		foreach ($result['actions'] as $action) {
			self::assertArrayNotHasKey('rowWhen', $action);
			self::assertSame(['lifecycle' => 'cancelled'], $action['set']);
		}

		// The last one also loses its unknown operator: two reasons.
		self::assertCount(6, $result['dropped']);
	}//end testDropsAMalformedConditionOnAnUpdateAction()

	/**
	 * An endpoint row action keeps its own rule: a malformed `rowWhen` stays,
	 * so RowActionResolver keeps it from resolving as a row action.
	 *
	 * @return void
	 */
	public function testLeavesAMalformedConditionOnAnEndpointActionToTheResolver(): void {
		$pay = [
			'id' => 'pay',
			'type' => 'endpoint-forward',
			'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
			'rowField' => 'invoiceId',
			'rowWhen' => ['field' => 'state', 'in' => []],
		];

		$result = (new RowWhenNormaliser())->normalise(actions: [$pay]);

		self::assertSame([$pay], $result['actions']);
		self::assertSame([], $result['dropped']);
	}//end testLeavesAMalformedConditionOnAnEndpointActionToTheResolver()

	/**
	 * A contribution's dropped parts are logged with the app that declared them.
	 *
	 * @return void
	 */
	public function testLogsEveryDroppedPartWithTheApp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Portaliq: row condition dropped', self::callback(static fn (array $context): bool => $context['app'] === 'learniq'));

		$contribution = (new RowWhenNormaliser())->normaliseContribution(
			contribution: ['actions' => [$this->cancel(['rowWhen' => ['field' => 'lifecycle', 'in' => []]])]],
			appId: 'learniq',
			logger: $logger
		);

		self::assertArrayNotHasKey('rowWhen', $contribution['actions'][0]);
	}//end testLogsEveryDroppedPartWithTheApp()
}//end class
