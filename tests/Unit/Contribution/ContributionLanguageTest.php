<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ContributionLanguage;
use OCA\Portaliq\Contribution\GuestActionRegistry;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Tests\Doubles\UrlParameterRequest;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A contributing app answers in the portal's language, not in whatever
 * `Accept-Language` the request carried. The provider interface is not
 * touched: the registry sets Nextcloud's `forceLanguage` request parameter
 * around the provider call, which Nextcloud's L10N factory reads before the
 * user's or the browser's language, and puts it back afterwards.
 *
 * The providers below read that parameter during their call, the way the
 * L10N factory does when a provider's IL10N is resolved.
 *
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-the-portal-api-must-ask-contributing-apps-in-the-portals-language
 */
class ContributionLanguageTest extends TestCase {

	private const PROVIDER_FQCN = 'OCA\\Portaliq\\Portal\\PortalContributionProvider';

	private const DUTCH_AND_ENGLISH = ['slug' => 'wilgenboom', 'locales' => ['nl', 'en']];

	public function testThePortalsOwnLanguageWinsOverABrowserLanguageItDoesNotSpeak(): void {
		$language = $this->language(
			headers: ['X-Portaliq-Portal' => 'wilgenboom', 'Accept-Language' => 'de-DE,de;q=0.9'],
			bySlug: self::DUTCH_AND_ENGLISH
		);

		$this->assertSame('nl', $language->languageFor(['organisation' => 'org-1']));
	}//end testThePortalsOwnLanguageWinsOverABrowserLanguageItDoesNotSpeak()

	public function testALanguageThePortalDeclaresIsKeptWhenTheSiteAsksForIt(): void {
		$language = $this->language(
			headers: ['X-Portaliq-Portal' => 'wilgenboom', 'Accept-Language' => 'en-GB,en;q=0.9'],
			bySlug: self::DUTCH_AND_ENGLISH
		);

		$this->assertSame('en', $language->languageFor());
	}//end testALanguageThePortalDeclaresIsKeptWhenTheSiteAsksForIt()

	public function testWithoutAPortalForTheRequestTheSubjectsOrganisationNamesIt(): void {
		$language = $this->language(
			headers: ['Accept-Language' => 'en'],
			bySlug: null,
			byOrganisation: ['locales' => ['nl']]
		);

		$this->assertSame('nl', $language->languageFor(['organisation' => 'org-1']));
	}//end testWithoutAPortalForTheRequestTheSubjectsOrganisationNamesIt()

	public function testNoPortalNoLocalesOrAFailingLookupLeavesTheChoiceToNextcloud(): void {
		$this->assertSame('', $this->language(headers: [], bySlug: null)->languageFor());
		$this->assertSame('', $this->language(headers: [], bySlug: ['locales' => [' ', 3]])->languageFor());

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willThrowException(new RuntimeException('OpenRegister is down'));
		$failing = new ContributionLanguage(new UrlParameterRequest(), $this->config(false), $portals);
		$this->assertSame('', $failing->languageFor());
	}//end testNoPortalNoLocalesOrAFailingLookupLeavesTheChoiceToNextcloud()

	public function testTheCallRunsInTheLanguageAndTheRequestIsPutBackAfterwards(): void {
		$request = new UrlParameterRequest();
		$request->setUrlParameters(['portal' => 'wilgenboom']);
		$language = new ContributionLanguage($request, $this->config(false), $this->createMock(PortalResolver::class));

		$seen = $language->speak('nl', static fn () => $request->getParam(ContributionLanguage::PARAMETER));

		$this->assertSame('nl', $seen);
		$this->assertNull($request->getParam(ContributionLanguage::PARAMETER));
		$this->assertSame('wilgenboom', $request->urlParams['portal']);
		$this->assertSame('wilgenboom', $request->getParam('portal'));
	}//end testTheCallRunsInTheLanguageAndTheRequestIsPutBackAfterwards()

