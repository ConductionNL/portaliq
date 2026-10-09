<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Cms\SiteHead;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\PortalTokenCss;
use OCA\Portaliq\Service\SiteShell;
use OCA\Portaliq\Service\SiteShellTheme;
use OCA\Portaliq\Service\Theme\PortalThemeParents;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The server-rendered shell params: slug, theme, sign-in, notices, locale, head.
 */
#[CoversClass(SiteShell::class)]
#[CoversClass(SiteShellTheme::class)]
#[UsesClass(PortalThemeParents::class)]
#[UsesClass(PortalTokenCss::class)]
class SiteShellTest extends TestCase {
	/**
	 * Build the shell over doubles.
	 *
	 * @param array<string, mixed> $params Request params.
	 * @param string               $accept Accept-Language header.
	 * @param array|null           $portal The resolved portal.
	 * @param bool                 $resolverThrows Whether resolve() throws.
	 * @param bool                 $orgThrows Whether the organisation lookup throws.
	 *
	 * @return array{0: SiteShell, 1: array<string, \PHPUnit\Framework\MockObject\MockObject>}
	 */
	private function shell(array $params, string $accept, ?array $portal, bool $resolverThrows=false, bool $orgThrows=false): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturn($accept);

		$resolver = $this->createMock(PortalResolver::class);
		if ($resolverThrows === true) {
			$resolver->method('resolve')->willThrowException(new \RuntimeException('down'));
		} else {
			$resolver->method('resolve')->willReturn($portal);
		}

		if ($orgThrows === true) {
			$resolver->method('resolveByOrganisation')->willThrowException(new \RuntimeException('x'));
		} else {
			$resolver->method('resolveByOrganisation')->willReturn(['slug' => 'by-org']);
		}

		$resolver->method('localeFor')->willReturnCallback(static fn (?array $p, string $l): string => $l);

		$theme = $this->createMock(PortalThemeResolver::class);
		$theme->method('stylesheetFor')->willReturnCallback(static fn (string $t): ?string => ($t === 'child' ? '/css/child.css' : ($t === 'base' ? '/css/base.css' : null)));
		$theme->method('nldsStylesheetFor')->willReturn('/css/nlds.css');
		$theme->method('catalogue')->willReturn([['id' => 'child', 'extends' => 'base'], ['id' => 'base']]);
		$theme->method('shippedStylesheets')->willReturn(['bridge' => '/b.css', 'fonts' => '/f.css']);
		$theme->method('logoFileFor')->willReturnCallback(static fn (string $t, string $v = ''): ?string => ($v === 'emblem' ? null : 'img/' . $t . $v . '.svg'));
		$theme->method('themeAppId')->willReturn('nldesign');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/api/site');
		$urls->method('linkToRouteAbsolute')->willReturn('https://x/site');
		$urls->method('linkTo')->willReturnCallback(static fn (string $app, string $file): string => '/apps/' . $app . '/' . $file);

		$head = $this->createMock(SiteHead::class);
		$head->method('for')->willReturn(['title' => 'Head']);
		$notices = $this->createMock(PortalNoticeReader::class);
		$notices->method('active')->willReturn([['id' => 'n1']]);
		$config = $this->createMock(PortalRuntimeConfigResolver::class);
		$config->method('resolvePortal')->willReturn($portal);
		$config->method('runtimeConfigFor')->willReturn(['devLogin' => true, 'silentSignIn' => 'digid', 'waysIn' => ['digid']]);

		$shell = new SiteShell($request, $resolver, $theme, $urls, $head, $notices, $config);

