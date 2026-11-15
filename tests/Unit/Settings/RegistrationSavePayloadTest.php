<?php

/**
 * The portal the Registration widget writes back fits the real portal schema.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The Registration widget on a portal's page (identity-staff-account-screens
 * T07) writes the whole portal back through OpenRegister with
 * `authentication.registration` set. `tests/staff-account-screens.spec.mjs`
 * asserts the widget's module produces exactly `tests/fixtures/registration-save.json`;
 * this test validates that same body against the real `portal` schema, so the
 * write cannot pass its own test and be refused by the register.
 *
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
 */
class RegistrationSavePayloadTest extends TestCase {

	/**
	 * The saved portal fits the portal schema.
	 *
	 * @return void
	 */
	public function testTheSavedPortalFitsThePortalSchema(): void {
		$body = json_decode((string)file_get_contents(__DIR__.'/../../fixtures/registration-save.json'), true);
		$this->assertTrue($this->fitsPortal($body), 'the saved portal fits the register schema');
	}//end testTheSavedPortalFitsThePortalSchema()

	/**
	 * A policy outside the enum is refused, so the validator reads the fragment.
	 *
	 * @return void
	 */
	public function testAPolicyOutsideTheSchemaIsRefused(): void {
		$body = json_decode((string)file_get_contents(__DIR__.'/../../fixtures/registration-save.json'), true);
		$body['authentication']['registration']['policy'] = 'open';
		$this->assertFalse($this->fitsPortal($body), 'an unknown policy does not fit');
	}//end testAPolicyOutsideTheSchemaIsRefused()

	/**
	 * Whether a portal body fits the real `portal` schema.
	 *
	 * @param array<string, mixed> $row The body.
	 *
	 * @return bool
	 */
	private function fitsPortal(array $row): bool {
		unset($row['id'], $row['uuid']);
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/portaliq_register.json'), true);
		$schema = $register['components']['schemas']['portal'];
		$jsonSchema = json_decode(
			(string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]),
			false
		);
		return (new Validator())->validate(json_decode((string)json_encode($row), false), $jsonSchema)->isValid();
	}//end fitsPortal()
}//end class
