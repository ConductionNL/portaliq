<?php

/**
 * Unit tests for InitializeDemoPortal.
 *
 * WHAT IS WORTH ASSERTING ABOUT AN INSTALL HOOK THAT WRITES CONTENT
 * -----------------------------------------------------------------
 * The happy path is the least interesting thing here: if it seeds a portal,
 * the next `occ app:install` shows it. What has to be tested is everything
 * that must NOT happen —
 *
 *   - it must not run twice, and the marker rather than the object count is
 *     what makes that true, because `countObjects()` answers 0 both when the
 *     instance is empty and when the count THREW;
 *   - it must not touch an instance that already has portals;
 *   - it must not fail the install when OpenRegister is not ready, because a
 *     repair step that throws aborts `occ app:install` and leaves the app not
 *     installed at all;
 *   - the pages it writes must reference the portal by SLUG, since CmsReader
 *     scopes content by slug and an id there matches nothing while every
 *     object involved looks correct.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Repair\InitializeDemoPortal;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the demo-portal install hook.
 */
class InitializeDemoPortalTest extends TestCase {

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @param PortalObjectWriter $writer The writer double.
	 * @param IAppConfig $config The config double.
	 *
	 * @return InitializeDemoPortal The step under test.
	 */
	private function step(PortalObjectWriter $writer, IAppConfig $config): InitializeDemoPortal {
		return new InitializeDemoPortal(
			$writer,
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end step()


	/**
	 * A config double answering a fixed map of keys.
	 *
	 * @param array<string, string> $values Key → value.
	 *
	 * @return IAppConfig The double.
	 */
	private function config(array $values = []): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($values): string {
					return ($values[$key] ?? $default);
				}
			);

		return $config;
	}//end config()


	/**
	 * A writer that reports N existing portals and records every write.
	 *
	 * @param integer $existing Portals already present.
	 * @param array   $writes   Collected writes, by reference.
	 *
	 * @return PortalObjectWriter The double.
	 */
	private function writer(int $existing, array &$writes): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('countObjects')->willReturn($existing);
		$writer->method('createAnonymousObject')
			->willReturnCallback(
				static function (string $register, string $schema, array $data) use (&$writes): array {
					$writes[] = ['schema' => $schema, 'data' => $data];
					return ['@self' => ['id' => 'seeded-' . $schema . '-' . count($writes)]];
				}
			);

