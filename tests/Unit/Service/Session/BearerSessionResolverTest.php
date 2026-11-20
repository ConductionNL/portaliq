<?php

/**
 * Tests for BearerSessionResolver: a live session bearer resolves, every
 * other bearer is refused.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Session
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Session;

use OCA\Portaliq\Auth\PortalSessionScope;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalJwtService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionRevoker;
use OCA\Portaliq\Kernel\Support\BearerSessionResolver;
use OCA\Portaliq\Kernel\Support\SessionRecords;
use OCA\Portaliq\Kernel\Support\SessionSigningKey;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
 */
class BearerSessionResolverTest extends TestCase {

	private const SECRET = 'unit-test-signing-secret-000000000';

	public function testALiveSessionBearerResolvesToItsSubject(): void {
		$token = (new PortalJwtService(signingSecret: self::SECRET))->createSession(
			subjectRef: 's1',
			audience: 'client',
			organisation: 'org-1',
			jti: 'live',
			trust: 'substantial'
		);

		$subject = $this->resolver(rows: ['live' => ['jti' => 'live', 'revoked' => false]])->resolveFromBearer('Bearer ' . $token);

		$this->assertSame('s1', $subject['subjectRef']);
		$this->assertSame('substantial', $subject['trust']);
		$this->assertSame('live', $subject['jti']);

	}//end testALiveSessionBearerResolvesToItsSubject()

	public function testEveryOtherBearerIsRefused(): void {
		$jwt = new PortalJwtService(signingSecret: self::SECRET);
		$live = $jwt->createSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', jti: 'live');
		$revoked = $jwt->createSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', jti: 'gone');
		$unknown = $jwt->createSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', jti: 'nobody');
		$resolver = $this->resolver(rows: ['live' => ['jti' => 'live'], 'gone' => ['jti' => 'gone', 'revoked' => 'yes']]);

		foreach ([null, '', 'Bearer ', 'bearer ' . $live, 'Basic ' . $live, 'Bearer ' . $live . 'x', 'Bearer ' . $revoked, 'Bearer ' . $unknown] as $header) {
			$this->assertNull($resolver->resolveFromBearer($header), (string)$header);
			$this->assertNull($resolver->resolveReferenceFromBearer($header), (string)$header);
		}

		$this->assertNull($resolver->resolveReferenceFromBearer('Bearer ' . $live), 'a portal session is no reference session');
		$this->assertNull($this->resolver(rows: ['live' => ['jti' => 'live']], secret: 'short')->resolveFromBearer('Bearer ' . $live));

	}//end testEveryOtherBearerIsRefused()

	/**
	 * A session minted on portal A resolves where the scope admits portal A,
	 * and is refused, exactly as an invalid bearer, where it does not.
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
	 */
	public function testAPortalBoundSessionResolvesOnlyWhereItsPortalIsAdmitted(): void {
		$token = (new PortalJwtService(signingSecret: self::SECRET))->createSession(
			subjectRef: 's1',
			audience: 'client',
			organisation: 'org-1',
			jti: 'live',
			context: ['portal' => 'portal-a']
		);
		$rows = ['live' => ['jti' => 'live', 'revoked' => false]];

		$asked = [];
		$scope = $this->createMock(PortalSessionScope::class);
		$scope->method('admits')->willReturnCallback(function (string $portal) use (&$asked): bool {
			$asked[] = $portal;
			return false;
		});
		$this->assertNull($this->resolver(rows: $rows, scope: $scope)->resolveFromBearer('Bearer ' . $token));
		$this->assertSame(['portal-a'], $asked);

		$home = $this->createMock(PortalSessionScope::class);
		$home->method('admits')->willReturn(true);
		$subject = $this->resolver(rows: $rows, scope: $home)->resolveFromBearer('Bearer ' . $token);
		$this->assertSame('portal-a', $subject['portal']);
		$this->assertSame('s1', $subject['subjectRef']);

	}//end testAPortalBoundSessionResolvesOnlyWhereItsPortalIsAdmitted()

	/**
	 * Without a scope to ask, a portal-bound session is refused, while a
	 * session bound to no portal is not asked about at all.
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
	 */
	public function testAPortalBoundSessionWithoutAScopeIsRefusedAndAnUnboundOneIsNotAsked(): void {
		$jwt   = new PortalJwtService(signingSecret: self::SECRET);
		$bound = $jwt->createSession(subjectRef: 's1', audience: 'client', organisation: 'org-1', jti: 'bound', context: ['portal' => 'portal-a']);
		$free  = $jwt->createSession(subjectRef: 's2', audience: 'client', organisation: 'org-1', jti: 'free');
		$rows  = ['bound' => ['jti' => 'bound'], 'free' => ['jti' => 'free']];

		$this->assertNull($this->resolver(rows: $rows)->resolveFromBearer('Bearer ' . $bound));

		$scope = $this->createMock(PortalSessionScope::class);
		$scope->expects($this->never())->method('admits');
		$subject = $this->resolver(rows: $rows, scope: $scope)->resolveFromBearer('Bearer ' . $free);
		$this->assertSame('s2', $subject['subjectRef']);
		$this->assertSame('', $subject['portal']);

	}//end testAPortalBoundSessionWithoutAScopeIsRefusedAndAnUnboundOneIsNotAsked()

	/**
	 * A resolver over recorded session rows, keyed by jti.
	 *
	 * @param array<string, array<string, mixed>> $rows   The recorded sessions.
	 * @param string                              $secret The configured signing secret.
	 *
	 * @return BearerSessionResolver
	 */
	private function resolver(array $rows, string $secret = self::SECRET, ?PortalSessionScope $scope = null): BearerSessionResolver {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($key === 'jwt_signing_secret' ? $secret : $default)
		);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema, string $scopeField, string $subjectRef) => (isset($rows[$subjectRef]) === true ? [$rows[$subjectRef]] : [])
		);
		$logger = $this->createMock(LoggerInterface::class);
		$revoker = new PortalSessionRevoker($reader, $this->createMock(PortalObjectWriter::class), $this->createMock(AuditTrailService::class), $logger);

		return new BearerSessionResolver(new SessionSigningKey($appConfig), new SessionRecords($reader, $revoker), $logger, $scope);

	}//end resolver()
}//end class
