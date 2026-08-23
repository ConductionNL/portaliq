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
	 * Build a throwaway theme app directory: a catalogue, two real token files
	 * and one generated dark variant.
	 *
	 * The CATALOGUE is part of the fixture because resolution now asks it, not
	 * only the filesystem. `orphan.css` exists deliberately and is NOT listed —
	 * a stray `.css` beside the catalogued sets (a work in progress, a leftover)
	 * is not a theme a portal may adopt, and a bare `is_file()` cannot tell the
	 * difference.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);

		$this->themeRoot = sys_get_temp_dir() . '/pq-theme-' . bin2hex(random_bytes(6));
		mkdir($this->themeRoot . '/css/tokens/dark', 0o777, true);
		file_put_contents($this->themeRoot . '/css/tokens/vng.css', ':root{--nldesign-color-text:#333}');
		file_put_contents($this->themeRoot . '/css/tokens/venray.css', ':root{}');
		file_put_contents($this->themeRoot . '/css/tokens/orphan.css', ':root{}');
		file_put_contents($this->themeRoot . '/css/tokens/dark/vng.css', '@media (prefers-color-scheme: dark){:root{}}');
		file_put_contents(
			$this->themeRoot . '/token-sets.json',
			(string)json_encode([
				['id' => 'vng', 'name' => 'VNG'],
				['id' => 'venray', 'name' => 'Venray'],
			])
		);
	}//end setUp()


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		// Only what this test created, and only under the system temp dir.
		foreach (glob($this->themeRoot . '/css/tokens/dark/*.css') ?: [] as $file) {
			unlink($file);
		}

		foreach (glob($this->themeRoot . '/css/tokens/*.css') ?: [] as $file) {
			unlink($file);
		}

		@unlink($this->themeRoot . '/token-sets.json');
		@rmdir($this->themeRoot . '/css/tokens/dark');
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
	 * The theme app is mid-rename from `nldesign` to `thematiq`: the new id is
	 * live on `development` while released builds still ship the old one. Both
	 * must resolve, because `isInstalled()` on a name nothing answers to
	 * returns false rather than raising — so a resolver pinned to one id does
	 * not fail loudly when it goes stale, it renders the portal UNTHEMED.
	 *
	 * These are the tests that tell the ids apart. The rest of this class stubs
	 * `isInstalled()` to true for any argument, so they pass under either name
	 * and would not notice the constant going stale.
	 *
	 * @return void
	 */
	public function testTheThemeAppResolvesUnderItsRenamedId(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app === 'thematiq'
		);
		$appManager->method('getAppPath')->willReturn($this->themeRoot);

		$resolver = new PortalThemeResolver(appManager: $appManager);

		$this->assertSame('thematiq', $resolver->themeAppId());
		$this->assertNotNull($resolver->stylesheetFor(theme: 'vng'));
	}//end testTheThemeAppResolvesUnderItsRenamedId()


	/**
	 * The other half of the rename: an instance still on the old id.
	 *
	 * @return void
	 */
	public function testTheThemeAppStillResolvesUnderItsFormerId(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app === 'nldesign'
		);
		$appManager->method('getAppPath')->willReturn($this->themeRoot);

		$resolver = new PortalThemeResolver(appManager: $appManager);

		$this->assertSame('nldesign', $resolver->themeAppId());
		$this->assertNotNull($resolver->stylesheetFor(theme: 'vng'));
	}//end testTheThemeAppStillResolvesUnderItsFormerId()


	/**
	 * An unrelated app being installed must not be mistaken for the theme app.
	 * Without this, a resolver that answered "installed" to anything would
	 * satisfy both tests above.
	 *
	 * @return void
	 */
	public function testAnUnrelatedAppIsNotMistakenForTheThemeApp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app === 'openregister'
		);

		$resolver = new PortalThemeResolver(appManager: $appManager);

		$this->assertNull($resolver->themeAppId());
		$this->assertNull($resolver->stylesheetFor(theme: 'vng'));
	}//end testAnUnrelatedAppIsNotMistakenForTheThemeApp()


	/**
	 * One candidate id throwing must not decide the answer for the others.
	 * `isInstalled()` raises on some ids on some server versions, and asking
	 * about the NEW name first means the old, working one sits behind it — a
	 * single unhandled throw there would render every portal unthemed.
	 *
	 * @return void
	 */
	public function testACandidateThatThrowsDoesNotHideTheNextOne(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static function (string $app): bool {
				if ($app === 'thematiq') {
					throw new \RuntimeException('no such app');
				}

				return $app === 'nldesign';
			}
		);
		$appManager->method('getAppPath')->willReturn($this->themeRoot);

		$resolver = new PortalThemeResolver(appManager: $appManager);

		$this->assertSame('nldesign', $resolver->themeAppId());
		$this->assertNotNull($resolver->stylesheetFor(theme: 'vng'));
	}//end testACandidateThatThrowsDoesNotHideTheNextOne()


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


	/**
	 * A `.css` that the catalogue does not list is NOT a theme.
	 *
	 * `orphan.css` exists on disk in the fixture. A bare `is_file()` check —
	 * which is what this resolver used to do — accepts it, and a portal could
	 * then adopt a generated variant, a work in progress or a leftover as if it
	 * were an offered theme. The theme app's catalogue is what says which sets
	 * it offers.
	 *
	 * @return void
	 */
	public function testAFileNotListedInTheCatalogueDoesNotResolve(): void {
		$this->assertFileExists($this->themeRoot . '/css/tokens/orphan.css');
		$this->assertNull($this->resolver()->stylesheetFor(theme: 'orphan'));
	}//end testAFileNotListedInTheCatalogueDoesNotResolve()


	/**
	 * A catalogued set with NO file on disk does not resolve either.
	 *
	 * Both halves are checked because they fail differently: this one means a
	 * broken install, and emitting a link that 404s is indistinguishable on
	 * screen from having no theme at all.
	 *
	 * @return void
	 */
	public function testACataloguedSetWithNoFileDoesNotResolve(): void {
		file_put_contents(
			$this->themeRoot . '/token-sets.json',
			(string)json_encode([['id' => 'ghost', 'name' => 'Ghost']])
		);

		$this->assertNull($this->resolver()->stylesheetFor(theme: 'ghost'));
	}//end testACataloguedSetWithNoFileDoesNotResolve()


	/**
	 * An unreadable catalogue resolves NOTHING rather than falling back.
	 *
	 * @return void
	 */
	public function testAMissingCatalogueFailsClosed(): void {
		unlink($this->themeRoot . '/token-sets.json');
		$this->assertNull($this->resolver()->stylesheetFor(theme: 'vng'));
	}//end testAMissingCatalogueFailsClosed()


	/**
	 * THE DARK VARIANT IS NOT RESOLVED, AND THAT IS THE DECISION UNDER TEST.
	 *
	 * The theme app generates `css/tokens/dark/<id>.css`; the fixture has one
	 * for `vng`. Serving it is one line, it was written, and it was measured on
	 * a live portal twice:
	 *
	 *   before the theme app was fixed  0 of 1,152,000 pixels changed — the
	 *                                   artefact rewrote `--nldesign-color-*`
	 *                                   and the page is painted from
	 *                                   `--utrecht-*`
	 *   after                           53% of pixels changed and 10 of 11 text
	 *                                   nodes fell below 4.5:1 — #e5e5e5
	 *                                   headings on white bands, ratio 1.26
	 *
	 * The site has no token-driven surface layer, so darkening the text without
	 * the surfaces is worse than no dark mode. This test exists so that adding
	 * the one line back is a deliberate act with a red test attached, rather
	 * than an obvious-looking improvement.
	 *
	 * @return void
	 */
	public function testTheDarkVariantIsDeliberatelyNotResolved(): void {
		$this->assertFileExists($this->themeRoot . '/css/tokens/dark/vng.css');
		$this->assertFalse(
			method_exists($this->resolver(), 'darkStylesheetFor'),
			'A dark variant must not be resolvable until the site paints its surfaces from tokens '
			. '— see templates/site.php for the measurements.'
		);
	}//end testTheDarkVariantIsDeliberatelyNotResolved()




	/**
	 * A set with a logo yields the path relative to the THEME APP.
	 *
	 * WHY THIS MATTERS ENOUGH TO TEST: token sets declare
	 * `--nldesign-logo-url` relative to the token file, and a browser resolves
	 * a relative `url()` inside a custom property against the stylesheet
	 * CONSUMING it — this app's bundled CSS. Measured on a live rig, the header
	 * requested `/custom_apps/portaliq/img/logos/opencatalogi.svg` and rendered
	 * no logo at all, while every token in the chain held the right value.
	 *
	 * So the caller needs a path it can turn into an absolute URL, and that is
	 * what this returns.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function testALogoResolvesToAPathInTheThemeApp(): void {
		mkdir($this->themeRoot . '/img/logos', 0o777, true);
		file_put_contents($this->themeRoot . '/img/logos/vng.svg', '<svg/>');

		$this->assertSame('img/logos/vng.svg', $this->resolver()->logoFileFor(theme: 'vng'));

	}//end testALogoResolvesToAPathInTheThemeApp()


	/**
	 * A catalogued set with NO logo file yields null, not a broken path.
	 *
	 * Emitting a path that 404s is indistinguishable on screen from having no
	 * logo, and moves the failure from somewhere checkable to somewhere only
	 * the browser sees.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function testASetWithoutALogoFileResolvesToNull(): void {
		$this->assertNull($this->resolver()->logoFileFor(theme: 'venray'));

	}//end testASetWithoutALogoFileResolvesToNull()


	/**
	 * A theme that does not resolve has no logo either.
	 *
	 * The logo must never outlive the theme: a portal rendering unthemed while
	 * still wearing another brand's mark is the confusing half-state the
	 * resolver's null-rather-than-default posture exists to avoid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function testAnUnresolvableThemeHasNoLogo(): void {
		mkdir($this->themeRoot . '/img/logos', 0o777, true);
		// The file exists, but `orphan` is not in the catalogue — so the theme
		// does not resolve and neither may its logo.
		file_put_contents($this->themeRoot . '/img/logos/orphan.svg', '<svg/>');

		$this->assertNull($this->resolver()->logoFileFor(theme: 'orphan'));

	}//end testAnUnresolvableThemeHasNoLogo()


	/**
	 * A traversal attempt is refused for the logo path too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
	 */
	public function testATraversalAttemptIsRefusedForTheLogo(): void {
		$this->assertNull($this->resolver()->logoFileFor(theme: '../../etc/passwd'));

	}//end testATraversalAttemptIsRefusedForTheLogo()


}//end class
