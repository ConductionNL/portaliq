<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * submit-creates-the-case-directly REQ-PIFO-004/005/007, through the real
 * DestinationSubmit and DestinationMapping: what reaches OpenRegister, how
 * a refusal comes back per field, and what stops a binding being published.
 * The publish check runs OpenRegister's REAL FormDestinationValidator over
 * the REAL dossiq `case` schema when openregister#4563 is on the autoload path.
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationIntake
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 * @uses \OCA\Portaliq\Service\Intake\DestinationMapping
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 * @uses \OCA\Portaliq\Listener\RegisterProposalLeavesListener
 * @uses \OCA\Portaliq\Service\ActionAuthService
 * @uses \OCA\Portaliq\Service\PortalFieldProjector
 * @uses \OCA\Portaliq\Service\PortalFilteredRows
 * @uses \OCA\Portaliq\Service\PortalObjectReader
 * @uses \OCA\Portaliq\Service\PortalObjectWriter
 * @uses \OCA\Portaliq\Service\PortalSchemaReader
 * @uses \OCA\Portaliq\Service\Proposals\ChangeProposalsProvider
 * @uses \OCA\Portaliq\Service\Proposals\ProposalQueueReader
 * @uses \OCA\Portaliq\Service\Proposals\ProposalService
 * @uses \OCA\Portaliq\Service\Proposals\ReviewerObjectWriter
 * @uses \OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard
 */
class DestinationIntakeTest extends TestCase {

	private const TYPE_ID = '0b5c1e9e-4f3a-4b8e-9c7d-2a1f0e6d5c4b';

	public function testASubmitSendsTheMappedAnswersAndTheServerValuesAndWritesNothingInPortaliq(): void {
		$fake   = new FormSubmitFake(outcome: ['reference' => '2026-0042', 'id' => 'u-1', 'receivedAt' => '2026-10-11T09:00:00+00:00', 'confirmation' => []]);
		$result = $this->intake(fake: $fake)->submit(
			binding: $this->binding(),
			render: ['fields' => $this->fields()],
			answers: ['title' => 'Bezwaar tegen besluit', 'description' => 'Ik ben het er niet mee eens.'],
			subjectRef: 'sub-123',
			server: ['externalReference' => 'AANVRAAG-ABC12345', 'statements' => [['key' => 'truth']]],
			idempotencyKey: 'k'
		);

		$this->assertTrue($result['ok']);
		$call = $fake->calls[0];
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case'], $call['destination']);
		$this->assertSame(self::TYPE_ID, $call['mapping']['fixed']['caseType']);
		$this->assertSame('sub-123', $call['mapping']['fixed']['portalSubject']);
		$this->assertSame('AANVRAAG-ABC12345', $call['mapping']['fixed']['externalReference']);
		$this->assertArrayNotHasKey('statements', $call['mapping']['fixed']);
		$this->assertSame('gemeente-x/bezwaar', $call['scope']);
		// The only writer is OpenRegister's submit service: one call, nothing else.
		$this->assertCount(1, $fake->calls);
	}//end testASubmitSendsTheMappedAnswersAndTheServerValuesAndWritesNothingInPortaliq()

