<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CaseFieldKey;
use OCA\Portaliq\Contribution\RowIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Small normalisers used by the contribution layer.
 */
#[CoversClass(CaseFieldKey::class)]
#[CoversClass(RowIdentifier::class)]
class SmallContributionHelpersTest extends TestCase {
	/**
	 * caseField survives only when it names a listed column.
	 *
	 * @return void
	 */
	public function testCaseFieldKey(): void {
		$n = new CaseFieldKey();

		$this->assertSame(['a' => 1], $n->normalise(['a' => 1]));
		$this->assertSame(['caseField' => 'x'], $n->normalise(['caseField' => 'x']));
		$this->assertSame(['fields' => ['x']], $n->normalise(['caseField' => 5, 'fields' => ['x']]));
		$this->assertSame([], $n->normalise(['caseField' => '  ']));
		$this->assertSame(['fields' => ['y']], $n->normalise(['caseField' => 'x', 'fields' => ['y']]));
		$this->assertSame(['caseField' => 'y', 'fields' => ['y']], $n->normalise(['caseField' => 'y', 'fields' => ['y']]));
	}//end testCaseFieldKey()

	/**
	 * The identifier is found on the row, then @self, else the fallback.
	 *
	 * @return void
	 */
	public function testRowIdentifier(): void {
		$r = new RowIdentifier();

		$this->assertSame('1', $r->idFor(['id' => 1], 'fb'));
		$this->assertSame('u', $r->idFor(['id' => '', 'uuid' => 'u'], 'fb'));
		$this->assertSame('su', $r->idFor(['@self' => ['uuid' => 'su', 'id' => 'si']], 'fb'));
		$this->assertSame('si', $r->idFor(['@self' => ['id' => 'si']], 'fb'));
		$this->assertSame('fb', $r->idFor(['@self' => 'bad', 'id' => []], 'fb'));
	}//end testRowIdentifier()
}//end class
