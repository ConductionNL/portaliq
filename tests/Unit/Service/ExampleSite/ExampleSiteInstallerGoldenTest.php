<?php

/**
 * Golden test for ExampleSiteInstaller::install.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleSite;

use OCA\Portaliq\Service\ExampleSite\ExampleSiteInstaller;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteProof;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteRecord;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteRows;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCA\Portaliq\Service\PortalNameSetting;
use OCA\Portaliq\Service\PortalThemeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Every collaborator call and the install report per world, against a
 * recording made on the code before the split.
 *
 * The world is a small instance: rows per schema (with the portal slug they
 * belong to), the keys a write is refused for, the keys a write silently
 * drops, whether OpenRegister is there, the themes on offer and an earlier
 * install record.
 *
 * @covers \OCA\Portaliq\Service\ExampleSite\ExampleSiteInstaller
 * @uses   \OCA\Portaliq\Service\ExampleSite\ExampleSiteInstallRun
 */
class ExampleSiteInstallerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/example-site-installer.json';

	/**
	 * The instance's rows during one trace, per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $state = [];

	/**
	 * @return void
	 */
	public function testEveryWorldMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $world) {
			$actual[$name] = $this->trace(world: $world);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryWorldMatchesTheGolden()

	/**
	 * The key that tells objects of a schema apart, as ExampleSiteRows has it.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The key.
	 */
	private static function keyOf(string $schema, array $object): string {
		return match ($schema) {
			'menu' => (int)($object['position'] ?? 0) . ' ' . (string)($object['title'] ?? ''),
			'page' => (string)($object['route'] ?? ''),
			'form' => (string)($object['externalReference'] ?? ''),
			'glossaryTerm' => (string)($object['term'] ?? ''),
			'portal' => (string)($object['slug'] ?? ''),
			default => (string)($object['title'] ?? ''),
		};
	}//end keyOf()

	/**
	 * Install in one world; the calls in order, the report, and the rows after.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return array<string, mixed> `calls`, `report` and `rows`.
	 */
	private function trace(array $world): array {
		$log         = [];
		$this->state = ($world['rows'] ?? []);
		$created     = 0;

		$store = $this->createMock(originalClassName: ExampleSiteStore::class);
		$store->method('available')->willReturnCallback(
			static function () use (&$log, $world): bool {
				$log[] = ['store.available'];
				return ($world['available'] ?? true);
			}
		);
		$store->method('create')->willReturnCallback(
			function (string $schema, array $data) use (&$log, &$created, $world): ?string {
				$log[] = ['store.create', $schema, $data];
				$key   = self::keyOf(schema: $schema, object: $data);
				if (in_array($schema . ' ' . $key, ($world['refused'] ?? []), true) === true) {
					return null;
				}

				$created++;
				$id = $schema . '-' . $created;
				$this->state[$schema][$id] = array_diff_key($data, array_flip($world['dropped'][$schema] ?? [])) + ['id' => $id];
				if (in_array($schema . ' ' . $key, ($world['unanswered'] ?? []), true) === true) {
					return null;
				}

				return $id;
			}
		);
		$store->method('idOf')->willReturnCallback(
			static function (array $row) use (&$log): string {
				$log[] = ['store.idOf', $row['id'] ?? null];
				return (string)($row['id'] ?? '');
			}
		);

		$rows = $this->createMock(originalClassName: ExampleSiteRows::class);
		$rows->method('portal')->willReturnCallback(
			function (string $slug) use (&$log): ?array {
				$log[] = ['rows.portal', $slug];
				foreach (($this->state['portal'] ?? []) as $row) {
					if (($row['slug'] ?? null) === $slug) {
						return $row;
					}
				}

				return null;
			}
		);
		$rows->method('keyed')->willReturnCallback(
			function (string $schema, string $slug) use (&$log): array {
				$log[]  = ['rows.keyed', $schema, $slug];
				$keyed  = [];
				foreach (($this->state[$schema] ?? []) as $row) {
					if (($row['portal'] ?? null) === $slug) {
						$keyed[self::keyOf(schema: $schema, object: $row)] = $row;
					}
				}

				return $keyed;
			}
		);
		$rows->method('keyOf')->willReturnCallback(
			static fn (string $schema, array $object): string => self::keyOf(schema: $schema, object: $object)
		);

		$record = $this->createMock(originalClassName: ExampleSiteRecord::class);
		$record->method('read')->willReturnCallback(
			static function (string $site) use (&$log, $world): array {
				$log[] = ['record.read', $site];
				return ($world['record'] ?? ['portal' => '', 'menu' => [], 'page' => [], 'newsItem' => [], 'glossaryTerm' => [], 'form' => []]);
			}
		);
		$record->method('write')->willReturnCallback(
			static function (string $site, array $value) use (&$log): void {
				$log[] = ['record.write', $site, $value];
			}
		);

		$proof = $this->createMock(originalClassName: ExampleSiteProof::class);
		$proof->method('lostPaths')->willReturnCallback(
			static function (mixed $declared, mixed $stored) use (&$log): array {
				$log[] = ['proof.lostPaths', $declared, $stored];
				return array_values(array_diff(array_keys((array)$declared), array_keys((array)$stored)));
			}
		);

		$themes = $this->createMock(originalClassName: PortalThemeResolver::class);
		$themes->method('stylesheetFor')->willReturnCallback(
			static function (string $theme) use (&$log, $world): ?string {
				$log[] = ['themes.stylesheetFor', $theme];
				return ($world['themes'][$theme] ?? null);
			}
		);

		$name = $this->createMock(originalClassName: PortalNameSetting::class);
		$name->method('fillFrom')->willReturnCallback(
			static function (array $portal) use (&$log): void {
				$log[] = ['name.fillFrom', $portal];
			}
		);

		$installer = new ExampleSiteInstaller(store: $store, rows: $rows, record: $record, proof: $proof, themes: $themes, name: $name);
		try {
			$report = $installer->install(site: $world['site']);
		} catch (\Throwable $error) {
			$report = ['threw' => $error::class, 'message' => $error->getMessage()];
		}

		return ['calls' => $log, 'report' => $report, 'rows' => $this->state];
	}//end trace()

	/**
	 * A small declaration.
	 *
	 * @param array<string, mixed> $changes Overrides per list.
	 *
	 * @return array<string, mixed>
	 */
	private function site(array $changes = []): array {
		return array_replace(
			[
				'id'       => 'demo',
				'portal'   => ['slug' => 'demo', 'title' => 'Demo', 'theme' => 'rijkshuisstijl', 'headerSearch' => true],
				'forms'    => [['externalReference' => 'aanmelden', 'fields' => [['id' => 'naam']], 'status' => 'active']],
				'menus'    => [['position' => 1, 'title' => 'Hoofd', 'items' => [['link' => '/']]], ['position' => 2, 'title' => 'Voet']],
				'pages'    => [
					['route' => '/', 'title' => 'Home', 'body' => ['widgets' => [['widgetKey' => 'text', 'props' => ['text' => 'Hallo']]]]],
					['route' => '/aanmelden', 'title' => 'Aanmelden', 'body' => ['widgets' => [
						['widgetKey' => 'form', 'props' => ['formKey' => 'aanmelden', 'submitLabel' => 'Verstuur']],
						['widgetKey' => 'form', 'props' => ['formKey' => 'onbekend']],
						['widgetKey' => 'form', 'props' => ['formKey' => 12]],
						['widgetKey' => 'text', 'props' => ['formKey' => 'aanmelden']],
						['props' => ['formKey' => 'aanmelden']],
					]]],
					['route' => '/leeg', 'title' => 'Leeg'],
				],
				'news'     => [['title' => 'Nieuws 1', 'status' => 'published']],
				'glossary' => [['term' => 'Zaak', 'definition' => 'Een verzoek']],
			],
			$changes
		);
	}//end site()

	/**
	 * The worlds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$existing = [
			'portal'       => ['p-0' => ['id' => 'p-0', 'slug' => 'demo', 'title' => 'Bestaand']],
			'page'         => [
				'pg-0' => ['id' => 'pg-0', 'route' => '/', 'title' => 'Aangepast', 'portal' => 'demo'],
				'pg-x' => ['id' => 'pg-x', 'route' => '/leeg', 'portal' => 'ander'],
			],
			'form'         => ['f-0' => ['id' => 'f-0', 'externalReference' => 'aanmelden', 'portal' => 'demo']],
			'glossaryTerm' => ['g-0' => ['id' => 'g-0', 'term' => 'Zaak', 'portal' => 'demo']],
		];

		return [
			'no OpenRegister'                => ['site' => $this->site(), 'available' => false],
			'no OpenRegister, theme offered' => ['site' => $this->site(), 'available' => false, 'themes' => ['rijkshuisstijl' => 'x.css']],
			'fresh instance'                 => ['site' => $this->site(), 'themes' => ['rijkshuisstijl' => 'rhs.css']],
			'fresh, no theme set'            => ['site' => $this->site(changes: ['portal' => ['slug' => 'demo', 'title' => 'Demo']])],
			'fresh, empty theme'             => ['site' => $this->site(changes: ['portal' => ['slug' => 'demo', 'theme' => '']])],
			'second run'                     => [
				'site' => $this->site(),
				'rows' => $existing,
				'record' => ['portal' => 'p-0', 'menu' => [], 'page' => ['pg-0'], 'newsItem' => [], 'glossaryTerm' => ['g-0'], 'form' => ['f-0']],
			],
			'portal write refused'           => ['site' => $this->site(), 'refused' => ['portal demo']],
			'portal answered without id'     => ['site' => $this->site(), 'unanswered' => ['portal demo']],
			'a page refused'                 => ['site' => $this->site(), 'refused' => ['page /leeg', 'newsItem Nieuws 1']],
			'a page answered without id'     => ['site' => $this->site(), 'unanswered' => ['page /']],
			'the form refused'               => ['site' => $this->site(), 'refused' => ['form aanmelden']],
			'keys dropped'                   => [
				'site' => $this->site(),
				'dropped' => ['portal' => ['headerSearch'], 'page' => ['body'], 'menu' => ['items']],
			],
			'no forms, no glossary'          => ['site' => array_diff_key($this->site(), ['forms' => 1, 'glossary' => 1, 'news' => 1])],
			'page widgets not a list'        => [
				'site' => $this->site(
					changes: ['pages' => [['route' => '/x', 'body' => ['widgets' => 'geen']], ['route' => '/y', 'body' => 'geen']]]
				),
			],
			'a page of another portal'       => [
				'site' => $this->site(),
				'rows' => ['page' => ['pg-x' => ['id' => 'pg-x', 'route' => '/', 'portal' => 'ander']]],
			],
			'existing row edited, lost key'  => ['site' => $this->site(), 'rows' => $existing, 'dropped' => ['menu' => ['items']]],
		];
	}//end worlds()
}//end class