		return [$shell, ['resolver' => $resolver, 'notices' => $notices]];
	}//end shell()

	/**
	 * A resolved themed portal fills every template parameter.
	 *
	 * @return void
	 */
	public function testTemplateParamsForThemedPortal(): void {
		[$shell] = $this->shell(['portal' => 'zuid', 'route' => '/x'], 'nl-NL,nl;q=0.9', ['slug' => 'zuid', 'title' => 'Zuid', 'theme' => 'child', 'tokens' => ['--utrecht-color' => '#112233', '--bad-x' => '#fff']]);

		$params = $shell->templateParams();

		$this->assertSame('zuid', $params['portalConfig']['portal']);
		$this->assertSame('/api/site', $params['portalConfig']['apiBase']);
		$this->assertSame('zuid', $params['portalConfig']['resolvedPortal']);
		$this->assertSame('Zuid', $params['portalConfig']['title']);
		$this->assertSame([['id' => 'n1']], $params['portalConfig']['portalNotices']);
		$this->assertTrue($params['portalConfig']['signin']['devLogin']);
		$this->assertSame('digid', $params['portalConfig']['signin']['silentSignIn']);
		$this->assertSame('', $params['portalConfig']['signin']['audience']);
		$this->assertSame('/css/child.css', $params['themeStylesheet']);
		$this->assertSame(['/css/base.css'], $params['themeParents']);
		$this->assertStringContainsString('#112233', $params['themeTokenCss']);
		$this->assertSame('/css/nlds.css', $params['nldsStylesheet']);
		$this->assertSame('nl-NL', $params['locale']);
		$this->assertSame(['title' => 'Head'], $params['head']);
		$this->assertSame('/apps/nldesign/img/child.cssdark.svg', $params['themeAppSheets']['logoInverse']);
		$this->assertSame('', $params['themeAppSheets']['emblem']);
		$this->assertSame('/b.css', $params['themeAppSheets']['bridge']);
		$this->assertSame('/apps/nldesign/img/child.css.svg', $params['themeLogoUrl']);
	}//end testTemplateParamsForThemedPortal()

	/**
	 * No portal means empty theme output, a default locale and no notices.
	 *
	 * @return void
	 */
	public function testTemplateParamsWithoutPortal(): void {
		[$shell, $mocks] = $this->shell([], '', null);
		$mocks['notices']->expects($this->never())->method('active');

		$params = $shell->templateParams();

		$this->assertSame('', $params['portalConfig']['resolvedPortal']);
		$this->assertSame('', $params['portalConfig']['title']);
		$this->assertSame([], $params['portalConfig']['portalNotices']);
		$this->assertSame('', $params['themeStylesheet']);
		$this->assertSame([], $params['themeParents']);
		$this->assertSame('', $params['themeTokenCss']);
		$this->assertSame('', $params['nldsStylesheet']);
		$this->assertSame('', $params['themeLogoUrl']);
		$this->assertSame('', $params['themeAppSheets']['bridge']);
		$this->assertSame('nl', $params['locale']);
	}//end testTemplateParamsWithoutPortal()

	/**
	 * A failing resolver degrades to empty values instead of throwing.
	 *
	 * @return void
	 */
	public function testResolverFailureDegrades(): void {
		[$shell] = $this->shell(['org' => 'acme'], 'en;q=0.8', null, true);

		$params = $shell->templateParams();

		$this->assertSame('', $params['portalConfig']['resolvedPortal']);
		$this->assertSame('', $params['portalConfig']['title']);
		$this->assertSame('', $params['themeStylesheet']);
		$this->assertSame('', $params['nldsStylesheet']);
		$this->assertSame([], $params['themeParents']);
		$this->assertSame('en', $params['locale']);
	}//end testResolverFailureDegrades()

	/**
	 * The slug comes from `portal`, else from the organisation, and is cached.
	 *
	 * @return void
	 */
	public function testRequestedPortalSlug(): void {
		[$byOrg] = $this->shell(['org' => 'acme'], '', null);
		$this->assertSame('by-org', $byOrg->requestedPortalSlug());
		$this->assertSame('by-org', $byOrg->requestedPortalSlug());

		[$direct] = $this->shell(['portal' => ' zuid ', 'org' => 'acme'], '', null);
		$this->assertSame('zuid', $direct->requestedPortalSlug());

		[$none] = $this->shell([], '', null);
		$this->assertSame('', $none->requestedPortalSlug());
	}//end testRequestedPortalSlug()

	/**
	 * Organisation lookup failing leaves the slug empty.
	 *
	 * @return void
	 */
	public function testOrganisationLookupFailure(): void {
		[$shell] = $this->shell(['org' => 'acme'], '', null, false, true);
		$this->assertSame('', $shell->requestedPortalSlug());
	}//end testOrganisationLookupFailure()
}//end class
