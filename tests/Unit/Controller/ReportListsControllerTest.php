<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ReportController;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Reports\ReportIntakeService;
use OCA\Portaliq\Service\Reports\ReportProjection;
use OCA\Portaliq\Service\Reports\ReportTermsService;
use OCA\Portaliq\Service\Reports\ReportThreadService;
use OCA\Portaliq\Service\Reports\RevealService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The staff lists of reports and reveal requests answer only what the
 * caller's groups handle, through the projection (intake-report-pages T03, T04).
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-handlers-see-reports-through-the-projection-only-req-irp-004
 */
class ReportListsControllerTest extends TestCase {
	/**
	 * Two reports of a handled case type, one of a case type nobody here
	 * handles, and one reveal request on each.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Set up the stored rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$report = static fn (string $id, string $caseType, string $subject, string $received): array => [
			'id' => $id,
			'portal' => 'zuiddrecht',
			'caseType' => $caseType,
			'subject' => $subject,
			'state' => 'open',
			'receivedAt' => $received,
		];
		$first = $report('r-1', 'misstand', 'Dienstauto', '2026-10-01T09:00:00+00:00') + ['contactRef' => 'c-1', 'codeHash' => 'h-1'];
		$this->rows = [
			'portalReport' => [
				$first,
				$report('r-2', 'misstand', 'Aanbesteding', '2026-10-05T09:00:00+00:00'),
				$report('r-3', 'integriteit', 'Elders', '2026-10-06T09:00:00+00:00'),
			],
			'portalRevealRequest' => [
				[
					'id' => 'q-1',
					'reportRef' => 'r-1',
					'requestedBy' => 'behandelaar-b',
					'motivation' => 'Enige getuige',
					'state' => 'pending',
					'requestedAt' => '2026-10-02T09:00:00+00:00',
				],
				['id' => 'q-3', 'reportRef' => 'r-3', 'requestedBy' => 'iemand', 'motivation' => 'Elders', 'state' => 'pending'],
			],
		];
	}//end setUp()

	/**
	 * A handler lists the reports of the case types they handle, newest
	 * first, and no list item carries the reporter's contact or the code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-handlers-see-reports-through-the-projection-only-req-irp-004
	 */
	public function testIndexListsOnlyHandledCaseTypesAndNeverTheContact(): void {
		$data = $this->controller(groups: ['meldpunt'])->index()->getData();

		$this->assertSame(expected: ['r-2', 'r-1'], actual: array_column(array: $data['results'], column_key: 'id'));
		$this->assertSame(expected: 2, actual: $data['total']);
		$encoded = (string)json_encode(value: $data);
		$this->assertStringNotContainsString(needle: 'contactRef', haystack: $encoded);
		$this->assertStringNotContainsString(needle: 'codeHash', haystack: $encoded);
	}//end testIndexListsOnlyHandledCaseTypesAndNeverTheContact()

	/**
	 * A colleague outside every group lists nothing, and an anonymous call
	 * is refused before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-only-declared-groups-may-handle-reports-req-irp-005
	 */
	public function testOutsideTheGroupsNothingIsListed(): void {
		$this->assertSame(expected: [], actual: $this->controller(groups: [])->index()->getData()['results']);
		$this->assertSame(expected: [], actual: $this->controller(groups: [])->revealRequests()->getData()['results']);
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(groups: [], signedIn: false)->index()->getStatus());
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(groups: [], signedIn: false)->revealRequests()->getStatus());
	}//end testOutsideTheGroupsNothingIsListed()

	/**
	 * The custodian lists the pending requests on the reports they guard,
	 * with the motivation and the report's subject; a handler sees none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
	 */
	public function testTheCustodianListsThePendingRequestsTheyGuard(): void {
		$data = $this->controller(groups: ['vertrouwenspersonen'])->revealRequests()->getData();

		$this->assertSame(expected: 1, actual: $data['total']);
		$this->assertSame(expected: 'q-1', actual: $data['results'][0]['id']);
		$this->assertSame(expected: 'Enige getuige', actual: $data['results'][0]['motivation']);
		$this->assertSame(expected: 'Dienstauto', actual: $data['results'][0]['subject']);
		$this->assertSame(expected: [], actual: $this->controller(groups: ['meldpunt'])->revealRequests()->getData()['results']);
	}//end testTheCustodianListsThePendingRequestsTheyGuard()

	/**
	 * The report's own page carries its reveal requests, never a contact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-handlers-see-reports-through-the-projection-only-req-irp-004
	 */
	public function testTheReportPageCarriesItsRevealRequests(): void {
		$data = $this->controller(groups: ['meldpunt'])->show(id: 'r-1')->getData();

		$this->assertSame(expected: ['q-1'], actual: array_column(array: $data['revealRequests'], column_key: 'id'));
		$this->assertArrayNotHasKey(key: 'contactRef', array: $data['report']);
	}//end testTheReportPageCarriesItsRevealRequests()

	/**
	 * The controller over the stored rows, the real projection and reveal
	 * service, and a user in `$groups`.
	 *
	 * @param array<int, string> $groups The groups the user is in.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return ReportController
	 */
	private function controller(array $groups, bool $signedIn = true): ReportController {
		$rows = $this->rows;

		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(value: [['slug' => 'zuiddrecht']]);

		$reader = $this->createMock(originalClassName: PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef) use ($rows): array {
				$matches = static fn (array $row): bool => ($row[$scopeField] ?? null) === $subjectRef;
				return array_values(array_filter(array: $rows[$schema], callback: $matches));
			}
		);
		$reader->method('readObjectAsUser')->willReturnCallback(
			static function (string $register, string $schema, string $id) use ($rows): ?array {
				foreach ($rows[$schema] as $row) {
					if ($row['id'] === $id) {
						return $row;
					}
				}

				return null;
			}
		);

		$caseTypes = $this->createMock(originalClassName: CaseTypeReader::class);
		$declarations = [
			'misstand' => ['custodianGroup' => 'vertrouwenspersonen', 'handlerGroup' => 'meldpunt'],
			'integriteit' => ['custodianGroup' => 'elders', 'handlerGroup' => 'elders'],
		];
		$caseTypes->method('readCaseType')->willReturnCallback(
			static fn (string $register, string $schema, string $id): array => [ReportTermsService::DECLARATION => $declarations[$id]]
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array(needle: $group, haystack: $groups, strict: true)
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$staff   = null;
		if ($signedIn === true) {
			$staff = $this->createMock(originalClassName: IUser::class);
			$staff->method('getUID')->willReturn(value: 'behandelaar-b');
		}

		$session->method('getUser')->willReturn(value: $staff);

		$reveals = new RevealService(
			reader: $reader,
			writer: $this->createMock(originalClassName: PortalObjectWriter::class),
			groupManager: $groupManager,
			dispatcher: $this->createMock(originalClassName: IEventDispatcher::class)
		);
		$threads = $this->createMock(originalClassName: ReportThreadService::class);
		$threads->method('allMessages')->willReturn(value: []);

		return new ReportController(
			request: $this->createMock(originalClassName: IRequest::class),
			portals: $portals,
			challenge: $this->createMock(originalClassName: PortalChallengeService::class),
			intake: $this->createMock(originalClassName: ReportIntakeService::class),
			threads: $threads,
			terms: $this->createMock(originalClassName: ReportTermsService::class),
			reveals: $reveals,
			projection: new ReportProjection(),
			reader: $reader,
			caseTypes: $caseTypes,
			userSession: $session
		);
	}//end controller()
}//end class
