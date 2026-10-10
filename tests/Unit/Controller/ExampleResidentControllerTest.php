<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ExampleResidentController;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentCatalogue;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRecord;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentSignIn;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The one-click demo sign-in for the example resident: closed without the
 * switch, refused for another resident, portal or an inactive account, and
 * minting at trust low for the resident's own account only.
 *
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */
class ExampleResidentControllerTest extends TestCase {

	/**
	 * The controller over doubles; every service not given is a bare mock,
	 * and the sign-in service is the real one over the given parts.
	 *
	 * @param PortalSessionService          $session          The session service.
	 * @param IConfig|null                  $config           The config.
	 * @param PortalAccountService|null     $accounts         The account service.
	 * @param IURLGenerator|null            $urlGenerator     The URL generator.
	 * @param PortalResolver|null           $portals          The portal resolver.
	 * @param ExampleResidentRecord|null    $exampleResidents The install records.
	 * @param ExampleResidentCatalogue|null $exampleCatalogue The shipped residents.
	 *
	 * @return ExampleResidentController
	 */
	private function controller(
		PortalSessionService $session,
		?IConfig $config = null,
		?PortalAccountService $accounts = null,
		?IURLGenerator $urlGenerator = null,
		?PortalResolver $portals = null,
		?ExampleResidentRecord $exampleResidents = null,
		?ExampleResidentCatalogue $exampleCatalogue = null,
	): ExampleResidentController {
		return new ExampleResidentController(
			$this->createMock(originalClassName: IRequest::class),
			$session,
			($accounts ?? $this->createMock(originalClassName: PortalAccountService::class)),
			($urlGenerator ?? $this->createMock(originalClassName: IURLGenerator::class)),
			($portals ?? $this->createMock(originalClassName: PortalResolver::class)),
			new ExampleResidentSignIn(
				config: ($config ?? $this->createMock(originalClassName: IConfig::class)),
				records: $exampleResidents,
				catalogue: $exampleCatalogue
			)
		);
	}//end controller()

	/**
	 * The one-click demo sign-in: the parts every test below shares. The
	 * example resident `zuiddrecht` is installed as `sanne.devries` on the
	 * portal `zuiddrecht`, which offers the `nextcloud` mode, and has an
	 * active portal account. A refusal in a test is therefore the guard's,
	 * never a missing account's.
	 *
	 * @param string             $switch     The app config `example_resident_demo_login`.
	 * @param bool               $debug      The system `debug` flag.
	 * @param array<int, string> $modes      The sign-in modes the portals offer.
	 * @param string             $resolvesTo When set, the slug every portal lookup answers with.
	 *
	 * @return array{config: IConfig, record: ExampleResidentRecord, catalogue: ExampleResidentCatalogue, portals: PortalResolver, accounts: PortalAccountService}
	 */
	private function demoParts(
		string $switch,
		bool $debug = false,
		array $modes = ['public', 'digid', 'nextcloud'],
		string $resolvesTo = ''
	): array {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValueBool')->willReturn($debug);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, mixed $default = ''): string => ($key === 'example_resident_demo_login' ? $switch : (string)$default)
		);
		$record = $this->createMock(originalClassName: ExampleResidentRecord::class);
		$record->method('read')->willReturnCallback(
			static fn (string $id): array => [
				'userId'      => ($id === 'zuiddrecht' ? 'sanne.devries' : ''),
				'userCreated' => true,
				'account'     => 'acc-1',
				'signIn'      => ['mode' => 'nextcloud', 'label' => true],
				'objects'     => [],
			]
		);
		$catalogue = $this->createMock(originalClassName: ExampleResidentCatalogue::class);
		$catalogue->method('find')->willReturnCallback(
			static fn (string $id): ?array => ($id === 'zuiddrecht' ? ['id' => 'zuiddrecht', 'portal' => 'zuiddrecht'] : null)
		);
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('resolve')->willReturnCallback(
			static fn (IRequest $request, string $portalSlug): ?array => (
				in_array(needle: $portalSlug, haystack: ['zuiddrecht', 'other'], strict: true)
					? ['slug' => ($resolvesTo !== '' ? $resolvesTo : $portalSlug), 'authentication' => ['modes' => $modes]]
					: null
			)
		);
		$accounts = $this->createMock(originalClassName: PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static fn (string $subjectRef): ?array => ($subjectRef === 'sanne.devries' ? ['status' => 'active', 'audience' => 'citizen', 'organisation' => 'zuiddrecht'] : null)
		);

