<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\AttachedActionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Actions that attach to another app's collection (woo-journey-entry-points
 * D3): pipelinq's question and dossiq's Woo request on opencatalogi's dossier.
 *
 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
 */
class AttachedActionResolverTest extends TestCase {
	/**
	 * pipelinq's askAboutDossier as its provider declares it.
	 *
	 * @param array<string, mixed> $overrides Keys to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function ask(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'askAboutDossier',
				'label' => 'Stel een vraag over dit dossier',
				'type' => 'endpoint-forward',
				'endpoint' => '/index.php/apps/pipelinq/api/portal/dossier-questions',
				'method' => 'POST',
				'fields' => ['question'],
				'fieldConfigs' => ['question' => ['label' => 'Uw vraag']],
				'rowField' => 'collectionId',
				'attachTo' => ['app' => 'opencatalogi', 'schema' => 'collection'],
			],
			$overrides
		);
	}//end ask()

	/**
	 * The aggregate: opencatalogi's dossiers and saved searches, pipelinq's action.
	 *
	 * @param array<string, mixed> $action The pipelinq action.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contributions(array $action): array {
		return [
			[
				'app' => 'opencatalogi',
				'collections' => [
					['id' => 'mijnDossiers', 'register' => 'opencatalogi', 'schema' => 'collection', 'attachedActions' => [['app' => 'x', 'id' => 'forged']]],
					['id' => 'mijnZoekopdrachten', 'register' => 'opencatalogi', 'schema' => 'savedSearch'],
				],
				'actions' => [],
			],
			['app' => 'pipelinq', 'collections' => [], 'actions' => [$action]],
		];
	}//end contributions()

	/**
	 * The action lands on the dossier collection only, with its fields and
	 * without its endpoint.
	 *
	 * @return void
	 */
	public function testAttachesToTheNamedSchemaOnly(): void {
		$out = (new AttachedActionResolver())->resolve(contributions: $this->contributions(action: $this->ask()));

		$dossiers = $out[0]['collections'][0];
		self::assertSame(
			[[
				'app' => 'pipelinq',
				'id' => 'askAboutDossier',
				'label' => 'Stel een vraag over dit dossier',
				'fields' => ['question'],
				'fieldConfigs' => ['question' => ['label' => 'Uw vraag']],
			]],
			$dossiers['attachedActions']
		);
		self::assertArrayNotHasKey('attachedActions', $out[0]['collections'][1]);
		// The action itself is untouched in its own contribution.
		self::assertSame($this->ask(), $out[1]['actions'][0]);
	}//end testAttachesToTheNamedSchemaOnly()

	/**
	 * A provider cannot declare attached actions on its own collection.
	 *
	 * @return void
	 */
	public function testDropsAProviderDeclaredList(): void {
		$out = (new AttachedActionResolver())->resolve(contributions: $this->contributions(action: $this->ask(['attachTo' => 'opencatalogi'])));

		self::assertArrayNotHasKey('attachedActions', $out[0]['collections'][0]);
		self::assertSame('opencatalogi', $out[1]['actions'][0]['attachTo']);
	}//end testDropsAProviderDeclaredList()

	/**
	 * No declaring app, no attachment: with dossiq disabled its action is absent
	 * from the aggregate, so nothing attaches.
	 *
	 * @return void
	 */
	public function testNoDeclaringAppNoAttachment(): void {
		$contributions = $this->contributions(action: $this->ask());
		unset($contributions[1]);

		$out = (new AttachedActionResolver())->resolve(contributions: array_values($contributions));

		self::assertArrayNotHasKey('attachedActions', $out[0]['collections'][0]);
	}//end testNoDeclaringAppNoAttachment()

	/**
	 * An action that is not an endpoint row action (no rowField, or an
	 * off-instance endpoint) attaches nothing.
	 *
	 * @return void
	 */
	public function testMalformedActionsAttachNothing(): void {
		foreach ([['rowField' => null], ['endpoint' => 'https://evil.example/x'], ['attachTo' => ['app' => 'opencatalogi']]] as $bad) {
			$out = (new AttachedActionResolver())->resolve(contributions: $this->contributions(action: $this->ask($bad)));
			self::assertArrayNotHasKey('attachedActions', $out[0]['collections'][0], json_encode($bad));
		}
	}//end testMalformedActionsAttachNothing()

	/**
	 * The row field is stamped by the server, so it is not among the fields
	 * the resident fills in.
	 *
	 * @return void
	 */
	public function testTheRowFieldIsNotAsked(): void {
		$out = (new AttachedActionResolver())->resolve(contributions: $this->contributions(action: $this->ask(['fields' => ['collectionId', 'question']])));

		self::assertSame(['question'], $out[0]['collections'][0]['attachedActions'][0]['fields']);
	}//end testTheRowFieldIsNotAsked()

	/**
	 * The forward's lookup: the attached action in its own contribution.
	 *
	 * @return void
	 */
	public function testFindsTheAttachedActionForAForward(): void {
		$resolver = new AttachedActionResolver();
		$out = $resolver->resolve(contributions: $this->contributions(action: $this->ask()));

		$found = $resolver->attachedAction(contributions: $out, collectionApp: 'opencatalogi', collection: $out[0]['collections'][0], actionApp: 'pipelinq', actionId: 'askAboutDossier');
		self::assertSame('askAboutDossier', $found['id'] ?? null);
		self::assertNull($resolver->attachedAction(contributions: $out, collectionApp: 'opencatalogi', collection: $out[0]['collections'][1], actionApp: 'pipelinq', actionId: 'askAboutDossier'));
		self::assertNull($resolver->attachedAction(contributions: $out, collectionApp: 'opencatalogi', collection: $out[0]['collections'][0], actionApp: 'dossiq', actionId: 'askAboutDossier'));
	}//end testFindsTheAttachedActionForAForward()
}//end class
