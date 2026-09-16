<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of portaliq's Integrations page. Nothing in portaliq reads it at
 * runtime, so a broken file fails nowhere in this repo: integriq skips it
 * whole and the page goes empty on some other instance. Every assertion here
 * is a way that file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-001-portaliq-declares-its-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against hydra connection-registry D2 and D12.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Integriq's schema, fetched with `gh api` from integriq `development` on
	 * 2026-09-15, where the file was last changed in
	 * 64b437fc2df24827985ce6e919fe5e47c5205617 (integriq#2024). It carries the
	 * hydra#673 and hydra#676 amendments (`reportedOnly`, `adapter.jsonPath`,
	 * `adapter.simulatedValues`, `{configKey, jsonPath}` in `requiredConfig`)
	 * and the hydra#677 one (`switch`, `disabledMessage`).
	 *
	 * @var string
	 */
	private const SCHEMA = '/tests/Fixtures/Integriq/connections.schema.json';

	/**
	 * The keys the file declares, in declared order.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_KEYS = ['geo-db', 'oidc'];

	/**
	 * The admin panel the settings links land in.
	 *
	 * @var string
	 */
	private const ADMIN_PANEL = '/src/views/AdminRoot.vue';

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[(string) $connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The vendored schema.
	 *
	 * @return string
	 */
	private function schema(): string {
		$schema = file_get_contents($this->root() . self::SCHEMA);
		$this->assertIsString(actual: $schema);

		return $schema;
	}//end schema()

	/**
	 * The file validates against integriq's JSON Schema.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$result = (new Validator())->validate(json_decode($this->raw()), $this->schema());

		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue(condition: $result->isValid(), message: (string) json_encode($errors, JSON_PRETTY_PRINT));
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The schema check can fail: a misspelled field is refused.
	 *
	 * Without this control a validator that accepts everything would pass the
	 * test above as well.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAnUnknownField(): void {
		$declaration = json_decode($this->raw());
		$declaration->connections[0]->setingsUrl = '/settings/admin/portaliq#section-visitor-geography';

		$this->assertFalse(condition: (new Validator())->validate($declaration, $this->schema())->isValid());
	}//end testTheSchemaRefusesAnUnknownField()

	/**
	 * The file names the app it ships in.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		// Read the bytes, not the path. Nextcloud's lib/base.php installs an external
		// entity loader that returns null, and libxml routes the primary document
		// through it too, so simplexml_load_file() returns false for a valid file in CI.
		$infoRaw = (string) file_get_contents($this->root() . '/appinfo/info.xml');
		$infoXml = simplexml_load_string($infoRaw);

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string) $infoXml->id, actual: $this->declaration()['app']);
		$this->assertSame(expected: 'portaliq', actual: $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique and in rising order.
	 *
	 * A row is keyed by app and key, so a second entry with the same key would
	 * overwrite the first.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueAndOrdered(): void {
		$connections = $this->declaration()['connections'];
		$keys        = array_column($connections, 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys, message: 'a key is declared twice');
		$this->assertSame(expected: self::DECLARED_KEYS, actual: $keys);

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $orders);
		$this->assertCount(expectedCount: count($keys), haystack: array_unique($orders));
	}//end testTheKeysAreUniqueAndOrdered()

	/**
	 * No text a reader sees carries an em-dash or a Title Case title (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextBreaksTheVoiceRules(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: '--', haystack: $this->raw());

		foreach ($this->declaration()['connections'] as $connection) {
			$words = explode(' ', (string) $connection['title']);
			foreach (array_slice($words, 1) as $word) {
				$this->assertSame(expected: mb_strtolower($word), actual: $word, message: $connection['key'] . ' title is not sentence case');
			}
		}
	}//end testNoTextBreaksTheVoiceRules()

	/**
	 * Every settings link lands on one element id in the admin panel.
	 *
	 * The admin section is `portaliq` (AdminSettings::getSection()). An anchor
	 * no element carries opens the settings page at the top and logs nothing.
	 * A copy of the link inside a comment does not count as the element.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingSection(): void {
		$panel = (string) file_get_contents($this->root() . self::ADMIN_PANEL);
		$admin = (string) file_get_contents($this->root() . '/lib/Settings/AdminSettings.php');
		$this->assertMatchesRegularExpression(pattern: "/return 'portaliq';/", string: $admin);

		$linked = [];
		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string) $connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/settings/admin/portaliq#section-', string: $url, message: $connection['key']);
			$anchor = substr($url, ((int) strpos($url, '#') + 1));
			$this->assertSame(
				expected: 1,
				actual: preg_match_all('/\bid="' . preg_quote($anchor, '/') . '"/', $panel),
				message: $connection['key'] . ' links to #' . $anchor . ', and the admin panel does not carry that id once'
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(expected: self::DECLARED_KEYS, actual: $linked);
	}//end testEverySettingsLinkPointsAtAnExistingSection()

	/**
	 * Both rows are reported only, with nothing for integriq to guess a status from.
	 *
	 * `none` switches geography off rather than faking it, and an unset
	 * provider key is the DB-IP default, so an adapter row would read a real
	 * provider as simulated. The switch says off; only portaliq can say the
	 * database is installed. The brokers are configured per organisation, so
	 * no key list can say they are filled.
	 *
	 * @return void
	 */
	public function testBothRowsAreReportedOnly(): void {
		foreach ($this->connectionsByKey() as $key => $connection) {
			$this->assertTrue(condition: $connection['reportedOnly'] ?? false, message: $key);
			$this->assertArrayNotHasKey(key: 'adapter', array: $connection, message: $key);
			$this->assertArrayNotHasKey(key: 'requiredConfig', array: $connection, message: $key);
			$this->assertStringStartsWith(prefix: 'Not checked yet.', string: (string) ($connection['unconfiguredMessage'] ?? ''), message: $key);
		}
	}//end testBothRowsAreReportedOnly()

	/**
	 * Provider `none` switches the geography row off, and an unset provider does not.
	 *
	 * An unset `traffic.geo.provider` is the DB-IP default, so the switch lists
	 * `none` as its only off value (hydra connection-registry D2, D12 item 9).
	 * Without `offValues`, integriq would read the unset key as off and hide a
	 * working database behind Switched off. The brokers have no switch.
	 *
	 * @return void
	 */
	public function testOnlyProviderNoneSwitchesGeographyOff(): void {
		$connections = $this->connectionsByKey();
		$switch      = $connections['geo-db']['switch'] ?? null;

		$this->assertSame(
			expected: ['configKey' => GeoSettings::KEY_PROVIDER, 'offValues' => ['none']],
			actual: $switch
		);
		$this->assertSame(expected: 'traffic.geo.provider', actual: GeoSettings::KEY_PROVIDER);

		$offValues = array_map(static fn (string $value): string => mb_strtolower(trim($value)), $switch['offValues']);
		foreach (['', GeoSettings::DEFAULT_PROVIDER, ...array_diff(GeoSettings::PROVIDERS, ['none'])] as $working) {
			$this->assertNotContains(needle: $working, haystack: $offValues, message: "'" . $working . "' must not read as off");
		}

		$this->assertSame(
			expected: 'Geography is switched off. No database is fetched and no region is stored.',
			actual: $connections['geo-db']['disabledMessage'] ?? null
		);
		$this->assertArrayNotHasKey(key: 'switch', array: $connections['oidc']);
	}//end testOnlyProviderNoneSwitchesGeographyOff()

	/**
	 * The geography facts the declaration relies on still hold in the code.
	 *
	 * If `none` ever becomes a mock resolver, or the default stops being a
	 * real provider, the row should be declared again.
	 *
	 * @return void
	 */
	public function testTheGeographyProviderFactsStillHold(): void {
		$settings = (string) file_get_contents($this->root() . '/lib/Service/Traffic/Geo/GeoSettings.php');
		$app      = (string) file_get_contents($this->root() . '/lib/AppInfo/Application.php');

		$this->assertStringContainsString(needle: "DEFAULT_PROVIDER = 'dbip'", haystack: $settings);
		$this->assertStringContainsString(needle: "PROVIDERS = ['none', 'dbip', 'maxmind']", haystack: $settings);
		$this->assertStringContainsString(
			needle: 'registerServiceAlias(GeoResolverInterface::class, MmdbGeoResolver::class)',
			haystack: $app
		);
		$this->assertStringNotContainsString(needle: 'NullGeoResolver::class)', haystack: $app);
	}//end testTheGeographyProviderFactsStillHold()
}//end class
