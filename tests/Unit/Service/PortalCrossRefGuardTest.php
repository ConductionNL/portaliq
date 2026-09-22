<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\CrossRefConfigNormaliser;
use OCA\Portaliq\Service\PortalCrossRefGuard;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The enforcement half of the cross-reference guard.
 *
 * The question asked of every declared reference is "may this subject already
 * read it", never "does it exist", and the double here answers accordingly:
 * it returns a row only for the id the subject owns.
 *
 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
 */
class PortalCrossRefGuardTest extends TestCase {
	/**
	 * The scoped read.
	 *
	 * @var PortalObjectReader&MockObject
	 */
	private PortalObjectReader $reader;

	/**
	 * The arguments every scoped read was made with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * Build the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reads = [];
		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->reader->method('readObject')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $id,
				string $organisation = '',
				string $scopeClaim = '',
				string $contributingApp = '',
				mixed $via = null,
				string $audience = '',
				mixed $fields = null,
			): ?array {
				$this->reads[] = [
					'register' => $register,
					'schema' => $schema,
					'scopeField' => $scopeField,
					'subjectRef' => $subjectRef,
					'id' => $id,
					'app' => $contributingApp,
				];

				// Only this one case belongs to this one subject.
				if ($id === 'case-mine' && $subjectRef === 'burger-1') {
					return ['id' => 'case-mine'];
				}

				return null;
			}
		);
	}//end setUp()

	/**
	 * Scenario: A citizen names somebody else's case.
	 *
	 * @return void
	 */
	public function testAReferenceOutsideTheSubjectsScopeRefuses(): void {
		$refused = $this->guard()->refusedField(
			action: $this->action(),
			data: ['tegenZaakId' => 'case-theirs', 'rationale' => 'Niet eens.'],
			subject: $this->subject(),
			app: 'dossiq'
		);

		$this->assertSame('tegenZaakId', $refused);
	}//end testAReferenceOutsideTheSubjectsScopeRefuses()

	/**
	 * Scenario: A citizen names their own case.
	 *
	 * @return void
	 */
	public function testAReferenceInsideTheSubjectsScopePasses(): void {
		$refused = $this->guard()->refusedField(
			action: $this->action(),
			data: ['tegenZaakId' => 'case-mine', 'rationale' => 'Niet eens.'],
			subject: $this->subject(),
			app: 'dossiq'
		);

		$this->assertSame('', $refused);
		$this->assertSame(
			[
				'register' => 'dossiq',
				'schema' => 'case',
				'scopeField' => 'portalSubject',
				'subjectRef' => 'burger-1',
				'id' => 'case-mine',
				'app' => 'dossiq',
			],
			$this->reads[0]
		);
	}//end testAReferenceInsideTheSubjectsScopePasses()

	/**
	 * Scenario: A required reference that was left out.
	 *
	 * @return void
	 */
	public function testARequiredReferenceThatIsAbsentRefuses(): void {
		$refused = $this->guard()->refusedField(
			action: $this->action(),
			data: ['rationale' => 'Niet eens.'],
			subject: $this->subject(),
			app: 'dossiq'
		);

		$this->assertSame('tegenZaakId', $refused);
		$this->assertSame([], $this->reads);
	}//end testARequiredReferenceThatIsAbsentRefuses()

	/**
	 * An optional reference the client left out is not a refusal.
	 *
	 * @return void
	 */
	public function testAnOptionalReferenceThatIsAbsentPasses(): void {
		$action = $this->action(required: false);

		$this->assertSame(
			'',
			$this->guard()->refusedField(
				action: $action,
				data: ['rationale' => 'Niet eens.'],
				subject: $this->subject(),
				app: 'dossiq'
			)
		);
	}//end testAnOptionalReferenceThatIsAbsentPasses()

	/**
	 * A list of references is checked entry by entry, and one foreign entry
	 * refuses the whole write.
	 *
	 * @return void
	 */
	public function testOneForeignEntryInAListRefusesTheWholeWrite(): void {
		$refused = $this->guard()->refusedField(
			action: $this->action(),
			data: ['tegenZaakId' => ['case-mine', 'case-theirs']],
			subject: $this->subject(),
			app: 'dossiq'
		);

		$this->assertSame('tegenZaakId', $refused);
	}//end testOneForeignEntryInAListRefusesTheWholeWrite()

	/**
	 * A value that is not a reference at all refuses: a caller who sends an
	 * object where a uuid belongs is not sending nothing.
	 *
	 * @return void
	 */
	public function testAValueThatIsNotAReferenceRefuses(): void {
		$refused = $this->guard()->refusedField(
			action: $this->action(),
			data: ['tegenZaakId' => 42],
			subject: $this->subject(),
			app: 'dossiq'
		);

		$this->assertSame('tegenZaakId', $refused);
	}//end testAValueThatIsNotAReferenceRefuses()

	/**
	 * An action that declares nothing reads nothing: the guard adds no cost
	 * to the writes that have no references.
	 *
	 * @return void
	 */
	public function testAnActionWithoutADeclarationIsNotChecked(): void {
		$refused = $this->guard()->refusedField(
			action: ['id' => 'createKlacht', 'type' => 'create', 'fields' => ['rationale']],
			data: ['rationale' => 'Niet eens.'],
			subject: $this->subject(),
			app: 'dossiq'
		);

		$this->assertSame('', $refused);
		$this->assertSame([], $this->reads);
	}//end testAnActionWithoutADeclarationIsNotChecked()

	/**
	 * One guarded create action, as the normaliser leaves it.
	 *
	 * @param bool $required Whether the reference is required.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function action(bool $required = true): array {
		return [
			'id' => 'createBezwaar',
			'type' => 'create',
			'fields' => ['tegenZaakId', 'rationale'],
			CrossRefConfigNormaliser::KEY => [
				'tegenZaakId' => [
					'register' => 'dossiq',
					'schema' => 'case',
					'scopeField' => 'portalSubject',
					'required' => $required,
					'scopeClaim' => '',
				],
			],
		];
	}//end action()

	/**
	 * The resolved subject.
	 *
	 * @return array<string, mixed> The subject.
	 */
	private function subject(): array {
		return [
			'subjectRef' => 'burger-1',
			'organisation' => 'gemeente',
			'audience' => 'citizen',
		];
	}//end subject()

	/**
	 * The guard over the double.
	 *
	 * @return PortalCrossRefGuard The guard under test.
	 */
	private function guard(): PortalCrossRefGuard {
		return new PortalCrossRefGuard(reader: $this->reader, logger: new NullLogger());
	}//end guard()
}//end class