	public function testADestinationRefusalComesBackOnTheField(): void {
		$fake   = new FormSubmitFake(outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'title', 'code' => 'enum', 'message' => 'Kies een waarde uit de lijst.']]));
		$result = $this->intake(fake: $fake)->submit(binding: $this->binding(), render: ['fields' => $this->fields()], answers: ['title' => 'x']);

		$this->assertSame(422, $result['status']);
		$this->assertSame(['title' => 'Kies een waarde uit de lijst.'], $result['errors']);
	}//end testADestinationRefusalComesBackOnTheField()

	public function testABindingWithoutADestinationRefusesAndCallsNothing(): void {
		$fake   = new FormSubmitFake(outcome: ['reference' => 'r']);
		$result = $this->intake(fake: $fake)->submit(binding: ['portal' => 'gemeente-x'], render: ['fields' => []], answers: []);

		$this->assertSame(503, $result['status']);
		$this->assertSame([], $fake->calls);
	}//end testABindingWithoutADestinationRefusesAndCallsNothing()

	public function testAnExternalBindingHasNothingToCheckAndAMissingDestinationIsAFinding(): void {
		$intake = $this->intake(fake: new FormSubmitFake());

		$this->assertSame([], $intake->check(binding: [], render: ['kind' => 'external']));
		$this->assertSame(DestinationIntake::NO_DESTINATION, $intake->check(binding: ['portal' => 'x'], render: [])[0]['code']);
	}//end testAnExternalBindingHasNothingToCheckAndAMissingDestinationIsAFinding()

	public function testADestinationThatCannotBeCheckedIsNotValid(): void {
		$findings = $this->intake(fake: new FormSubmitFake())->check(binding: $this->binding(), render: ['fields' => $this->fields()]);

		$this->assertSame(DestinationIntake::UNCHECKED, $findings[0]['code']);
	}//end testADestinationThatCannotBeCheckedIsNotValid()

	public function testTheRealValidatorAcceptsABindingThatFitsTheDossiqCase(): void {
		$findings = $this->intake(fake: new FormSubmitFake(), realValidator: true)->check(binding: $this->binding(), render: ['fields' => $this->fields()]);

		$this->assertSame([], $findings);
	}//end testTheRealValidatorAcceptsABindingThatFitsTheDossiqCase()

	public function testTheRealValidatorNamesTheRequiredCaseTypeABindingLeavesOut(): void {
		$binding = $this->binding();
		unset($binding['typeId']);

		$findings = $this->intake(fake: new FormSubmitFake(), realValidator: true)->check(binding: $binding, render: ['fields' => $this->fields()]);

		$this->assertContains(['property' => 'caseType', 'code' => 'required-unmapped'], array_map(static fn (array $f): array => ['property' => $f['property'], 'code' => $f['code']], $findings));
	}//end testTheRealValidatorNamesTheRequiredCaseTypeABindingLeavesOut()

	/**
	 * The intake over the real DestinationSubmit and DestinationMapping.
	 *
	 * @param FormSubmitFake $fake          OpenRegister's submit service.
	 * @param bool           $realValidator Hand out OpenRegister's real validator and the real case schema.
	 *
	 * @return DestinationIntake
	 */
	private function intake(FormSubmitFake $fake, bool $realValidator = false): DestinationIntake {
		$schema  = json_decode((string)file_get_contents(__DIR__ . '/../../../Fixtures/dossiq-case-schema-d179.json'), true)['schema'];
		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn($schema);

		$more = [];
		if ($realValidator === true) {
			$more = $this->realValidator(schema: $schema);
		}

		return new DestinationIntake(
			submitter: new DestinationSubmit($fake->container($more), $this->createMock(LoggerInterface::class)),
			mapping: new DestinationMapping(),
			schemas: $schemas
		);
	}//end intake()

	/**
	 * OpenRegister's real validator and a schema mapper answering the real case schema.
	 *
	 * @param array<string, mixed> $schema The case schema.
	 *
	 * @return array<string, object>
	 */
	private function realValidator(array $schema): array {
		if (class_exists('OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator') === false || class_exists('OCA\\OpenRegister\\Db\\Schema') === false) {
			$this->markTestSkipped('OpenRegister with FormDestinationValidator (openregister#4563) is not on the autoload path; set PORTALIQ_OPENREGISTER_LIB.');
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$entity = new \OCA\OpenRegister\Db\Schema();
		$entity->hydrate(array_merge($schema, ['slug' => 'case']));

		$mapper = new class($entity) {
			public function __construct(private object $entity) {
			}

			public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): array {
				return [$this->entity];
			}
		};

		return [
			'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator' => new \OCA\OpenRegister\Service\Form\FormDestinationValidator($l10n, new \OCA\OpenRegister\Service\Form\FormFieldRules()),
			'OCA\\OpenRegister\\Db\\SchemaMapper' => $mapper,
		];
	}//end realValidator()

	/**
	 * A binding into the dossiq case, as the repair step leaves it.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(): array {
		return [
			'portal' => 'gemeente-x',
			'route' => 'bezwaar',
			'typeSchema' => 'caseType',
			'typeId' => self::TYPE_ID,
			'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject'],
		];
	}//end binding()

	/**
	 * A bezwaar form's fields, as the shared runtime renders them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fields(): array {
		return [
			['name' => 'title', 'type' => 'text', 'label' => 'Onderwerp', 'required' => true, 'maxLength' => 255],
			['name' => 'description', 'type' => 'textarea', 'label' => 'Toelichting'],
		];
	}//end fields()
}//end class
