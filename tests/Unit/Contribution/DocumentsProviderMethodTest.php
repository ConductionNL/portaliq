<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionConfigNormaliser;
use OCA\Portaliq\Contribution\DocumentsProviderMethod;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A case collection may name the method on its app's portal provider that
 * returns the documents a resident may see on a case (cases-documents-on-the-
 * case, REQ-CDC-001). The name is held to the timeline method rule.
 *
 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
 */
class DocumentsProviderMethodTest extends TestCase {

	/**
	 * A well-formed declaration is kept with its label.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedDeclaration(): void {
		$out = (new DocumentsProviderMethod())->normalise(collection: ['id' => 'mijnZaken', 'documents' => ['label' => 'Stukken', 'provider' => 'caseDocuments', 'x' => 1]]);
		$this->assertSame(['label' => 'Stukken', 'provider' => 'caseDocuments'], $out['documents']);

		$out = (new DocumentsProviderMethod())->normalise(collection: ['documents' => ['label' => ['nl' => 'x'], 'provider' => 'caseDocuments']]);
		$this->assertSame(['label' => '', 'provider' => 'caseDocuments'], $out['documents'], 'a label that is not text becomes empty');
	}//end testKeepsAWellFormedDeclaration()

	/**
	 * A contract method drops the key.
	 *
	 * @return void
	 */
	public function testDropsAContractMethodName(): void {
		foreach (['getContribution', 'getAudience', 'getAudiences'] as $name) {
			$out = (new DocumentsProviderMethod())->normalise(collection: ['id' => 'c', 'documents' => ['provider' => $name]]);
			$this->assertArrayNotHasKey('documents', $out, $name);
		}
	}//end testDropsAContractMethodName()

	/**
	 * A name that is not a plain identifier, or no name, drops the key.
	 *
	 * @return void
	 */
	public function testDropsANonIdentifier(): void {
		foreach ([['provider' => 'a-b'], ['provider' => '__call'], ['provider' => 'Upper'], [], 'caseDocuments'] as $declared) {
			$out = (new DocumentsProviderMethod())->normalise(collection: ['id' => 'c', 'documents' => $declared]);
			$this->assertArrayNotHasKey('documents', $out, json_encode($declared));
			$this->assertSame('c', $out['id']);
		}
	}//end testDropsANonIdentifier()

	/**
	 * Every contribution's collections pass through it.
	 *
	 * @return void
	 */
	public function testEveryContributionPassesThroughIt(): void {
		$collections = (new CollectionConfigNormaliser(values: new ManifestValueNormaliser()))->normaliseCollections(collections: [
			['id' => 'a', 'documents' => ['label' => 'Stukken', 'provider' => 'caseDocuments']],
			['id' => 'b', 'documents' => ['provider' => 'getContribution']],
		]);
		$this->assertSame(['label' => 'Stukken', 'provider' => 'caseDocuments'], $collections[0]['documents']);
		$this->assertArrayNotHasKey('documents', $collections[1]);
	}//end testEveryContributionPassesThroughIt()
}//end class
