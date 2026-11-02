<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\GlossaryRelations;
use PHPUnit\Framework\TestCase;

/**
 * portal-cms-content-model task 3: a term relates only to terms of its own portal.
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
 */
class GlossaryRelationsTest extends TestCase {

	public function testARelationToATermOfTheSamePortalPasses(): void {
		$terms = [['portal' => 'a', '@self' => ['uuid' => 't1']], ['portal' => 'a', 'id' => 't2']];

		$this->assertSame([], (new GlossaryRelations())->foreign('a', ['t1', 't2'], $terms));
	}//end testARelationToATermOfTheSamePortalPasses()

	public function testARelationToATermOfAnotherPortalIsNamed(): void {
		$terms = [['portal' => 'a', 'id' => 't1'], ['portal' => 'b', 'id' => 't9']];

		$this->assertSame(['t9'], (new GlossaryRelations())->foreign('a', ['t1', 't9'], $terms));
	}//end testARelationToATermOfAnotherPortalIsNamed()

	public function testARelationThatResolvesNowhereIsRefused(): void {
		$this->assertSame(['ghost'], (new GlossaryRelations())->foreign('a', ['ghost'], []));
		$this->assertSame([''], (new GlossaryRelations())->foreign('a', [['not' => 'an id']], []));
	}//end testARelationThatResolvesNowhereIsRefused()
}//end class
