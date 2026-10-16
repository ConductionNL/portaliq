<?php

/**
 * Unit tests for EmailLinkController: refusals first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\BackgroundJob\EmailLinkRequestJob;
use OCA\Portaliq\Controller\EmailLinkController;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkEligibility;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkLimits;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The request endpoint, the link page's describe call and the redeem.
 */
class EmailLinkControllerTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * Jobs queued, as [class, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * The in-memory distributed cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * Sessions minted, by their named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $minted = [];

	/**
	 * The request's cookie, or null.
	 */
	private ?string $cookie = null;

	/**
	 * The request's Authorization header.
	 */
	private string $authorization = '';

	/**
	 * Whether the per-address counter is over its limit.
	 */
	private bool $addressLimitReached = false;

	/**
	 * Whether the switch is on.
	 */
	private bool $enabled = true;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->seedRow('portalAccount', ['subjectRef' => 'email:tom', 'audience' => 'client', 'organisation' => 'academie', 'identityType' => 'email', 'status' => 'active', 'signInAddress' => 'tom@example.nl']);
	}//end setUp()

	/**
	 * While the switch is OFF (the default) the form is refused like a portal
	 * that does not offer the mode, and nothing is queued.
	 *
	 * @return void
	 */
	public function testWhileTheSwitchIsOffTheRequestIsRefused(): void {
		$this->enabled = false;

		$response = $this->controller()->request(email: 'tom@example.nl');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->queued);
	}//end testWhileTheSwitchIsOffTheRequestIsRefused()

	/**
	 * @return void
	 */
	public function testAPortalThatDoesNotDeclareTheModeRefusesTheRequest(): void {
		$response = $this->controller(modes: ['digid'])->request(email: 'tom@example.nl');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->queued);
	}//end testAPortalThatDoesNotDeclareTheModeRefusesTheRequest()

	/**
	 * A known and an unknown address queue the same job and get the same
	 * answer; the request itself looks nothing up and mails nothing.
	 *
	 * @return void
	 */
	public function testKnownAndUnknownAddressGetTheSameAnswerAndOneJobEach(): void {
		$known   = $this->controller()->request(email: 'tom@example.nl');
		$unknown = $this->controller()->request(email: 'nobody@example.nl');

		$this->assertSame($known->getData(), $unknown->getData());
		$this->assertSame($known->getStatus(), $unknown->getStatus());
		$this->assertCount(2, $this->queued);
		$this->assertSame(EmailLinkRequestJob::class, $this->queued[0][0]);
		$this->assertSame(EmailLinkRequestJob::class, $this->queued[1][0]);
		$this->assertSame(array_keys($this->queued[0][1]), array_keys($this->queued[1][1]));
		$this->assertSame([], $this->storedRows('portalEmailLink'));

		$cookie = $known->getCookies()[EmailLinkController::COOKIE] ?? null;
		$this->assertSame('Strict', $cookie['sameSite'] ?? null);
		$this->assertSame(hash('sha256', (string)$cookie['value']), $this->queued[0][1]['cookieHash']);
	}//end testKnownAndUnknownAddressGetTheSameAnswerAndOneJobEach()

	/**
	 * A fourth request in an hour: the same answer, no job, so no mail.
	 *
	 * @return void
	 */
	public function testOverThePerAddressLimitTheAnswerStaysTheSameAndNothingIsQueued(): void {
		$within = $this->controller()->request(email: 'tom@example.nl');
		$this->addressLimitReached = true;
		$this->queued = [];

		$over = $this->controller()->request(email: 'tom@example.nl');

		$this->assertSame($within->getData(), $over->getData());
		$this->assertSame([], $this->queued);
	}//end testOverThePerAddressLimitTheAnswerStaysTheSameAndNothingIsQueued()

	/**
	 * A redeem without the value the page fetched spends nothing.
	 *
	 * @return void
	 */
	public function testARedeemWithoutThePageValueSpendsNothing(): void {
		$token = $this->issue(cookie: 'browser-a');
		$this->cookie = 'browser-a';

		$response = $this->controller()->redeem(token: $token, nonce: 'guessed');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('sent', $this->storedRows('portalEmailLink')[0]['state']);
		$this->assertSame([], $this->minted);
	}//end testARedeemWithoutThePageValueSpendsNothing()

	/**
	 * A mail scanner in a sandbox without the request cookie presses the
	 * button without typing the address: not spent, no session.
	 *
	 * @return void
	 */
	public function testAScannerWithoutTheCookieSpendsNothing(): void {
		$token = $this->issue(cookie: 'browser-a');
		$page  = $this->controller()->describe(token: $token)->getData();
		$this->assertFalse($page['sameBrowser']);

		$response = $this->controller()->redeem(token: $token, nonce: $page['nonce']);

		$this->assertSame('address_needed', $response->getData()['error']);
		$this->assertSame('sent', $this->storedRows('portalEmailLink')[0]['state']);
		$this->assertSame([], $this->minted);
	}//end testAScannerWithoutTheCookieSpendsNothing()

	/**
	 * A wrong address spends nothing and counts towards the brute-force limit.
	 *
	 * @return void
	 */
	public function testAWrongAddressSpendsNothingAndIsThrottled(): void {
		$token = $this->issue(cookie: 'browser-a');
		$page  = $this->controller()->describe(token: $token)->getData();

		$response = $this->controller()->redeem(token: $token, nonce: $page['nonce'], email: 'eve@example.nl');

		$this->assertSame('address_wrong', $response->getData()['error']);
		$this->assertTrue($response->isThrottled());
		$this->assertSame('sent', $this->storedRows('portalEmailLink')[0]['state']);
	}//end testAWrongAddressSpendsNothingAndIsThrottled()

	/**
	 * Tom opens the link in the browser that asked: the page names the portal
	 * and his masked address; pressing the button signs him in at `low`
	 * through a fresh session; a second press says the link was used. The
	 * bearer the browser sent along is never read.
	 *
	 * @return void
	 */
	public function testTomSignsInOnceAtLowAndABearerSentAlongIsNotReused(): void {
		$token = $this->issue(cookie: 'browser-a');
		$this->cookie        = 'browser-a';
		$this->authorization = 'Bearer someone-elses-session';

		$page = $this->controller()->describe(token: $token)->getData();
		$this->assertTrue($page['sameBrowser']);
		$this->assertSame('t***@example.nl', $page['address']);
		$this->assertSame('Mijn academie', $page['portal']);

		$response = $this->controller()->redeem(token: $token, nonce: $page['nonce']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bearer-1', $response->getData()['bearer']);
		$this->assertCount(1, $this->minted);
		$this->assertSame('email:tom', $this->minted[0]['subjectRef']);
		$this->assertSame('low', $this->minted[0]['trust']);
		$this->assertSame('email-link', $this->minted[0]['provider']);

		$again = $this->controller()->describe(token: $token);
		$this->assertSame('link_used', $again->getData()['error']);
	}//end testTomSignsInOnceAtLowAndABearerSentAlongIsNotReused()

	/**
	 * An account withdrawn after the link was mailed does not sign in.
	 *
	 * @return void
	 */
	public function testAWithdrawnAccountDoesNotSignIn(): void {
		$token = $this->issue(cookie: 'browser-a');
		$this->cookie = 'browser-a';
		$page = $this->controller()->describe(token: $token)->getData();
		foreach ($this->rows as $id => $row) {
			if (($row['_schema'] ?? '') === 'portalAccount') {
				$this->rows[$id]['status'] = 'suspended';
			}
		}

		$response = $this->controller()->redeem(token: $token, nonce: $page['nonce']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->minted);
	}//end testAWithdrawnAccountDoesNotSignIn()

	/**
	 * A link of Tom's, issued for the browser holding `$cookie`.
	 *
	 * @param string $cookie The browser's cookie.
	 *
	 * @return string The token.
	 */
	private function issue(string $cookie): string {
		$tokens = $this->tokens();
		$token  = $tokens->issue(account: ['subjectRef' => 'email:tom', 'organisation' => 'academie'], portal: 'academie', cookieHash: hash('sha256', $cookie), addressHash: hash('sha256', 'tom@example.nl'));
		$this->assertNotNull($token);

		return (string)$token;
	}//end issue()

	/**
	 * @return EmailLinkTokens
	 */
	private function tokens(): EmailLinkTokens {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('t5', 24));

		return new EmailLinkTokens($this->fakeReader(), $this->fakeWriter(), $random, new ClaimLock($this->createMock(ILockingProvider::class), 0));
	}//end tokens()

	/**
	 * @param array<int, string> $modes The portal's sign-in modes.
	 *
	 * @return EmailLinkController
	 */
	private function controller(array $modes = ['eherkenning', 'email-link']): EmailLinkController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('academie');
		$request->method('getCookie')->willReturnCallback(fn (string $name): ?string => ($name === EmailLinkController::COOKIE ? $this->cookie : null));
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === 'Authorization' ? $this->authorization : ''));

		$portals = $this->getMockBuilder(PortalResolver::class)->disableOriginalConstructor()->onlyMethods(['resolve'])->getMock();
		$portals->method('resolve')->willReturn(['slug' => 'academie', 'title' => 'Mijn academie', 'organisation' => 'academie', 'authentication' => ['modes' => $modes]]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => ($this->enabled ? '1' : '0'));

		$limiter = $this->createMock(ILimiter::class);
		$limiter->method('registerAnonRequest')->willReturnCallback(
			function (): void {
				if ($this->addressLimitReached === true) {
					throw $this->createMock(IRateLimitExceededException::class);
				}
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function ($job, $argument = null): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$counter = 0;
		$random  = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length) use (&$counter): string {
				$counter++;
				return str_pad('r' . $counter, $length, '0');
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->cache[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cache[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cache[$key]);
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$sessions = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['issueSession'])->getMock();
		$sessions->method('issueSession')->willReturnCallback(
			function (string $subjectRef, string $audience, string $organisation, string $trust = '', array $roles = [], string $branch = '', string $provider = ''): array {
				$this->minted[] = compact('subjectRef', 'audience', 'organisation', 'trust', 'roles', 'provider');
				return ['token' => 'bearer-' . count($this->minted), 'jti' => 'jti-' . count($this->minted), 'expiresAt' => 1, 'hardExpiresAt' => 2, 'idleTimeout' => 900];
			}
		);

		$reader  = $this->fakeReader();
		$address = new EmailLinkAddress();

		return new EmailLinkController(
			$request,
			$portals,
			new EmailLinkSetting($config),
			$address,
			new EmailLinkLimits($limiter),
			$this->tokens(),
			new EmailLinkEligibility($reader, $address),
			$jobs,
			$random,
			$cacheFactory,
			$sessions,
			$this->createMock(PortalIdentityMailer::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()
}//end class
