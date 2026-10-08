<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionConfigNormaliser;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use OCA\Portaliq\Contribution\MessageBoxConfigNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * An inbox collection may name the method on its app's portal provider that
 * returns the recipient for the government message box
 * (inbox-berichtenbox-channel, REQ-MBC-002). The name comes from a manifest,
 * so it is held to the same rule as a timeline provider: a plain identifier,
 * never one of the contract's own methods. Anything else drops the key, so a
 * malformed manifest never makes portaliq call a method nobody meant.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
 */
class MessageBoxConfigNormaliserTest extends TestCase {

	/**
	 * A well-formed declaration on an inbox collection is kept, and only its
	 * known key travels on.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedDeclaration(): void {
		$collection = (new MessageBoxConfigNormaliser())->normalise(collection: [
			'id' => 'berichten',
			'kind' => 'inbox',
			'messageBox' => ['recipientProvider' => 'messageBoxRecipient', 'extra' => 'dropped'],
		]);

		$this->assertSame(['recipientProvider' => 'messageBoxRecipient'], $collection['messageBox']);
	}//end testKeepsAWellFormedDeclaration()

	/**
	 * The collection may name the fields that hold the letter's subject and
	 * text; a name that is not a plain field name is dropped, the rest stands.
	 *
	 * @return void
	 */
	public function testKeepsTheDeclaredLetterFields(): void {
		$normaliser = new MessageBoxConfigNormaliser();
		$collection = $normaliser->normalise(collection: [
			'kind' => 'inbox',
			'messageBox' => ['recipientProvider' => 'messageBoxRecipient', 'bodyField' => 'content', 'subjectField' => 'subject'],
		]);
		$this->assertSame(['recipientProvider' => 'messageBoxRecipient', 'bodyField' => 'content', 'subjectField' => 'subject'], $collection['messageBox']);

		$unsafe = $normaliser->normalise(collection: [
			'kind' => 'inbox',
			'messageBox' => ['recipientProvider' => 'messageBoxRecipient', 'bodyField' => 'a.b', 'subjectField' => ['x']],
		]);
		$this->assertSame(['recipientProvider' => 'messageBoxRecipient'], $unsafe['messageBox']);
	}//end testKeepsTheDeclaredLetterFields()

	/**
	 * A contract method, a non-identifier, a missing name, a non-array value
	 * and a collection that is not an inbox all drop the key.
	 *
	 * @return void
	 */
	public function testDropsAContractMethodName(): void {
		$normaliser = new MessageBoxConfigNormaliser();
		foreach ([
			['kind' => 'inbox', 'messageBox' => ['recipientProvider' => 'getContribution']],
			['kind' => 'inbox', 'messageBox' => ['recipientProvider' => 'getAudience']],
			['kind' => 'inbox', 'messageBox' => ['recipientProvider' => '__construct']],
			['kind' => 'inbox', 'messageBox' => ['recipientProvider' => 'a-b']],
			['kind' => 'inbox', 'messageBox' => []],
			['kind' => 'inbox', 'messageBox' => 'messageBoxRecipient'],
			['kind' => 'cases', 'messageBox' => ['recipientProvider' => 'messageBoxRecipient']],
		] as $collection) {
			$out = $normaliser->normalise(collection: $collection);
			$this->assertArrayNotHasKey('messageBox', $out, json_encode($collection));
			$this->assertSame($collection['kind'], $out['kind'], 'the rest of the collection stands');
		}

		$this->assertSame(['kind' => 'inbox'], $normaliser->normalise(collection: ['kind' => 'inbox']), 'no declaration, nothing added');
	}//end testDropsAContractMethodName()

	/**
	 * The collection normaliser every contribution passes through applies it.
	 *
	 * @return void
	 */
	public function testEveryContributionPassesThroughIt(): void {
		$collections = (new CollectionConfigNormaliser(values: new ManifestValueNormaliser()))->normaliseCollections(collections: [
			['id' => 'berichten', 'kind' => 'inbox', 'messageBox' => ['recipientProvider' => 'messageBoxRecipient']],
			['id' => 'kwaad', 'kind' => 'inbox', 'messageBox' => ['recipientProvider' => 'getContribution']],
		]);

		$this->assertSame(['recipientProvider' => 'messageBoxRecipient'], $collections[0]['messageBox']);
		$this->assertArrayNotHasKey('messageBox', $collections[1]);
	}//end testEveryContributionPassesThroughIt()
}//end class
