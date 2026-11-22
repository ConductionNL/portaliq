<?php

/**
 * A genuine portal bearer opens a portal route and invokes no tool.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-mcp-tools-are-unreachable-from-the-external-portal-edge
 * @spec openspec/changes/hermiq-ai-tooling/tasks.md#task-4-prove-the-portal-edge-cannot-reach-mcp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Mcp;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Support\BearerSessionResolver;
use OCA\Portaliq\Kernel\Support\SessionRecords;
use OCA\Portaliq\Kernel\Support\SessionSigningKey;
use OCA\Portaliq\Mcp\PortaliqToolProvider;
use OCA\Portaliq\Mcp\StaffObjectStore;
use OCA\Portaliq\Middleware\PortalAuthMiddleware;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\PortalJwtService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalRegionResolver;
use OCA\Portaliq\Service\PortalSessionRevoker;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Both halves in one run: the same valid, unexpired bearer authenticates on a
 * bearer-guarded portal route (so the fixture is genuinely valid), and every
 * tool answers it exactly as it answers a forged bearer.
 */
#[CoversNothing]
class PortalBearerReachesNoToolTest extends TestCase {

	/**
	 * The signing secret the bearer is minted and checked with.
	 *
	 * @var string
	 */
	private const SECRET = 'unit-test-signing-secret-000000000';

	/**
	 * A live session bearer, minted the way a portal sign-in mints one.
	 *
	 * @return string
	 */
	private function validBearer(): string {
		return 'Bearer ' . (new PortalJwtService(signingSecret: self::SECRET))->createSession(
			subjectRef: 'resident-1',
			audience: 'client',
			organisation: 'org-1',
			jti: 'live',
			trust: 'substantial'
		);
	}//end validBearer()

	/**
	 * The real bearer resolver over one recorded, unrevoked session row.
	 *
	 * @return BearerSessionResolver
	 */
	private function resolver(): BearerSessionResolver {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['portaliq', 'jwt_signing_secret', '', self::SECRET],
			]
		);
		$reader = $this->createMock(originalClassName: PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef): array {
				if ($subjectRef !== 'live') {
					return [];
				}

				return [['jti' => 'live', 'revoked' => false]];
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$revoker = new PortalSessionRevoker(
			reader: $reader,
			writer: $this->createMock(originalClassName: PortalObjectWriter::class),
			auditor: $this->createMock(originalClassName: AuditTrailService::class),
			logger: $logger
		);

		return new BearerSessionResolver(
			signingKey: new SessionSigningKey(appConfig: $appConfig),
			records: new SessionRecords(reader: $reader, revoker: $revoker),
			logger: $logger
		);
	}//end resolver()

	/**
	 * The tool provider as Nextcloud wires it for a request that carries a
	 * portal bearer: Nextcloud's own authentication does not read portal
	 * bearers, so no Nextcloud user is signed in.
	 *
	 * @return PortaliqToolProvider
	 */
	private function provider(): PortaliqToolProvider {
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$auditor = $this->createMock(originalClassName: AuditTrailService::class);
		$auditor->expects($this->never())->method('record');

		return new PortaliqToolProvider(
			userSession: $userSession,
			groups: $this->createMock(originalClassName: IGroupManager::class),
			editors: $this->createMock(originalClassName: PageEditorService::class),
			objects: $this->createMock(originalClassName: StaffObjectStore::class),
			regions: new PortalRegionResolver(),
			themes: $this->createMock(originalClassName: PortalThemeChoice::class),
			sessions: $this->createMock(originalClassName: PortalSessionService::class),
			traffic: $this->createMock(originalClassName: TrafficEventStore::class),
			numbers: $this->createMock(originalClassName: TrafficReportNumbers::class),
			auditor: $auditor,
		);
	}//end provider()

	/**
	 * Every tool's answer to a call that carries the given bearer.
	 *
	 * @param string $bearer The Authorization header of the call.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function answers(string $bearer): array {
		$provider = $this->provider();
		$answers = [];
		foreach ($provider->getTools() as $tool) {
			$answers[$tool['id']] = $provider->invokeTool(
				toolId: $tool['id'],
				arguments: ['authorization' => $bearer, 'organisation' => 'org-1', 'id' => 'x', 'portal' => 'p']
			);
		}

		return $answers;
	}//end answers()

	/**
	 * The control half: the bearer is genuinely valid, so the bearer guard
	 * lets it through to a protected portal route.
	 *
	 * @return void
	 */
	public function testTheBearerAuthenticatesOnAProtectedPortalRoute(): void {
		$bearer = $this->validBearer();
		$subject = $this->resolver()->resolveFromBearer(authorizationHeader: $bearer);
		$this->assertSame(expected: 'resident-1', actual: ($subject['subjectRef'] ?? null));

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', $bearer]]);
		$middleware = new PortalAuthMiddleware(
			request: $request,
			session: $this->resolver(),
			registry: $this->createMock(originalClassName: PortalContributionRegistryInterface::class),
			l10n: $this->createMock(originalClassName: IL10N::class)
		);

		// No exception: the guard accepts the session.
		$middleware->beforeController(
			controller: new class implements PortalProtected {
			},
			methodName: 'index'
		);
		$this->addToAssertionCount(count: 1);
	}//end testTheBearerAuthenticatesOnAProtectedPortalRoute()

	/**
	 * The guarded half: the same bearer invokes no tool, and the refusal is
	 * the very answer a forged bearer gets.
	 *
	 * @return void
	 */
	public function testTheSameBearerIsRefusedByEveryToolLikeAForgedOne(): void {
		$valid = $this->answers(bearer: $this->validBearer());
		$forged = $this->answers(bearer: 'Bearer not.a.token');

		$this->assertCount(expectedCount: 7, haystack: $valid);
		$this->assertSame(expected: $forged, actual: $valid);
		foreach ($valid as $id => $answer) {
			$this->assertSame(expected: 'unauthenticated', actual: ($answer['error'] ?? null), message: $id . ' accepted a portal bearer');
		}
	}//end testTheSameBearerIsRefusedByEveryToolLikeAForgedOne()

	/**
	 * Listing and invocation take their identity from the Nextcloud user
	 * session only: the provider has no request and no bearer resolver to
	 * read a portal bearer from.
	 *
	 * @return void
	 */
	public function testTheProviderCannotReadABearer(): void {
		$constructor = (new ReflectionClass(objectOrClass: PortaliqToolProvider::class))->getConstructor();
		$types = [];
		foreach ($constructor?->getParameters() ?? [] as $parameter) {
			$types[] = (string)$parameter->getType();
		}

		$this->assertContains(needle: IUserSession::class, haystack: $types);
		$this->assertNotContains(needle: IRequest::class, haystack: $types);
		$this->assertNotContains(needle: BearerSessionResolver::class, haystack: $types);
		$this->assertSame(expected: [], actual: preg_grep(pattern: '/Bearer|PortalJwt|PortalSessionScope/', array: $types));
	}//end testTheProviderCannotReadABearer()
}//end class
