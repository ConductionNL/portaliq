<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ItemListConfigNormaliser;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The `itemList` declaration on a collection (my-dossiers D1).
 *
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */
class ItemListConfigNormaliserTest extends TestCase {
	/**
	 * opencatalogi's remove action.
	 *
	 * @param array<string, mixed> $overrides Keys to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function remove(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'removeCollectionItem',
				'label' => 'Verwijderen',
				'endpoint' => '/index.php/apps/opencatalogi/api/portal/collections/items/remove',
				'method' => 'POST',
				'fields' => ['itemId'],
				'rowField' => 'collectionId',
			],
			$overrides
		);
	}//end remove()

	/**
	 * The dossier collection.
	 *
	 * @param mixed $itemList The declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function dossiers(mixed $itemList): array {
		return ['id' => 'mijnDossiers', 'register' => 'opencatalogi', 'schema' => 'collection', 'rowActions' => ['removeCollectionItem'], 'itemList' => $itemList];
	}//end dossiers()

	/**
	 * A well-formed declaration survives, label defaulting to ''.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedDeclaration(): void {
		$out = (new ItemListConfigNormaliser())->resolve(
			collections: [$this->dossiers(['provider' => 'dossierItems', 'removeAction' => 'removeCollectionItem'])],
			actions: [$this->remove()]
		);

		self::assertSame(['label' => '', 'provider' => 'dossierItems', 'removeAction' => 'removeCollectionItem'], $out[0]['itemList']);
	}//end testKeepsAWellFormedDeclaration()

	/**
	 * Remove action without itemId: the key goes, the list stays.
	 *
	 * @return void
	 */
	public function testRemoveActionWithoutItemId(): void {
		$out = (new ItemListConfigNormaliser())->resolve(
			collections: [$this->dossiers(['label' => 'In dit dossier', 'provider' => 'dossierItems', 'removeAction' => 'removeCollectionItem'])],
			actions: [$this->remove(['fields' => ['note']])]
		);

		self::assertSame(['label' => 'In dit dossier', 'provider' => 'dossierItems'], $out[0]['itemList']);
	}//end testRemoveActionWithoutItemId()

	/**
	 * A remove action the collection does not offer on its rows is dropped too.
	 *
	 * @return void
	 */
	public function testRemoveActionNotOnTheRows(): void {
		$collection = $this->dossiers(['provider' => 'dossierItems', 'removeAction' => 'removeCollectionItem']);
		$collection['rowActions'] = [];
		$out = (new ItemListConfigNormaliser())->resolve(collections: [$collection], actions: [$this->remove()]);

		self::assertArrayNotHasKey('removeAction', $out[0]['itemList']);
	}//end testRemoveActionNotOnTheRows()

	/**
	 * A bad provider drops the whole declaration.
	 *
	 * @return void
	 */
	public function testABadProviderDropsTheList(): void {
		foreach (['getContribution', 'Evil::static', '', null, 'x y'] as $bad) {
			$out = (new ItemListConfigNormaliser())->resolve(collections: [$this->dossiers(['provider' => $bad])], actions: []);
			self::assertArrayNotHasKey('itemList', $out[0], var_export($bad, true));
		}

		$out = (new ItemListConfigNormaliser())->resolve(collections: [$this->dossiers('dossierItems')], actions: []);
		self::assertArrayNotHasKey('itemList', $out[0]);
	}//end testABadProviderDropsTheList()

	/**
	 * The manifest normaliser runs it.
	 *
	 * @return void
	 */
	public function testTheManifestNormaliserRunsIt(): void {
		$out = (new PortalManifestNormaliser())->normalise(contribution: [
			'collections' => [$this->dossiers(['provider' => 'getContribution'])],
			'actions' => [$this->remove()],
		]);

		self::assertArrayNotHasKey('itemList', $out['collections'][0]);
	}//end testTheManifestNormaliserRunsIt()
}//end class
