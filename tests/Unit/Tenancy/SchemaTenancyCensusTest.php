<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Service\Tenancy\SchemaTenancy;
use PHPUnit\Framework\TestCase;

/**
 * operate-portals-per-organisation REQ-OPO-001: every schema of the register
 * says how it is kept apart, and what it says is true of the schema.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t01
 */
class SchemaTenancyCensusTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $schemas;

	public static function setUpBeforeClass(): void {
		$register      = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/portaliq_register.json'), true);
		self::$schemas = $register['components']['schemas'];
	}

	public function testEverySchemaIsDeclared(): void {
		$missing = array_values(array_diff(array_keys(self::$schemas), array_keys(SchemaTenancy::MAP)));

		$this->assertSame([], $missing, 'declare these schemas in SchemaTenancy::MAP: '.implode(', ', $missing));

	}//end testEverySchemaIsDeclared()

	public function testNoDeclarationNamesAMissingSchema(): void {
		$stale = array_values(array_diff(array_keys(SchemaTenancy::MAP), array_keys(self::$schemas)));

		$this->assertSame([], $stale, 'remove these from SchemaTenancy::MAP: '.implode(', ', $stale));

	}//end testNoDeclarationNamesAMissingSchema()

	public function testADeclaredFieldIsOnTheSchema(): void {
		$wrong = [];
		foreach (SchemaTenancy::MAP as $slug => $entry) {
			$properties = array_keys((array)(self::$schemas[$slug]['properties'] ?? []));
			$needs      = [
				SchemaTenancy::ORGANISATION => 'organisation',
				SchemaTenancy::PORTAL       => 'portal',
				SchemaTenancy::SUBJECT      => 'subjectRef',
				SchemaTenancy::PARENT       => ($entry['via'] ?? ''),
			][$entry['scope']] ?? null;
			if ($needs !== null && in_array($needs, $properties, true) === false) {
				$wrong[] = $slug.' lacks '.$needs;
			}
		}

		$this->assertSame([], $wrong);

	}//end testADeclaredFieldIsOnTheSchema()

	public function testAParentIsDeclaredAndTheGlobalOnesAreNamed(): void {
		$globalParents = [];
		foreach (SchemaTenancy::MAP as $slug => $entry) {
			if ($entry['scope'] !== SchemaTenancy::PARENT) {
				continue;
			}

			$parent = ($entry['parent'] ?? '');
			$this->assertArrayHasKey($parent, SchemaTenancy::MAP, $slug.' hangs on a declared schema');
			if (SchemaTenancy::MAP[$parent]['scope'] === SchemaTenancy::GLOBAL) {
				$globalParents[$slug] = $parent;
			}
		}

		// A child of a global schema is as open as its parent. These are the known
		// ones, waiting for the parent to get a portal field; a new one fails here.
		$this->assertSame(
			['guardianMessage' => 'messageThread', 'eventRsvp' => 'schoolEvent', 'eventSignup' => 'schoolEvent', 'activitySignup' => 'activityOffer', 'activityAttendance' => 'activityOffer'],
			$globalParents
		);
		$this->assertSame(SchemaTenancy::PORTAL, SchemaTenancy::MAP['portalReport']['scope'], 'the report children hang on a portal-scoped parent');

	}//end testAParentIsDeclaredAndTheGlobalOnesAreNamed()

	public function testAGlobalSchemaStatesItsReason(): void {
		foreach (SchemaTenancy::MAP as $slug => $entry) {
			if ($entry['scope'] === SchemaTenancy::GLOBAL) {
				$this->assertGreaterThan(20, strlen((string)($entry['reason'] ?? '')), $slug.' says why it is global');
			}
		}

	}//end testAGlobalSchemaStatesItsReason()

	public function testTheCensusFailsForAnUndeclaredSchema(): void {
		// The positive control: a schema outside the map is reported, so the first test can fail.
		$schemas = array_merge(array_keys(self::$schemas), ['brandNewSchema']);

		$this->assertContains('brandNewSchema', array_diff($schemas, array_keys(SchemaTenancy::MAP)));
		$this->assertNull(SchemaTenancy::scopeOf('brandNewSchema'));
		$this->assertFalse(SchemaTenancy::isOrganisationScoped('brandNewSchema'));
		$this->assertTrue(SchemaTenancy::isOrganisationScoped('portalMessage'));

	}//end testTheCensusFailsForAnUndeclaredSchema()
}//end class
