<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CrossRefConfigNormaliser;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The cross-reference declaration, and the one fail-closed direction that is
 * the opposite of every other normaliser here: a guard that could not be read
 * takes its action with it, because dropping the block alone would leave the
 * write standing unguarded.
 *
 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
 */
class CrossRefConfigNormaliserTest extends TestCase {
	/**
	 * A sound declaration survives, with `required` resolved.
	 *
	 * @return void
	 */
	public function testASoundDeclarationIsKept(): void {
		$action = $this->normalised(
			[
				'tegenZaakId' => [
					'register' => 'dossiq',
					'schema' => 'case',
					'scopeField' => 'portalSubject',
					'required' => true,
				],
			]
		);

		$this->assertNotNull($action);
		$this->assertSame(
			[
				'register' => 'dossiq',
				'schema' => 'case',
				'scopeField' => 'portalSubject',
				'required' => true,
				'scopeClaim' => '',
			],
			$action[CrossRefConfigNormaliser::KEY]['tegenZaakId']
		);
	}//end testASoundDeclarationIsKept()

	/**
	 * `required` is false unless it is said, so a declaration that forgets it
	 * guards the value that is there rather than demanding one.
	 *
	 * @return void
	 */
	public function testARequiredFlagDefaultsToFalse(): void {
		$action = $this->normalised(
			[
				'caseReference' => [
					'register' => 'dossiq',
					'schema' => 'case',
					'scopeField' => 'portalSubject',
				],
			]
		);

		$this->assertFalse($action[CrossRefConfigNormaliser::KEY]['caseReference']['required']);
	}//end testARequiredFlagDefaultsToFalse()

	/**
	 * A reference missing one of the three keys the portal cannot guess is
	 * unreadable, and takes the action with it.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationDropsTheAction(): void {
		$this->assertNull(
			$this->normalised(['tegenZaakId' => ['register' => 'dossiq', 'scopeField' => 'portalSubject']])
		);
	}//end testAMalformedDeclarationDropsTheAction()

	/**
	 * A guard naming a field the action never accepts can never fire, so it is
	 * not a guard at all.
	 *
	 * @return void
	 */
	public function testAGuardOnAFieldTheActionDoesNotAcceptDropsTheAction(): void {
		$this->assertNull(
			$this->normalised(
				[
					'somethingElse' => [
						'register' => 'dossiq',
						'schema' => 'case',
						'scopeField' => 'portalSubject',
					],
				]
			)
		);
	}//end testAGuardOnAFieldTheActionDoesNotAcceptDropsTheAction()

	/**
	 * An anonymous caller owns nothing, so a guarded action loses the flag
	 * rather than the guard.
	 *
	 * @return void
	 */
	public function testAGuardedActionLosesItsAnonymousFlag(): void {
		$action = $this->normalised(
			[
				'tegenZaakId' => [
					'register' => 'dossiq',
					'schema' => 'case',
					'scopeField' => 'portalSubject',
				],
			],
			['anonymous' => true]
		);

		$this->assertArrayNotHasKey('anonymous', $action);
		$this->assertArrayHasKey('tegenZaakId', $action[CrossRefConfigNormaliser::KEY]);
	}//end testAGuardedActionLosesItsAnonymousFlag()

	/**
	 * A reference declared on a read is a mis-declaration rather than a guard
	 * that failed: there is no body to check, so only the key goes.
	 *
	 * @return void
	 */
	public function testADeclarationOnANonWritingActionDropsOnlyTheKey(): void {
		$action = (new CrossRefConfigNormaliser())->normaliseAction(
			action: [
				'id' => 'openThing',
				'type' => 'endpoint',
				'fields' => ['tegenZaakId'],
				CrossRefConfigNormaliser::KEY => [
					'tegenZaakId' => [
						'register' => 'dossiq',
						'schema' => 'case',
						'scopeField' => 'portalSubject',
					],
				],
			],
			whitelist: ['tegenZaakId']
		);

		$this->assertNotNull($action);
		$this->assertArrayNotHasKey(CrossRefConfigNormaliser::KEY, $action);
	}//end testADeclarationOnANonWritingActionDropsOnlyTheKey()

	/**
	 * An action with no declaration is untouched: this change adds a guard, it
	 * does not require one.
	 *
	 * @return void
	 */
	public function testAnActionWithoutADeclarationIsUntouched(): void {
		$action = (new CrossRefConfigNormaliser())->normaliseAction(
			action: ['id' => 'createKlacht', 'type' => 'create', 'fields' => ['subject']],
			whitelist: ['subject']
		);

		$this->assertSame(['id' => 'createKlacht', 'type' => 'create', 'fields' => ['subject']], $action);
	}//end testAnActionWithoutADeclarationIsUntouched()

	/**
	 * The drop reaches the manifest, not only the normaliser it happens in.
	 *
	 * @return void
	 */
	public function testTheWholeManifestLosesAnUnguardableAction(): void {
		$manifest = (new PortalManifestNormaliser())->normalise(
			[
				'actions' => [
					[
						'id' => 'createBezwaar',
						'type' => 'create',
						'fields' => ['tegenZaakId'],
						CrossRefConfigNormaliser::KEY => ['tegenZaakId' => ['register' => 'dossiq']],
					],
					[
						'id' => 'createKlacht',
						'type' => 'create',
						'fields' => ['subject'],
					],
				],
			]
		);

		$this->assertSame(
			['createKlacht'],
			array_map(static fn (array $action): string => (string)$action['id'], $manifest['actions'])
		);
	}//end testTheWholeManifestLosesAnUnguardableAction()

	/**
	 * One create action carrying the given declaration, normalised.
	 *
	 * @param array<string, mixed> $declared The declaration.
	 * @param array<string, mixed> $extra    Extra keys on the action.
	 *
	 * @return array<string, mixed>|null The action, or null when it was dropped.
	 */
	private function normalised(array $declared, array $extra = []): ?array {
		return (new CrossRefConfigNormaliser())->normaliseAction(
			action: ([
				'id' => 'createBezwaar',
				'type' => 'create',
				'fields' => ['tegenZaakId', 'caseReference', 'rationale'],
				CrossRefConfigNormaliser::KEY => $declared,
			] + $extra),
			whitelist: ['tegenZaakId', 'caseReference', 'rationale']
		);
	}//end normalised()
}//end class
