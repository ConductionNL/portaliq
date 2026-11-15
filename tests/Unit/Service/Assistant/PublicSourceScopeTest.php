<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Assistant;

use OCA\Portaliq\Service\Assistant\PublicSourceScope;
use PHPUnit\Framework\TestCase;

/**
 * search-assistant-from-public-content REQ-SAP-002, REQ-SAP-006: public is a
 * declaration, and nothing but published public content is on it.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t02
 */
class PublicSourceScopeTest extends TestCase {

	public function testOnlyPublishedPublicSchemas(): void {
		// The pin: adding a schema that holds a resident's data fails here.
		$this->assertSame(['page', 'glossaryTerm', 'publication'], PublicSourceScope::SCHEMAS);

		$scope = (new PublicSourceScope())->forPortal(portal: 'zuiddrecht');
		$this->assertSame(PublicSourceScope::SCHEMAS, array_column($scope['sources'], 'schema'));
		$this->assertSame(['portal' => 'zuiddrecht', 'status' => 'published'], $scope['sources'][0]['filters']);
		$this->assertSame(['status' => 'published'], $scope['sources'][2]['filters']);
		foreach (['portalMessage', 'portalCase', 'portalAccount', 'portalSubmission', 'portalSession'] as $private) {
			$this->assertNotContains($private, PublicSourceScope::SCHEMAS);
		}

	}//end testOnlyPublishedPublicSchemas()

	public function testTheAssistantIsOffUnlessExplicitlyTrue(): void {
		$scope = new PublicSourceScope();

		$this->assertFalse($scope->enabledFor(portal: null));
		$this->assertFalse($scope->enabledFor(portal: []));
		$this->assertFalse($scope->enabledFor(portal: ['assistant' => ['enabled' => 'true']]));
		$this->assertFalse($scope->enabledFor(portal: ['assistant' => ['enabled' => false]]));
		$this->assertTrue($scope->enabledFor(portal: ['assistant' => ['enabled' => true]]));

	}//end testTheAssistantIsOffUnlessExplicitlyTrue()

	public function testALeftOutRouteAndAForeignSchemaAreNotAdmitted(): void {
		$sources = new PublicSourceScope();
		$scope   = $sources->forPortal(portal: 'p', settings: ['excludedRoutes' => ['/intern/', '', 7, 'intern']]);

		$this->assertSame(['intern'], $scope['excludedRoutes']);
		$this->assertTrue($sources->admits(source: ['schema' => 'page', 'route' => '/afval'], scope: $scope));
		$this->assertFalse($sources->admits(source: ['schema' => 'page', 'route' => 'intern'], scope: $scope));
		$this->assertFalse($sources->admits(source: ['schema' => 'portalMessage', 'route' => 'afval'], scope: $scope));
		$this->assertFalse($sources->admits(source: [], scope: $scope));

	}//end testALeftOutRouteAndAForeignSchemaAreNotAdmitted()
}//end class