	public function testTheRequestIsPutBackWhenTheCallThrows(): void {
		$request = new UrlParameterRequest();
		$language = new ContributionLanguage($request, $this->config(false), $this->createMock(PortalResolver::class));

		try {
			$language->speak('nl', static function (): void {
				throw new RuntimeException('provider failed');
			});
			$this->fail('the provider exception must reach the caller');
		} catch (RuntimeException $e) {
			$this->assertSame('provider failed', $e->getMessage());
		}

		$this->assertNull($request->getParam(ContributionLanguage::PARAMETER));
	}//end testTheRequestIsPutBackWhenTheCallThrows()

	public function testAnAdministratorsOrTheRequestsOwnForcedLanguageWins(): void {
		$request = new UrlParameterRequest();
		$byAdmin = new ContributionLanguage($request, $this->config('de'), $this->createMock(PortalResolver::class));
		$this->assertNull($byAdmin->speak('nl', static fn () => $request->getParam(ContributionLanguage::PARAMETER)));

		$asked = new UrlParameterRequest([], [ContributionLanguage::PARAMETER => 'en']);
		$byRequest = new ContributionLanguage($asked, $this->config(false), $this->createMock(PortalResolver::class));
		$this->assertSame('en', $byRequest->speak('nl', static fn () => $asked->getParam(ContributionLanguage::PARAMETER)));
		$this->assertSame('en', $asked->getParam(ContributionLanguage::PARAMETER), 'the request keeps its own choice');
	}//end testAnAdministratorsOrTheRequestsOwnForcedLanguageWins()

	public function testNoLanguageRunsTheCallUntouched(): void {
		$request = new UrlParameterRequest();
		$language = new ContributionLanguage($request, $this->config(false), $this->createMock(PortalResolver::class));

		$this->assertNull($language->speak('', static fn () => $request->getParam(ContributionLanguage::PARAMETER)));
		$this->assertSame([], $request->urlParams);
	}//end testNoLanguageRunsTheCallUntouched()