		return $writer;
	}//end writer()


	/**
	 * @return IOutput A silent output channel.
	 */
	private function silentOutput(): IOutput {
		return $this->createMock(IOutput::class);
	}//end silentOutput()


	/**
	 * On a fresh instance it seeds a portal, three pages and the legal menu.
	 *
	 * @return void
	 */
	public function testSeedsThePortalAndItsPagesOnAFreshInstance(): void {
		$writes = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())->method('setValueString');

		$this->step($this->writer(0, $writes), $config)->run($this->silentOutput());

		$schemas = array_column($writes, 'schema');
		$this->assertSame(['portal', 'page', 'page', 'menu', 'page'], $schemas);

		$routes = [];
		foreach ($writes as $write) {
			if ($write['schema'] === 'page') {
				$routes[] = $write['data']['route'];
			}
		}

		// Named individually rather than counted: a count of three passes while
		// the wrong three routes are seeded.
		$this->assertSame(['/', '/publicatie', '/zoeken'], $routes);

	}//end testSeedsThePortalAndItsPagesOnAFreshInstance()


	/**
	 * Every page references the portal by SLUG, never by id.
	 *
	 * CmsReader filters content on the slug. A page carrying the portal's UUID
	 * matches nothing, and the portal then renders its own header and footer
	 * around a 404 — with every object present, published and linked to a real
	 * id. This assertion is the cheapest place to catch that.
	 *
	 * @return void
	 */
	public function testPagesReferenceThePortalBySlug(): void {
		$writes = [];
		$this->step($this->writer(0, $writes), $this->config())->run($this->silentOutput());

		foreach ($writes as $write) {
			if ($write['schema'] === 'portal') {
				$this->assertSame('demo', $write['data']['slug']);
				continue;
			}

			$this->assertSame(
				'demo',
				$write['data']['portal'],
				$write['schema'] . ' must reference the portal by slug, not by id'
			);
		}

	}//end testPagesReferenceThePortalBySlug()


	/**
	 * The seeded portal claims NO domain.
	 *
	 * A portal serves a hostname only when that domain is marked verified, and
	 * marking one here would be this hook asserting control of a hostname on
	 * the operator's behalf — exactly what that flag exists to prevent.
	 *
	 * @return void
	 */
	public function testSeedsNoVerifiedDomain(): void {
		$writes = [];
		$this->step($this->writer(0, $writes), $this->config())->run($this->silentOutput());

		$this->assertSame([], $writes[0]['data']['domains']);

	}//end testSeedsNoVerifiedDomain()


	/**
	 * `demo_portal=no` switches the step off entirely.
	 *
	 * @return void
	 */
	public function testDisabledByConfigurationWritesNothing(): void {
		$writes = [];
		$writer = $this->writer(0, $writes);
		$writer->expects($this->never())->method('createAnonymousObject');

		$this->step($writer, $this->config(['demo_portal' => 'no']))->run($this->silentOutput());

		$this->assertSame([], $writes);

	}//end testDisabledByConfigurationWritesNothing()


	/**
	 * The marker refuses a second run even when the count says zero.
	 *
	 * THE COUNT CANNOT DISTINGUISH failure from emptiness: `countObjects()`
	 * catches Throwable and answers 0 either way, and 0 is the answer that
	 * permits a write. So the marker is given the count that would otherwise
	 * green-light a duplicate seed.
	 *
	 * @return void
	 */
	public function testMarkerRefusesASecondRunEvenWhenTheCountIsZero(): void {
		$writes = [];
		$writer = $this->writer(0, $writes);
		$writer->expects($this->never())->method('createAnonymousObject');

		$this->step($writer, $this->config(['demo_portal_provisioned' => 'some-portal-id']))
			->run($this->silentOutput());

		$this->assertSame([], $writes);

	}//end testMarkerRefusesASecondRunEvenWhenTheCountIsZero()


	/**
	 * An instance that already has portals is left alone.
	 *
	 * @return void
	 */
	public function testExistingPortalsAreNeverTouched(): void {
		$writes = [];
		$writer = $this->writer(3, $writes);
		$writer->expects($this->never())->method('createAnonymousObject');

		$this->step($writer, $this->config())->run($this->silentOutput());

		$this->assertSame([], $writes);

	}//end testExistingPortalsAreNeverTouched()


	/**
	 * A failed portal write warns and returns — it never throws.
	 *
	 * A repair step that throws aborts `occ app:install`, so an instance where
	 * OpenRegister is not ready yet would end up with the app NOT INSTALLED —
	 * trading a cosmetic problem for a total one.
	 *
	 * @return void
	 */
	public function testAFailedWriteDoesNotAbortTheInstall(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('countObjects')->willReturn(0);
		$writer->method('createAnonymousObject')->willReturn(null);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		// The marker must NOT be stamped: a half-provisioned instance has to
		// stay eligible for the re-run that would complete it.
		$config->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$this->step($writer, $config)->run($output);

	}//end testAFailedWriteDoesNotAbortTheInstall()


	/**
	 * The search page places the federated search block; the detail page
	 * places the detail block.
	 *
	 * Asserted by widget key rather than by page count, because a page that
	 * exists and places nothing renders a portal's chrome around an empty
	 * article.
	 *
	 * @return void
	 */
	public function testPagesPlaceTheBlocksTheyExistFor(): void {
		$writes = [];
		$this->step($this->writer(0, $writes), $this->config())->run($this->silentOutput());

		$byRoute = [];
		foreach ($writes as $write) {
			if ($write['schema'] === 'page') {
				$byRoute[$write['data']['route']] = array_column(
					$write['data']['body']['widgets'],
					'widgetKey'
				);
			}
		}

		$this->assertSame(['hero', 'markdown'], $byRoute['/']);
		$this->assertSame(['federatedSearch'], $byRoute['/zoeken']);
		$this->assertSame(['publicationDetail'], $byRoute['/publicatie']);

	}//end testPagesPlaceTheBlocksTheyExistFor()


	/**
	 * The legal strip sits at position 2.
	 *
	 * Position is a contract in the renderer — 0 header, 1 footer column, 2+
	 * legal strip — so a menu seeded at any other position renders in the
	 * wrong band.
	 *
	 * @return void
	 */
	public function testTheLegalMenuSitsInTheSubFooter(): void {
		$writes = [];
		$this->step($this->writer(0, $writes), $this->config())->run($this->silentOutput());

		$menus = array_values(
			array_filter($writes, static fn (array $w): bool => ($w['schema'] === 'menu'))
		);

		$this->assertCount(1, $menus);
		$this->assertSame(2, $menus[0]['data']['position']);
		$this->assertSame(
			['Cookies', 'Privacy', 'Disclaimer'],
			array_column($menus[0]['data']['items'], 'name')
		);

	}//end testTheLegalMenuSitsInTheSubFooter()


	/**
	 * A writer whose Nth write returns null, every other write succeeding.
	 *
	 * Failure is addressed by ORDINAL rather than by schema because three of
	 * the five writes share the schema `page`, and a double keyed on the
	 * schema could not tell the home page's failure from the search page's —
	 * which is exactly the distinction these tests exist to draw.
	 *
	 * @param integer $failAt Which write (1-based) returns null.
	 * @param array   $writes Collected writes, by reference.
	 *
	 * @return PortalObjectWriter The double.
	 */
	private function writerFailingAt(int $failAt, array &$writes): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('countObjects')->willReturn(0);
		$writer->method('createAnonymousObject')
			->willReturnCallback(
				static function (string $register, string $schema, array $data) use (&$writes, $failAt): ?array {
					$writes[] = ['schema' => $schema, 'data' => $data];
					if (count($writes) === $failAt) {
						return null;
					}

					return ['@self' => ['id' => 'seeded-' . $schema . '-' . count($writes)]];
				}
			);

		return $writer;
	}//end writerFailingAt()


	/**
	 * A portal that comes back without an id stops before any page is written.
	 *
	 * A write can succeed and still answer a body this step cannot use. Pages
	 * reference the portal, so continuing here would seed pages belonging to
	 * nothing — content that exists, resolves, and renders for no portal.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAnIdStopsBeforeWritingPages(): void {
		$writes = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('countObjects')->willReturn(0);
		$writer->method('createAnonymousObject')
			->willReturnCallback(
				static function (string $register, string $schema, array $data) use (&$writes): array {
					$writes[] = ['schema' => $schema, 'data' => $data];
					// Written, but carrying neither `@self.id` nor `id`.
					return ['@self' => []];
				}
			);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$this->step($writer, $config)->run($output);

		$this->assertCount(1, $writes, 'only the portal write should have been attempted');
		$this->assertSame('portal', $writes[0]['schema']);

	}//end testAPortalWithoutAnIdStopsBeforeWritingPages()


	/**
	 * When the home page fails, the marker stays unstamped.
	 *
	 * The marker is what makes the step refuse a second run, so stamping it
	 * for a portal that has no home page would make the incomplete state
	 * PERMANENT — the re-run that would have finished the job is the very
	 * thing the marker turns away.
	 *
	 * @return void
	 */
	public function testAFailedHomePageLeavesTheMarkerUnstamped(): void {
		$writes = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		// Write 1 is the portal, write 2 is the home page.
		$this->step($this->writerFailingAt(2, $writes), $config)->run($output);

		$this->assertCount(2, $writes, 'it should stop at the failed home page');

	}//end testAFailedHomePageLeavesTheMarkerUnstamped()


	/**
	 * When the search page fails, the marker stays unstamped.
	 *
	 * This is the failure that matters most and is the least visible: the
	 * portal, its landing page and its menu all exist, so the install looks
	 * successful and the site renders — with no search. Stamping the marker
	 * here would make a portal whose entire purpose is federated search
	 * permanently searchless.
	 *
	 * @return void
	 */
	public function testAFailedSearchPageLeavesTheMarkerUnstamped(): void {
		$writes = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		// Writes 1-4 are portal, home, detail and menu; write 5 is search.
		$this->step($this->writerFailingAt(5, $writes), $config)->run($output);

		$this->assertCount(5, $writes, 'every earlier write should still have happened');

	}//end testAFailedSearchPageLeavesTheMarkerUnstamped()


	/**
	 * A completed run stamps the marker with the portal's id.
	 *
	 * Asserted on the VALUE, not merely that it was called: the marker doubles
	 * as the pointer to what was seeded, and a marker holding the empty string
	 * would still suppress the re-run while naming nothing.
	 *
	 * @return void
	 */
	public function testACompletedRunStampsTheMarkerWithThePortalId(): void {
		$writes = [];

		$stamped = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$stamped): bool {
					$stamped[$key] = $value;
					return true;
				}
			);

		$this->step($this->writer(0, $writes), $config)->run($this->silentOutput());

		$this->assertArrayHasKey('demo_portal_provisioned', $stamped);
		$this->assertSame('seeded-portal-1', $stamped['demo_portal_provisioned']);

	}//end testACompletedRunStampsTheMarkerWithThePortalId()

}//end class
