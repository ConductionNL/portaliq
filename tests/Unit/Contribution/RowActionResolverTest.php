<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\RowActionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Which actions a collection offers on its rows (contribution-pay-screen).
 *
 * The manifests below follow shillinq's parent manifest once it declares
 * `rowField`, and a portal-status-transitions update action.
 *
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-server-enforced-status-transitions
 */
class RowActionResolverTest extends TestCase {
	/**
	 * Shillinq's pay action, with the row keys of the follow-up.
	 *
	 * @param array<string, mixed> $overrides Keys to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function pay(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pay',
				'label' => 'Pay now',
				'type' => 'endpoint-forward',
				'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
				'method' => 'POST',
				'minTrust' => 'low',
				'rowField' => 'invoiceId',
				'rowWhen' => ['field' => 'state', 'in' => ['issued', 'partially-paid', 'overdue']],
			],
			$overrides
		);
	}//end pay()

	/**
	 * A transition action.
	 *
	 * @return array<string, mixed>
	 */
	private function close(): array {
		return ['id' => 'close', 'type' => 'update', 'schema' => 'request', 'fields' => ['status'], 'set' => ['status' => 'closed']];
	}//end close()

	/**
	 * The singular `rowAction` is folded into the list and removed; an object
	 * entry is reduced to its id; a duplicate collapses.
	 *
	 * @return void
	 */
	public function testSingularRowActionAndObjectEntriesResolve(): void {
		$out = (new RowActionResolver())->resolve(
			collections: [
				['id' => 'salesInvoices', 'rowActions' => [['id' => 'close'], 'pay'], 'rowAction' => 'pay'],
			],
			actions: [$this->close(), $this->pay()]
		);

		$this->assertSame(['close', 'pay'], $out[0]['rowActions']);
		$this->assertArrayNotHasKey('rowAction', $out[0]);
	}//end testSingularRowActionAndObjectEntriesResolve()

	/**
	 * Shillinq's manifest as it ships today: `pay` without `rowField` is not
	 * a row action, so no button. The action itself stays in the manifest.
	 *
	 * @return void
	 */
	public function testAnEndpointActionWithoutAWellFormedRowFieldIsNotOffered(): void {
		$resolver = new RowActionResolver();
		$withoutField = $this->pay();
		unset($withoutField['rowField']);

		foreach ([$withoutField, $this->pay(['rowField' => 'invoice id']), $this->pay(['rowField' => 7])] as $action) {
			$out = $resolver->resolve(collections: [['id' => 'salesInvoices', 'rowAction' => 'pay']], actions: [$action]);
			$this->assertArrayNotHasKey('rowActions', $out[0]);
			$this->assertArrayNotHasKey('rowAction', $out[0]);
		}

		$normalised = (new PortalManifestNormaliser())->normalise(
			['collections' => [['id' => 'salesInvoices', 'schema' => 'ARInvoice', 'rowAction' => 'pay']], 'actions' => [$withoutField]]
		);
		$this->assertSame('pay', $normalised['actions'][0]['id']);
		$this->assertArrayNotHasKey('rowActions', $normalised['collections'][0]);
	}//end testAnEndpointActionWithoutAWellFormedRowFieldIsNotOffered()

	/**
	 * An endpoint that is not instance-local, a create action, a malformed
	 * `rowWhen` and an unknown id never resolve.
	 *
	 * @return void
	 */
	public function testUnsafeOrMalformedEntriesAreDropped(): void {
		$out = (new RowActionResolver())->resolve(
			collections: [
				['id' => 'a', 'rowActions' => ['remote', 'relative', 'create', 'badWhen', 'emptyWhen', 'ghost', 42, ['label' => 'x']]],
			],
			actions: [
				$this->pay(['id' => 'remote', 'endpoint' => 'https://evil.example/pay']),
				$this->pay(['id' => 'relative', 'endpoint' => '//evil.example/pay']),
				$this->pay(['id' => 'create', 'type' => 'create']),
				$this->pay(['id' => 'badWhen', 'rowWhen' => ['field' => 'state', 'in' => [['nested']]]]),
				$this->pay(['id' => 'emptyWhen', 'rowWhen' => ['field' => 'state', 'in' => []]]),
			]
		);

		$this->assertArrayNotHasKey('rowActions', $out[0]);
	}//end testUnsafeOrMalformedEntriesAreDropped()

	/**
	 * `rowWhen` matches on the row's own value, strictly; a row without the
	 * field never matches; an action without `rowWhen` applies everywhere.
	 *
	 * @return void
	 */
	public function testRowWhenMatchesOnlyTheListedValues(): void {
		$resolver = new RowActionResolver();
		$pay = $this->pay();

		$this->assertTrue($resolver->rowMatches(action: $pay, row: ['state' => 'issued']));
		$this->assertTrue($resolver->rowMatches(action: $pay, row: ['state' => 'overdue']));
		$this->assertFalse($resolver->rowMatches(action: $pay, row: ['state' => 'paid']));
		$this->assertFalse($resolver->rowMatches(action: $pay, row: []));
		$this->assertFalse($resolver->rowMatches(action: $pay, row: ['state' => ['issued']]));

		$open = $this->pay();
		unset($open['rowWhen']);
		$this->assertTrue($resolver->rowMatches(action: $open, row: ['state' => 'paid']));
	}//end testRowWhenMatchesOnlyTheListedValues()

	/**
	 * `noticeField` survives only as a field name, through the full
	 * normaliser pipeline.
	 *
	 * @return void
	 */
	public function testNoticeFieldIsKeptOnlyWhenWellFormed(): void {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'a', 'schema' => 's', 'noticeField' => 'invoiceNote'],
					['id' => 'b', 'schema' => 's', 'noticeField' => 'invoice note'],
					['id' => 'c', 'schema' => 's', 'noticeField' => ['invoiceNote']],
					['id' => 'd', 'schema' => 's'],
				],
				'actions' => [],
			]
		);

		$this->assertSame('invoiceNote', $out['collections'][0]['noticeField']);
		$this->assertArrayNotHasKey('noticeField', $out['collections'][1]);
		$this->assertArrayNotHasKey('noticeField', $out['collections'][2]);
		$this->assertArrayNotHasKey('noticeField', $out['collections'][3]);
	}//end testNoticeFieldIsKeptOnlyWhenWellFormed()
}//end class