	public function testTheRegistryAsksEachProviderInThePortalsLanguage(): void {
		$request = new UrlParameterRequest(['X-Portaliq-Portal' => 'wilgenboom', 'Accept-Language' => 'en-US']);
		$provider = $this->provider($request, ['parent']);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'wilgenboom', 'locales' => ['nl']]);

		$registry = $this->registry($provider, new ContributionLanguage($request, $this->config(false), $portals));

		$result = $registry->aggregateFor(['audience' => 'parent', 'organisation' => 'org-1']);

		$this->assertSame('Mijn kinderen', $result['contributions'][0]['label']);
		$this->assertSame(['nl'], $provider->seen);
		$this->assertNull($request->getParam(ContributionLanguage::PARAMETER), 'restored after the aggregation');
	}//end testTheRegistryAsksEachProviderInThePortalsLanguage()

	public function testTheAnonymousAggregationAsksInThePortalsLanguageToo(): void {
		$request = new UrlParameterRequest(['Accept-Language' => 'en']);
		$provider = $this->provider($request, ['citizen'], anonymous: true);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['locales' => ['nl']]);

		$registry = $this->registry($provider, new ContributionLanguage($request, $this->config(false), $portals));

		$result = $registry->aggregateAnonymous();

		$this->assertCount(1, $result['contributions']);
		$this->assertSame(['nl'], $provider->seen);
	}//end testTheAnonymousAggregationAsksInThePortalsLanguageToo()

	public function testTheGuestActionIsAskedInThePortalsLanguage(): void {
		$request = new UrlParameterRequest(['Accept-Language' => 'en']);
		$provider = $this->provider($request, ['guest']);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['locales' => ['nl']]);
		$logger = $this->createMock(LoggerInterface::class);

		$guests = new GuestActionRegistry(
			$this->appManager(),
			new PortalProviderLocator(
				$this->appManager(),
				$this->container($provider),
				$logger,
				new ContributionLanguage($request, $this->config(false), $portals)
			),
			$logger
		);

		$guests->guestAction(appId: 'portaliq', actionId: 'none');

		$this->assertSame(['nl'], $provider->seen);
		$this->assertNull($request->getParam(ContributionLanguage::PARAMETER));
	}//end testTheGuestActionIsAskedInThePortalsLanguage()

	public function testARegistryBuiltWithoutALanguageAsksAsBefore(): void {
		$request = new UrlParameterRequest(['Accept-Language' => 'en']);
		$provider = $this->provider($request, ['parent']);

		$registry = new PortalContributionRegistry(
			$this->appManager(),
			$this->container($provider),
			$this->createMock(LoggerInterface::class)
		);
		$registry->aggregateFor(['audience' => 'parent']);

		$this->assertSame([null], $provider->seen);
	}//end testARegistryBuiltWithoutALanguageAsksAsBefore()

	/**
	 * A registry whose provider locator asks in the given language, as the
	 * DI container builds it.
	 *
	 * @param object               $provider The provider.
	 * @param ContributionLanguage $language The language.
	 *
	 * @return PortalContributionRegistry
	 */
	private function registry(object $provider, ContributionLanguage $language): PortalContributionRegistry {
		$logger = $this->createMock(LoggerInterface::class);
		$container = $this->container($provider);

		return new PortalContributionRegistry(
			$this->appManager(),
			$container,
			$logger,
			locator: new PortalProviderLocator($this->appManager(), $container, $logger, $language)
		);
	}//end registry()

	/**
	 * A ContributionLanguage over a fixed portal lookup.
	 *
	 * @param array<string, string>     $headers        The request headers.
	 * @param array<string, mixed>|null $bySlug         What resolve() answers.
	 * @param array<string, mixed>|null $byOrganisation What resolveByOrganisation() answers.
	 *
	 * @return ContributionLanguage
	 */
	private function language(array $headers, ?array $bySlug, ?array $byOrganisation=null): ContributionLanguage {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn($bySlug);
		$portals->method('resolveByOrganisation')->willReturn($byOrganisation);

		return new ContributionLanguage(new UrlParameterRequest($headers), $this->config(false), $portals);
	}//end language()

	/**
	 * A config whose `force_language` is the given value.
	 *
	 * @param string|false $forced The system value.
	 *
	 * @return IConfig
	 */
	private function config(string|false $forced): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, $default=null) => ($key === 'force_language' ? $forced : $default)
		);
		return $config;
	}//end config()

	/**
	 * A provider that answers in the language the request forces during its
	 * call, as a leaf app's IL10N would, and records what it saw.
	 *
	 * @param UrlParameterRequest $request   The request the L10N factory reads.
	 * @param array<int, string>  $audiences The audiences it serves.
	 * @param bool                $anonymous Whether its one collection is anonymous.
	 *
	 * @return object
	 */
	private function provider(UrlParameterRequest $request, array $audiences, bool $anonymous=false): object {
		return new class ($request, $audiences, $anonymous) {

			/**
			 * @var array<int, mixed>
			 */
			public array $seen = [];

			public function __construct(
				private readonly UrlParameterRequest $request,
				private readonly array $audiences,
				private readonly bool $anonymous,
			) {
			}

			public function getAudiences(): array {
				return $this->audiences;
			}

			public function getContribution(array $subject): array {
				$forced = $this->request->getParam(ContributionLanguage::PARAMETER);
				$this->seen[] = $forced;
				return [
					'label' => ($forced === 'nl' ? 'Mijn kinderen' : 'My children'),
					'collections' => [
						['id' => 'children', 'register' => 'learniq', 'schema' => 'learner', 'anonymous' => $this->anonymous],
					],
					'actions' => [],
				];
			}
		};
	}//end provider()

	/**
	 * Only portaliq is installed, so the provider is looked up under its FQCN.
	 *
	 * @return IAppManager
	 */
	private function appManager(): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('getInstalledApps')->willReturn(['portaliq']);
		$mock->method('getAppInfo')->willReturn(['id' => 'portaliq']);
		return $mock;
	}//end appManager()

	/**
	 * A container that hands out the provider.
	 *
	 * @param object $provider The provider.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $provider): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			static function (string $id) use ($provider) {
				if ($id === self::PROVIDER_FQCN) {
					return $provider;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end container()
}//end class
