<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\CitizenWritableSetResolver;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * withdrawing-your-own-case-from-the-portal REQ-WOC-001 and REQ-WOC-004: the
 * case type says whether a request may be withdrawn, until when, and onto
 * which status. A type that says nothing offers nothing, a closed window
 * yields the reason rather than an action, and an already withdrawn request is
 * never open again.
 *
 * @spec openspec/changes/withdrawing-your-own-case-from-the-portal/specs/withdrawing-your-own-case/spec.md
 */
class CitizenWithdrawalResolutionTest extends TestCase {

	public function testACaseTypeThatAllowsItOffersTheAction(): void {
		$resolver = $this->resolver($this->caseType(openStatuses: ['ontvangen']));

		$withdrawal = $resolver->withdrawal(action: $this->action(), case: ['caseType' => 'verhuizing', 'status' => 'ontvangen']);

		$this->assertTrue($withdrawal['declared']);
		$this->assertTrue($withdrawal['open']);
		$this->assertSame('ingetrokken', $withdrawal['targetStatus']);
		$this->assertSame('Als u intrekt, stopt de behandeling.', $withdrawal['confirmText']);

	}//end testACaseTypeThatAllowsItOffersTheAction()

	public function testACaseTypeThatDeclaresNothingOffersNothing(): void {
		$resolver = $this->resolver(['title' => 'Verhuizing']);

		$withdrawal = $resolver->withdrawal(action: $this->action(), case: ['caseType' => 'verhuizing', 'status' => 'ontvangen']);

		$this->assertFalse($withdrawal['declared']);
		$this->assertFalse($withdrawal['open']);
		$this->assertSame('', $withdrawal['targetStatus']);

	}//end testACaseTypeThatDeclaresNothingOffersNothing()

	public function testAClosedWindowSaysWhy(): void {
		$resolver = $this->resolver($this->caseType(openStatuses: ['ontvangen'], closedReason: 'Uw aanvraag is al beoordeeld.'));

		$withdrawal = $resolver->withdrawal(action: $this->action(), case: ['caseType' => 'verhuizing', 'status' => 'besloten']);

		$this->assertTrue($withdrawal['declared']);
		$this->assertFalse($withdrawal['open']);
		$this->assertSame('Uw aanvraag is al beoordeeld.', $withdrawal['reason']);

	}//end testAClosedWindowSaysWhy()

	public function testAnAlreadyWithdrawnRequestIsNeverOpenAgain(): void {
		$resolver = $this->resolver($this->caseType(openStatuses: ['ontvangen', 'ingetrokken']));

		$withdrawal = $resolver->withdrawal(action: $this->action(), case: ['caseType' => 'verhuizing', 'status' => 'ingetrokken']);

		// Even though the declaration lists the target status as open, a
		// second withdrawal is refused.
		$this->assertFalse($withdrawal['open']);

	}//end testAnAlreadyWithdrawnRequestIsNeverOpenAgain()

	public function testADeclarationWithNowhereToLandOffersNothing(): void {
		$caseType = $this->caseType(openStatuses: ['ontvangen']);
		unset($caseType['portalWithdrawal']['targetStatus']);
		$resolver = $this->resolver($caseType);

		$withdrawal = $resolver->withdrawal(action: $this->action(), case: ['caseType' => 'verhuizing', 'status' => 'ontvangen']);

		$this->assertFalse($withdrawal['declared']);

	}//end testADeclarationWithNowhereToLandOffersNothing()

	public function testAnActionWithNoCitizenWriteDeclarationOffersNothing(): void {
		$resolver = $this->resolver($this->caseType(openStatuses: ['ontvangen']));

		$withdrawal = $resolver->withdrawal(action: ['id' => 'amend-case'], case: ['caseType' => 'verhuizing', 'status' => 'ontvangen']);

		$this->assertFalse($withdrawal['declared']);

	}//end testAnActionWithNoCitizenWriteDeclarationOffersNothing()

	public function testAnUnreadableCaseTypeOffersNothing(): void {
		$resolver = $this->resolver(null);

		$this->assertFalse($resolver->withdrawal(action: $this->action(), case: ['caseType' => 'verhuizing', 'status' => 'ontvangen'])['declared']);

	}//end testAnUnreadableCaseTypeOffersNothing()

	/**
	 * A case type declaring withdrawal.
	 *
	 * @param array<int, string> $openStatuses When withdrawal is possible.
	 * @param string $closedReason What is said once it is not.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(array $openStatuses, string $closedReason = 'Dit kan niet meer.'): array {
		return [
			'title' => 'Verhuizing',
			'portalWithdrawal' => [
				'openStatuses' => $openStatuses,
				'closedReason' => $closedReason,
				'targetStatus' => 'ingetrokken',
				'confirmText' => 'Als u intrekt, stopt de behandeling.',
			],
		];
	}//end caseType()

	/**
	 * The matched update action carrying its citizen write declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function action(): array {
		return [
			'id' => 'amend-case',
			CitizenWriteConfigNormaliser::KEY => [
				'typeField' => 'caseType',
				'typeRegister' => 'portaliq',
				'typeSchema' => 'portalCaseType',
				'statusField' => 'status',
				'recordField' => 'portalWrites',
			],
		];
	}//end action()

	/**
	 * The resolver over one case type.
	 *
	 * @param array<string, mixed>|null $caseType The case type it reads.
	 *
	 * @return CitizenWritableSetResolver
	 */
	private function resolver(?array $caseType): CitizenWritableSetResolver {
		$reader = $this->getMockBuilder(CaseTypeReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCaseType'])
			->getMock();
		$reader->method('readCaseType')->willReturn($caseType);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CitizenWritableSetResolver($reader, $l10n);
	}//end resolver()

}//end class
