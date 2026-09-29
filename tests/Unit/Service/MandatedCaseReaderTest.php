<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
use OCA\Portaliq\Service\MandatedCaseReader;
use OCA\Portaliq\Service\PortalCaseListReader;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * cases-my-cases-page REQ-CMC-004: a case listed under a mandate opens under
 * that mandate on the case screen, through the very reader that listed it,
 * so the screen can never show a case the list would not.
 *
 * The case list reader and the mandate service are the real classes; only the
 * OpenRegister read, the stored mandates and the party tree are doubles.
 *
 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
 */
class MandatedCaseReaderTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'employee-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'];

	/**
	 * The scope values the OpenRegister double was read by.
	 *
	 * @var array<int, string>
	 */
	private array $readBy = [];

	public function testTheCaseOpensUnderTheMandateThatListedIt(): void {
		$case = $this->reader()->read(subject: self::SUBJECT, mandateId: 'mandate-1', register: 'dossiq', schema: 'case', id: 'zaak-9');

		$this->assertNotNull($case);
		$this->assertSame('ZAAK-9', $case['reference']);
		$this->assertSame('Bakkerij Jansen BV', $case['_mandate']['label']);
		// Read by the party field, for the company, never by the employee.
		$this->assertSame(['kvk-1'], $this->readBy);
	}//end testTheCaseOpensUnderTheMandateThatListedIt()

	public function testNothingOpensWithoutAMandateTheIdentityHolds(): void {
		foreach (['', 'self', 'mandate-of-someone-else'] as $mandateId) {
			$this->assertNull(
				$this->reader()->read(subject: self::SUBJECT, mandateId: $mandateId, register: 'dossiq', schema: 'case', id: 'zaak-9'),
				$mandateId
			);
		}

		$this->assertSame([], $this->readBy);
	}//end testNothingOpensWithoutAMandateTheIdentityHolds()

	public function testACaseTheMandateDoesNotListStaysClosed(): void {
		$reader = $this->reader();

		$this->assertNull($reader->read(subject: self::SUBJECT, mandateId: 'mandate-1', register: 'dossiq', schema: 'case', id: 'zaak-other'));
		$this->assertNull($reader->read(subject: self::SUBJECT, mandateId: 'mandate-1', register: 'dossiq', schema: 'other-schema', id: 'zaak-9'));
	}//end testACaseTheMandateDoesNotListStaysClosed()

	public function testAGroupPastTheBoundOpensNothing(): void {
		$reader = $this->reader(scope: ['entities' => [], 'refused' => true, 'bound' => ['maxDepth' => 4, 'pageSize' => 100]]);

		$this->assertNull($reader->read(subject: self::SUBJECT, mandateId: 'mandate-1', register: 'dossiq', schema: 'case', id: 'zaak-9'));
		$this->assertSame([], $this->readBy);
	}//end testAGroupPastTheBoundOpensNothing()

	/**
	 * The reader over one dossiq case collection that declares its party
	 * field, one held mandate for the company, and the company's one case.
	 *
	 * @param array<string, mixed>|null $scope What the party tree answers.
	 *
	 * @return MandatedCaseReader
	 */
	private function reader(?array $scope = null): MandatedCaseReader {
		$objects = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$objects->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef): array {
				$this->readBy[] = $subjectRef;
				if ($scopeField !== 'requesterOrganisation' || $subjectRef !== 'kvk-1') {
					return [];
				}

				return [['id' => 'zaak-9', 'reference' => 'ZAAK-9', 'caseType' => 'vergunning', 'requesterOrganisation' => 'kvk-1']];
			}
		);

		$mandates = $this->getMockBuilder(PortalMandateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['mandatesFor'])
			->getMock();
		$mandates->method('mandatesFor')->willReturn([
			[
				'uuid' => 'mandate-1',
				'subjectRef' => 'employee-1',
				'organisation' => 'gemeente-x',
				'onBehalfOf' => 'kvk-1',
				'label' => 'Bakkerij Jansen BV',
				'status' => 'active',
			],
		]);

		$tree = $this->getMockBuilder(PortalPartyTreeResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['entitiesFor'])
			->getMock();
		$tree->method('entitiesFor')->willReturn(($scope ?? ['entities' => ['kvk-1'], 'refused' => false, 'bound' => ['maxDepth' => 4, 'pageSize' => 100]]));

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['aggregateFor'])
			->getMock();
		$registry->method('aggregateFor')->willReturn([
			'contributions' => [
				[
					'app' => 'dossiq',
					'label' => 'Zaken',
					'collections' => [
						[
							'id' => 'mijnZaken',
							'kind' => 'cases',
							'register' => 'dossiq',
							'schema' => 'case',
							'scopeField' => 'portalSubject',
							'mandateField' => 'requesterOrganisation',
						],
					],
				],
			],
		]);

		return new MandatedCaseReader($registry, new PortalCaseListReader($objects, $mandates), $mandates, $tree);
	}//end reader()
}//end class