		return ['config' => $config, 'record' => $record, 'catalogue' => $catalogue, 'portals' => $portals, 'accounts' => $accounts];
	}//end demoParts()


	/**
	 * With the switch off the one-click sign-in is a throttled 404, even in
	 * debug mode, mints nothing and does not look the portal up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInIsClosedWithoutTheSwitch(): void {
		$parts   = $this->demoParts(switch: 'no', debug: true);
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->expects($this->never())->method('resolve');

		$response = $this->controller(
			session: $session,
			config: $parts['config'],
			accounts: $parts['accounts'],
			portals: $portals,
			exampleResidents: $parts['record'],
			exampleCatalogue: $parts['catalogue']
		)->signIn(id: 'zuiddrecht', portal: 'zuiddrecht');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'not_found'], actual: $response->getData());
		$this->assertTrue(condition: $response->isThrottled());
	}//end testTheOneClickSignInIsClosedWithoutTheSwitch()


	/**
	 * With the switch on, an id no install record names and a portal that is
	 * not the resident's own are both a 404 that mints nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInRefusesAnotherResidentOrPortal(): void {
		$parts   = $this->demoParts(switch: 'yes');
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$controller = $this->controller(
			session: $session,
			config: $parts['config'],
			accounts: $parts['accounts'],
			portals: $parts['portals'],
			exampleResidents: $parts['record'],
			exampleCatalogue: $parts['catalogue']
		);

		foreach ([['id' => 'someone', 'portal' => 'zuiddrecht'], ['id' => 'zuiddrecht', 'portal' => 'other'], ['id' => '', 'portal' => '']] as $call) {
			$response = $controller->signIn(id: $call['id'], portal: $call['portal']);
			$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus(), message: json_encode($call));
			$this->assertTrue(condition: $response->isThrottled());
		}
	}//end testTheOneClickSignInRefusesAnotherResidentOrPortal()


	/**
	 * With the switch on, the resident's own portal mints a session for the
	 * record's user id, at trust low, and hands the bearer back in the
	 * fragment; the caller never names the subject.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInMintsForTheExampleResidentOnly(): void {
		$parts   = $this->demoParts(switch: 'yes');
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->once())->method('issueSession')
			->with('sanne.devries', 'citizen', 'zuiddrecht', 'low', ['citizen:read'])
			->willReturn(['token' => 'tok.en', 'expiresAt' => 1]);
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://demo.test' . $url);

		$response = $this->controller(
			session: $session,
			config: $parts['config'],
			accounts: $parts['accounts'],
			urlGenerator: $urlGenerator,
			portals: $parts['portals'],
			exampleResidents: $parts['record'],
			exampleCatalogue: $parts['catalogue']
		)->signIn(id: 'zuiddrecht', portal: 'zuiddrecht');

		$this->assertInstanceOf(expected: RedirectResponse::class, actual: $response);
		$this->assertSame(expected: 'https://demo.test/apps/portaliq/site?portal=zuiddrecht#token=tok.en', actual: $response->getRedirectURL());
	}//end testTheOneClickSignInMintsForTheExampleResidentOnly()


	/**
	 * A resident whose portal account is gone or inactive is refused the
	 * same way, so the route tells nothing apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInRefusesAnInactiveAccount(): void {
		$parts    = $this->demoParts(switch: 'yes');
		$accounts = $this->createMock(originalClassName: PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturn(['status' => 'blocked']);
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');

		$response = $this->controller(
			session: $session,
			config: $parts['config'],
			accounts: $accounts,
			portals: $parts['portals'],
			exampleResidents: $parts['record'],
			exampleCatalogue: $parts['catalogue']
		)->signIn(id: 'zuiddrecht', portal: 'zuiddrecht');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testTheOneClickSignInRefusesAnInactiveAccount()


	/**
	 * The sign-in is refused when the resident's own portal does not offer
	 * the `nextcloud` mode, the resident's own way in, and when the lookup
	 * answers with the resident's portal for another portal the caller named
	 * (for example by host).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInRefusesAPortalThatDoesNotOfferTheNextcloudMode(): void {
		$cases = [
			'no nextcloud mode' => ['named' => 'zuiddrecht', 'modes' => ['public', 'digid'], 'resolvesTo' => ''],
			'another portal'    => ['named' => 'other', 'modes' => ['public', 'digid', 'nextcloud'], 'resolvesTo' => 'zuiddrecht'],
		];
		foreach ($cases as $case => $portal) {
			$parts   = $this->demoParts(switch: 'yes', modes: $portal['modes'], resolvesTo: $portal['resolvesTo']);
			$session = $this->createMock(originalClassName: PortalSessionService::class);
			$session->expects($this->never())->method('issueSession');

			$response = $this->controller(
				session: $session,
				config: $parts['config'],
				accounts: $parts['accounts'],
				portals: $parts['portals'],
				exampleResidents: $parts['record'],
				exampleCatalogue: $parts['catalogue']
			)->signIn(id: 'zuiddrecht', portal: $portal['named']);

			$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus(), message: $case);
			$this->assertTrue(condition: $response->isThrottled(), message: $case);
		}
	}//end testTheOneClickSignInRefusesAPortalThatDoesNotOfferTheNextcloudMode()


	/**
	 * A portal lookup that throws is no door: a throttled 404, nothing minted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInRefusesWhenThePortalCannotBeRead(): void {
		$parts   = $this->demoParts(switch: 'yes');
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('resolve')->willThrowException(new \RuntimeException('register unavailable'));

		$response = $this->controller(
			session: $session,
			config: $parts['config'],
			accounts: $parts['accounts'],
			portals: $portals,
			exampleResidents: $parts['record'],
			exampleCatalogue: $parts['catalogue']
		)->signIn(id: 'zuiddrecht', portal: 'zuiddrecht');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertTrue(condition: $response->isThrottled());
	}//end testTheOneClickSignInRefusesWhenThePortalCannotBeRead()


	/**
	 * When no session can be issued, the answer is a 503, not a redirect
	 * without a bearer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function testTheOneClickSignInAnswers503WhenNoSessionCanBeIssued(): void {
		$parts   = $this->demoParts(switch: 'yes');
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->method('issueSession')->willReturn(null);

		$response = $this->controller(
			session: $session,
			config: $parts['config'],
			accounts: $parts['accounts'],
			portals: $parts['portals'],
			exampleResidents: $parts['record'],
			exampleCatalogue: $parts['catalogue']
		)->signIn(id: 'zuiddrecht', portal: 'zuiddrecht');

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'not_configured'], actual: $response->getData());
	}//end testTheOneClickSignInAnswers503WhenNoSessionCanBeIssued()
}//end class
