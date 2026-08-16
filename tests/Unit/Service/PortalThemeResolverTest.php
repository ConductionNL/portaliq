<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Theme reference -> token stylesheet.
 *
 * The decision this class makes is which brand a tenant's portal wears, and
 * the dangerous outcome is not an error — it is a portal rendering correctly
 * in SOMEBODY ELSE'S colours. That failure passes every screenshot review, so
 * the refusals are asserted here rather than left to a visual check.
 */
class PortalThemeResolverTest extends TestCase {

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	private string $themeRoot;


	/**
	 * Build a throwaway theme app directory with two real token files.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);

		$this->themeRoot = sys_get_temp_dir() . '/pq-theme-' . bin2hex(random_bytes(6));
		mkdir($this->themeRoot . '/css/tokens', 0o777, true);
		file_put_contents($this->themeRoot . '/css/tokens/vng.css', ':root{--nldesign-color-text:#333}');
		file_put_contents($this->themeRoot . '/css/tokens/venray.css', ':root{}');
	}//end setUp()


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		// Only what this test created, and only under the system temp dir.
		foreach (glob($this->themeRoot . '/css/tokens/*.css') ?: [] as $file) {
			unlink($file);
		}

		@rmdir($this->themeRoot . '/css/tokens');
		@rmdir($this->themeRoot . '/css');
		@rmdir($this->themeRoot);
	}//end tearDown()


	/**
	 * A resolver with the theme app installed at the fixture root.
	 *
	 * @return PortalThemeResolver The resolver.
	 */
	private function resolver(): PortalThemeResolver {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('getAppPath')->willReturn($this->themeRoot);

		return new PortalThemeResolver(appManager: $this->appManager);
	}//end resolver()


	/**
	 * The positive control. Without it every refusal below is satisfied by a
	 * resolver that returns null unconditionally.
	 *
	 * @return void
	 */
	public function testAShippedThemeResolvesToItsStylesheet(): void {
		$this->assertSame('tokens/vng', $this->resolver()->stylesheetFor(theme: 'vng'));
		$this->assertSame('tokens/venray', $this->resolver()->stylesheetFor(theme: 'venray'));
	}//end testAShippedThemeResolvesToItsStylesheet()


	/**
	 * A theme naming no shipped file resolves to NOTHING — not to a default.
	 * Falling back would render this tenant in another tenant's brand, which
	 * looks entirely correct and is the failure nobody reports.
	 *
	 * @return void
	 */
	public function testAnUnknownThemeResolvesToNullRatherThanADefault(): void {
		$this->assertNull($this->resolver()->stylesheetFor(theme: 'no-such-municipality'));
	}//end testAnUnknownThemeResolvesToNullRatherThanADefault()


	/**
	 * THIS APP SHIPS NO TOKENS AT ALL — for any theme, known or not.
	 *
	 * The assertion inverted, deliberately. It used to be the positive control
	 * for `nldsStylesheetFor()`, expecting `themes/vng`, because this app
	 * carried its own copy of the VNG token set: 600 `--utrecht-*` and 254
	 * `--tilburg-*`, vendored from the reference implementation.
	 *
	 * That was a SECOND source of truth for a theme `nldesign` already owns,
	 * and the two halves were measured to have ZERO tokens in common — the
	 * portal was styled from here while nldesign's `utrecht-bridge.css` ran
	 * every one of its 88 inputs on fallbacks. The set now lives in
	 * `nldesign/css/tokens/<theme>.css` and `stylesheetFor()` resolves it.
	 *
	 * A KNOWN theme is asserted alongside an unknown one on purpose: "returns
	 * null because the theme is unknown" and "returns null because this app
	 * ships nothing" are different claims, and only the second one is true now.
	 *
	 * @return void
	 */
	public function testThisAppShipsNoTokenSetOfItsOwn(): void {
		$this->assertNull($this->resolver()->nldsStylesheetFor(theme: 'vng'));
		$this->assertNull($this->resolver()->nldsStylesheetFor(theme: 'venray'));
	}//end testThisAppShipsNoTokenSetOfItsOwn()


	/**
	 * The theme app's OWN token set still resolves — that is where styling
	 * comes from now, and this is the positive control for it.
	 *
	 * Without this, every null-returning assertion in this class would be
	 * satisfied by a resolver that resolved nothing at all, and a portal would
	 * render unstyled while the suite stayed green.
	 *
	 * @return void
	 */
	public function testTheThemeAppsTokenSetIsWhatResolves(): void {
		$this->assertSame('tokens/vng', $this->resolver()->stylesheetFor(theme: 'vng'));
	}//end testTheThemeAppsTokenSetIsWhatResolves()


	/**
	 * A theme this app ships no token set for resolves to NOTHING.
	 *
	 * Same posture as `stylesheetFor()`: never fall back to another theme's
	 * tokens, because a portal wearing another municipality's colours looks
	 * correct in every screenshot.
	 *
	 * @return void
	 */
	public function testAThemeWithoutAnNldsTokenSetResolvesToNull(): void {
		$this->assertNull($this->resolver()->nldsStylesheetFor(theme: 'no-such-municipality'));
	}//end testAThemeWithoutAnNldsTokenSetResolvesToNull()


	/**
	 * The NLDS path is built by concatenation too, so it takes the same
	 * traversal refusal as `stylesheetFor()`. Asserted separately rather than
	 * assumed: the two methods share a guard today, and a later refactor that
	 * split them would leave this one open with nothing to notice.
	 *
	 * @return void
	 */
	public function testATraversalAttemptIsRefusedForNldsToo(): void {
		foreach (
			[
				'../../../../etc/passwd',
				'vng/../../../secret',
				'../vng',
				'',
			] as $hostile
		) {
			$this->assertNull(
				$this->resolver()->nldsStylesheetFor(theme: $hostile),
				"nldsStylesheetFor() accepted a hostile theme reference: {$hostile}"
			);
		}
	}//end testATraversalAttemptIsRefusedForNldsToo()


	/**
	 * The value is editor-controlled and gets concatenated into a path.
	 *
	 * @return void
	 */
	public function testATraversalAttemptIsRefused(): void {
		foreach (
			[
				'../../../../etc/passwd',
				'vng/../../../secret',
				'../vng',
				'vng.css',
				'VNG',
				'vng ',
				'',
			] as $hostile
		) {
			$this->assertNull(
				$this->resolver()->stylesheetFor(theme: $hostile),
				sprintf('%s must not resolve', var_export($hostile, true))
			);
		}
	}//end testATraversalAttemptIsRefused()


	/**
	 * A Portaliq with no theme app installed is a normal deployment: it
	 * renders unthemed rather than failing to render.
	 *
	 * @return void
	 */
	public function testAnAbsentThemeAppResolvesToNull(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		$resolver = new PortalThemeResolver(appManager: $appManager);

		$this->assertNull($resolver->stylesheetFor(theme: 'vng'));
	}//end testAnAbsentThemeAppResolvesToNull()


	/**
	 * A theme app that throws while being located must not take the portal
	 * down with it — an unthemed page is still a working page.
	 *
	 * @return void
	 */
	public function testAThrowingAppManagerDegradesToUnthemed(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->willThrowException(new \RuntimeException('gone'));

		$resolver = new PortalThemeResolver(appManager: $appManager);

		$this->assertNull($resolver->stylesheetFor(theme: 'vng'));
	}//end testAThrowingAppManagerDegradesToUnthemed()


}//end class
