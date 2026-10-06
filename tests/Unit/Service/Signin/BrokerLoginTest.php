<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Service\Signin
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Signin;

use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Signin\BrokerExchangeClient;
use OCA\Portaliq\Service\Signin\OrganisationLoginConfig;
use OCA\Portaliq\Service\Signin\BrokerLogin;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * T04, T05, T07: the broker login from start to minted session, over the real
 * organisation config, state store, exchange client and envelope check. Only
 * the HTTP answer, OpenRegister's rows, the account store and the session
 * minter are doubled.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md
 */
class BrokerLoginTest extends TestCase {

	/**
	 * The state rows, as OpenRegister would hold them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * What the exchange was asked, in order.
	 *
	 * @var array<int, array{url: string, options: array<string, mixed>}>
	 */
	private array $exchanged = [];

	/**
	 * What the account store was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $accountCalls = [];

	/**
	 * What the session minter was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sessionCalls = [];

	/**
	 * The audience the found account holds, or null for a new account.
	 *
	 * @var string|null
	 */
	private ?string $accountAudience = null;


	/**
	 * The login over an organisation whose override is given, and an exchange
	 * that answers the given status and body.
	 *
	 * @param array<string, mixed> $overrides The organisation's override.
	 * @param int                  $status    The exchange's status.
	 * @param string               $body      The exchange's body.
	 *
	 * @return BrokerLogin
	 */
	private function login(array $overrides, int $status = 200, string $body = ''): BrokerLogin {
		$organisations = new class {
			/**
			 * @param string $slug The slug.
			 *
			 * @return object
			 */
			public function findBySlug(string $slug): object {
				if ($slug !== 'gemeente-x') {
					throw new \RuntimeException('not found');
				}

				return new class {
					public function getUuid(): string {
						return 'org-uuid-1';
					}

					public function getName(): string {
						return 'Gemeente X';
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'broker_secret_org-uuid-1' => 'consumer-secret-1',
				'org_presentation_org-uuid-1' => (string)json_encode($overrides),
				default => $default,
			}
		);
		$orgConfig = new OrganisationLoginConfig(
			new PortalOrganisationConfigService($container, $appConfig, $this->createMock(LoggerInterface::class), new OidcClaimMapperService()),
			$appConfig
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->exchanged[] = ['url' => $url, 'options' => $options];
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$accounts = $this->getMockBuilder(PortalAccountService::class)->disableOriginalConstructor()->onlyMethods(['findOrCreate'])->getMock();
		$accounts->method('findOrCreate')->willReturnCallback(
			function (...$args): array {
				$this->accountCalls[] = $args;
				if ($this->accountAudience !== null) {
					return ['subjectRef' => 'subject-9', 'isNew' => false, 'audience' => $this->accountAudience];
				}

				return ['subjectRef' => 'subject-9', 'isNew' => true];
			}
		);
		$session = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['issueSession'])->getMock();
		$session->method('issueSession')->willReturnCallback(
			function (...$args): array {
				$this->sessionCalls[] = $args;
				return ['token' => 'bearer-1', 'jti' => 'j1'];
			}
		);

		return new BrokerLogin(
			$orgConfig,
			new OidcStateStoreService($this->writer(), $this->reader()),
			new BrokerExchangeClient($clients, $this->createMock(LoggerInterface::class)),
			$accounts,
			$session
		);
	}//end login()


	/**
	 * The organisation's override with DigiD routed to a complete broker.
	 *
	 * @return array<string, mixed>
	 */
	private function routed(): array {
		return [
			'loginRoutes' => ['digid' => 'broker'],
			'broker' => [
				'startUrl' => 'https://integriq.example/idp/start',
				'exchangeUrl' => 'https://integriq.example/api/idp/envelope/exchange',
				'consumerId' => 'portaliq-venray',
			],
			'oidc' => ['eherkenning' => ['issuer' => 'https://idp.example', 'clientId' => 'c1']],
		];
	}//end routed()


	/**
	 * The writer over $this->rows.
	 *
	 * @return PortalObjectWriter
	 */
	private function writer(): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$data['uuid'] = 'row-' . (count($this->rows) + 1);
				$this->rows[$data['uuid']] = $data;
				return $data;
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): ?array {
				if (isset($this->rows[$id]) === false) {
					return null;
				}

				$this->rows[$id] = array_merge($this->rows[$id], $data);
				return $this->rows[$id];
			}
		);
		return $writer;
	}//end writer()


	/**
	 * The reader over $this->rows.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema, string $scopeField, string $subjectRef): array => array_values(
				array_filter($this->rows, static fn (array $row): bool => ($row[$scopeField] ?? null) === $subjectRef)
			)
		);
		return $reader;
	}//end reader()


	/**
	 * The relay state the start wrote into integriq's address.
	 *
	 * @param string $url The start address.
	 *
	 * @return array<string, string>
	 */
	private function queryOf(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		return $query;
	}//end queryOf()


	/**
	 * A body integriq answers for a DigiD login at gemeente-x, fresh now.
	 *
	 * @param array<string, mixed> $change Claims to change.
	 *
	 * @return string
	 */
	private function exchangeBody(array $change = []): string {
		$claims = array_merge(BrokerEnvelopeCheckTest::claims(), ['iat' => (time() - 5), 'exp' => (time() + 55)], $change);
		return (string)json_encode(['envelope' => BrokerEnvelopeCheckTest::envelope($claims)]);
	}//end exchangeBody()


	/**
	 * T04: the start sends the browser to integriq with the organisation, the
	 * provider, the trust asked for, the consumer and a relay state that is
	 * also the single-use row's key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 */
	public function testStartRedirectsWithRelayState(): void {
		$url = $this->login($this->routed())->start(org: 'gemeente-x', provider: 'digid', returnTo: '/apps/portaliq/portal', callbackUrl: 'https://portal.example/callback');

		$this->assertStringStartsWith('https://integriq.example/idp/start?', (string)$url);
		$query = $this->queryOf((string)$url);
		$this->assertSame('gemeente-x', $query['organisation']);
		$this->assertSame('digid', $query['provider']);
		$this->assertSame('substantial', $query['trust']);
		$this->assertSame('portaliq-venray', $query['consumer']);
		$this->assertSame('https://portal.example/callback', $query['returnUrl']);
		$this->assertStringNotContainsString('consumer-secret-1', (string)$url);

		$row = array_values($this->rows)[0];
		// Integriq reads and hands back `relayState` (digid-eherkenning-auth-adapter
		// REQ-IDP-001); a `state` it does not read came back empty.
		$this->assertSame($query['relayState'], $row['state']);
		$this->assertArrayNotHasKey('state', $query);
		$this->assertSame('broker', $row['route']);
		$this->assertSame('gemeente-x', $row['org']);
	}//end testStartRedirectsWithRelayState()


	/**
	 * T04: a provider this organisation routes to its own OIDC broker cannot
	 * be started here, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 */
	public function testStartRefusesAnOidcRoutedProvider(): void {
		$login = $this->login($this->routed());
		$this->assertNull($login->start(org: 'gemeente-x', provider: 'eherkenning', returnTo: '/p', callbackUrl: 'https://p/cb'));
		$this->assertNull($login->start(org: 'gemeente-y', provider: 'digid', returnTo: '/p', callbackUrl: 'https://p/cb'), 'an unknown organisation');
		$this->assertNull($login->start(org: 'gemeente-x', provider: 'generic', returnTo: '/p', callbackUrl: 'https://p/cb'));
		$this->assertSame([], $this->rows);
	}//end testStartRefusesAnOidcRoutedProvider()


	/**
	 * T07: the envelope becomes an ordinary session with integriq's trust, and
	 * the account is keyed on the provider and the envelope's subject.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-envelope-becomes-an-ordinary-portal-session-req-bel-005
	 */
	public function testEnvelopeMintsASessionWithItsTrust(): void {
		$login = $this->login($this->routed(), 200, $this->exchangeBody());
		$state = $this->queryOf((string)$login->start(org: 'gemeente-x', provider: 'digid', returnTo: '/apps/portaliq/portal', callbackUrl: 'https://p/cb'))['relayState'];

		$this->assertSame(['token' => 'bearer-1', 'returnTo' => '/apps/portaliq/portal'], $login->complete(state: $state, code: 'one-time-code'));

		$this->assertSame('https://integriq.example/api/idp/envelope/exchange', $this->exchanged[0]['url']);
		$this->assertSame('Bearer consumer-secret-1', $this->exchanged[0]['options']['headers']['Authorization']);
		$this->assertSame(['code' => 'one-time-code', 'consumer' => 'portaliq-venray'], $this->exchanged[0]['options']['json']);
		// identityType, identityRef, organisation, audience; no subject
		// override and no verified e-mail (the broker supplies none).
		$this->assertSame(['digid', 'pseudonym-3f2a', 'gemeente-x', 'client', null, ''], $this->accountCalls[0]);
		// subjectRef, audience, organisation, trust, roles, and no branch:
		// integriq's envelope carries none yet (signin-eherkenning-branch),
		// and no OIDC provider: integriq's route has no broker sign-out
		// (signin-session-idle-warning-and-sso D6).
		$this->assertSame(['subject-9', 'client', 'gemeente-x', 'substantial', ['client:read'], '', ''], $this->sessionCalls[0]);
	}//end testEnvelopeMintsASessionWithItsTrust()


	/**
	 * An account an app invited as `employer` signs in through the broker:
	 * the session takes the account's audience, not the preset's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-account-names-the-audience-and-the-company/specs/portal-identity-space/spec.md#requirement-an-existing-accounts-audience-wins-over-the-sign-in-routes
	 */
	public function testAnInvitedAccountKeepsItsAudience(): void {
		$this->accountAudience = 'employer';
		$login = $this->login($this->routed(), 200, $this->exchangeBody());
		$state = $this->queryOf((string)$login->start(org: 'gemeente-x', provider: 'digid', returnTo: '/p', callbackUrl: 'https://p/cb'))['relayState'];

		$this->assertSame('bearer-1', $login->complete(state: $state, code: 'one-time-code')['token']);
		$this->assertSame('client', $this->accountCalls[0][3]);
		$this->assertSame('employer', $this->sessionCalls[0][1]);
		$this->assertSame(['employer:read'], $this->sessionCalls[0][4]);
	}//end testAnInvitedAccountKeepsItsAudience()


	/**
	 * T07: a trust value the portal does not know is under-privileged to low.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-envelope-becomes-an-ordinary-portal-session-req-bel-005
	 */
	public function testUnknownTrustBecomesLow(): void {
		$login = $this->login($this->routed(), 200, $this->exchangeBody(['trust' => 'eidas-high']));
		$state = $this->queryOf((string)$login->start(org: 'gemeente-x', provider: 'digid', returnTo: '/p', callbackUrl: 'https://p/cb'))['relayState'];

		$this->assertNotNull($login->complete(state: $state, code: 'c'));
		$this->assertSame('low', $this->sessionCalls[0][3]);
	}//end testUnknownTrustBecomesLow()


	/**
	 * T05: a state written for the OIDC route cannot complete a broker login,
	 * and integriq is not asked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-callback-redeems-the-code-once-over-the-authenticated-exchange-req-bel-003
	 */
	public function testCallbackRefusesAnOidcState(): void {
		$this->rows['row-1'] = [
			'uuid' => 'row-1', 'state' => 'oidc-state', 'nonce' => 'n', 'codeVerifier' => 'v', 'org' => 'gemeente-x',
			'provider' => 'digid', 'returnTo' => '/p', 'expiresAt' => (new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM), 'used' => false,
		];

		$this->assertNull($this->login($this->routed(), 200, $this->exchangeBody())->complete(state: 'oidc-state', code: 'c'));
		$this->assertSame([], $this->exchanged);
		$this->assertSame([], $this->sessionCalls);
	}//end testCallbackRefusesAnOidcState()


	/**
	 * T05: anything but a 200 with an envelope ends the login, and so does a
	 * replay of a callback that already succeeded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-callback-redeems-the-code-once-over-the-authenticated-exchange-req-bel-003
	 */
	public function testCallbackRefusesANon200Exchange(): void {
		foreach ([[401, '{"error":"unauthorized"}'], [200, '{"envelope":""}'], [200, 'not json'], [500, '']] as [$status, $body]) {
			$this->rows = [];
			$login = $this->login($this->routed(), $status, $body);
			$state = $this->queryOf((string)$login->start(org: 'gemeente-x', provider: 'digid', returnTo: '/p', callbackUrl: 'https://p/cb'))['relayState'];
			$this->assertSame(['token' => '', 'returnTo' => '/p'], $login->complete(state: $state, code: 'c'), $status . ' ' . $body);
		}

		$this->assertSame([], $this->sessionCalls);

		$this->rows = [];
		$this->exchanged = [];
		$login = $this->login($this->routed(), 200, $this->exchangeBody());
		$state = $this->queryOf((string)$login->start(org: 'gemeente-x', provider: 'digid', returnTo: '/p', callbackUrl: 'https://p/cb'))['relayState'];
		$this->assertNotNull($login->complete(state: $state, code: 'c'));
		$this->assertNull($login->complete(state: $state, code: 'c'), 'a replayed callback is refused');
		$this->assertCount(1, $this->exchanged, 'the replay never reached integriq');
	}//end testCallbackRefusesANon200Exchange()


	/**
	 * T06 in the flow: an envelope minted for another organisation is refused
	 * after the exchange, and no session is minted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-every-claim-portaliq-acts-on-is-checked-req-bel-004
	 */
	public function testAnEnvelopeForAnotherOrganisationIsRefused(): void {
		$login = $this->login($this->routed(), 200, $this->exchangeBody(['organisation' => 'gemeente-y']));
		$state = $this->queryOf((string)$login->start(org: 'gemeente-x', provider: 'digid', returnTo: '/p', callbackUrl: 'https://p/cb'))['relayState'];

		// A failure after the state row is spent still names where the login
		// started, so the failure message shows on that portal.
		$this->assertSame(['token' => '', 'returnTo' => '/p'], $login->complete(state: $state, code: 'c'));
		$this->assertSame([], $this->accountCalls);
	}//end testAnEnvelopeForAnotherOrganisationIsRefused()
}//end class
