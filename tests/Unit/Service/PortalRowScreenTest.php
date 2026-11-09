<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalRowScreen;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pq-f2: the one per-row proof every per-object portal route shares. A row
 * the subject's scope reads is still refused when the list would not show
 * it: another branch, a row still waiting for its moment, or a case of a type
 * the serving portal hides.
 *
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t03
 */
#[CoversClass(PortalRowScreen::class)]
class PortalRowScreenTest extends TestCase {

	/**
	 * A branch-restricted eHerkenning session.
	 */
	private const BRANCH_SUBJECT = [
		'subjectRef' => 'kvk-1',
		'organisation' => 'org-1',
		'branch' => '000012345678',
		'branchRestricted' => true,
	];

	/**
	 * The rows the list would not show, each refused by row().
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function hiddenRows(): array {
		return [
			'another branch' => [
				['branchField' => 'vestiging'],
				['id' => 'c-1', 'vestiging' => '000087654321'],
			],
			'restricted login, collection without branchField' => [
				[],
				['id' => 'c-1'],
			],
			'still waiting for its moment' => [
				['branchField' => 'vestiging', 'visibleFromField' => 'publishFrom'],
				['id' => 'c-1', 'vestiging' => '000012345678', 'publishFrom' => '2999-01-01T00:00:00+00:00'],
			],
			'a case type the portal hides' => [
				['branchField' => 'vestiging', 'kind' => 'cases'],
				['id' => 'c-1', 'vestiging' => '000012345678', 'caseType' => 'hidden-type'],
			],
		];
	}//end hiddenRows()

	/**
	 * Each gate of the list refuses the row by id too.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param array<string, mixed> $row The row the scoped read returns.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenRows')]
	public function testARowTheListWouldNotShowIsRefusedById(array $collection, array $row): void {
		$screen = $this->screen(row: $row);

		$this->assertNull($screen->row(request: $this->request(), subject: self::BRANCH_SUBJECT, collection: $collection, app: 'zaakiq', target: ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'c-1']));
	}//end testARowTheListWouldNotShowIsRefusedById()

	/**
	 * The session's own branch, past its moment and of a shown type passes,
	 * and the declared collection filter reaches the scoped read.
	 *
	 * @return void
	 */
	public function testAVisibleRowPassesAndTheFilterReachesTheRead(): void {
		$received = [];
		$reader   = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			static function (...$args) use (&$received): array {
				$received = $args;
				return ['id' => 'c-1', 'vestiging' => '000012345678', 'publishFrom' => '2001-01-01T00:00:00+00:00', 'caseType' => 'shown-type'];
			}
		);
		$screen = new PortalRowScreen(reader: $reader, caseTypes: $this->caseTypes());

		$row = $screen->row(
			request: $this->request(),
			subject: self::BRANCH_SUBJECT,
			collection: ['branchField' => 'vestiging', 'visibleFromField' => 'publishFrom', 'kind' => 'cases', 'filter' => ['type' => 'x']],
			app: 'zaakiq',
			target: ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'c-1']
		);

		$this->assertSame('c-1', $row['id']);
		// `filter` is readObject()'s twelfth argument.
		$this->assertSame(['type' => 'x'], $received[11]);
	}//end testAVisibleRowPassesAndTheFilterReachesTheRead()

	/**
	 * A write through an action passes when any of the subject's collections
	 * over the schema shows the row, and is refused when none does.
	 *
	 * @return void
	 */
	public function testAnActionRowMustPassTheGatesOfOneOfTheSubjectsCollections(): void {
		$screen    = $this->screen(row: []);
		$aggregate = ['contributions' => [['app' => 'zaakiq', 'collections' => [
			['id' => 'mine', 'register' => 'zaken', 'schema' => 'zaak', 'branchField' => 'vestiging'],
		]]]];
		$match     = ['action' => ['type' => 'update'], 'app' => 'zaakiq'];
		$target    = ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'c-1'];

		$this->assertTrue($screen->admitsForAction(request: $this->request(), subject: self::BRANCH_SUBJECT, aggregate: $aggregate, match: $match, target: $target, row: ['vestiging' => '000012345678']));
		$this->assertFalse($screen->admitsForAction(request: $this->request(), subject: self::BRANCH_SUBJECT, aggregate: $aggregate, match: $match, target: $target, row: ['vestiging' => '000087654321']));

		// No collection over the schema: the action's own declaration stands in.
		$this->assertFalse($screen->admitsForAction(request: $this->request(), subject: self::BRANCH_SUBJECT, aggregate: ['contributions' => []], match: $match, target: $target, row: ['vestiging' => '000012345678']));
		$this->assertTrue($screen->admitsForAction(
			request: $this->request(),
			subject: self::BRANCH_SUBJECT,
			aggregate: ['contributions' => []],
			match: ['action' => ['type' => 'update', 'branchField' => 'vestiging'], 'app' => 'zaakiq'],
			target: $target,
			row: ['vestiging' => '000012345678']
		));
	}//end testAnActionRowMustPassTheGatesOfOneOfTheSubjectsCollections()

	/**
	 * A screen whose scoped read returns the row given.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return PortalRowScreen
	 */
	private function screen(array $row): PortalRowScreen {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn($row);

		return new PortalRowScreen(reader: $reader, caseTypes: $this->caseTypes());
	}//end screen()

	/**
	 * Case-type visibility hiding `hidden-type` on a `cases` collection.
	 *
	 * @return CaseTypeVisibility
	 */
	private function caseTypes(): CaseTypeVisibility {
		$caseTypes = $this->createMock(CaseTypeVisibility::class);
		$caseTypes->method('hiddenForCollection')->willReturnCallback(
			static fn (IRequest $request, array $subject, array $collection): array => (($collection['kind'] ?? '') === 'cases' ? ['hidden-type'] : [])
		);
		$caseTypes->method('rowIsHidden')->willReturnCallback(
			static fn (array $row, array $collection, array $hidden): bool => in_array((string)($row['caseType'] ?? ''), $hidden, true)
		);

		return $caseTypes;
	}//end caseTypes()

	/**
	 * A request with no `?collection=`.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);

		return $request;
	}//end request()
}//end class
