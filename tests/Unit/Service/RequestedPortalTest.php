<?php

/**
 * Portaliq Requested Portal Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
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
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\RequestedPortal;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The header names the portal, the query parameter is the fallback, and
 * nothing named is null.
 *
 * @covers \OCA\Portaliq\Service\RequestedPortal
 */
class RequestedPortalTest extends TestCase {

	/**
	 * A request carrying the given header and query parameter.
	 *
	 * @param string $header The `X-Portaliq-Portal` header.
	 * @param mixed  $param  The `portal` query parameter.
	 *
	 * @return IRequest
	 */
	private function request(string $header, mixed $param): IRequest {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'X-Portaliq-Portal' ? $header : ''));
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($key === 'portal' ? $param : null));

		return $request;
	}//end request()

	/**
	 * The header wins over the query parameter.
	 *
	 * @return void
	 */
	public function testTheHeaderWins(): void {
		$this->assertSame(expected: 'zuid', actual: (new RequestedPortal())->slug(request: $this->request(header: ' zuid ', param: 'noord')));
	}//end testTheHeaderWins()

	/**
	 * Without the header, the query parameter names the portal.
	 *
	 * @return void
	 */
	public function testTheQueryParameterIsTheFallback(): void {
		$this->assertSame(expected: 'noord', actual: (new RequestedPortal())->slug(request: $this->request(header: '', param: ' noord ')));
	}//end testTheQueryParameterIsTheFallback()

	/**
	 * A request naming no portal, or naming it with something other than text, names none.
	 *
	 * @return void
	 */
	public function testNothingNamedIsNull(): void {
		$this->assertNull(actual: (new RequestedPortal())->slug(request: $this->request(header: '  ', param: null)));
		$this->assertNull(actual: (new RequestedPortal())->slug(request: $this->request(header: '', param: ['zuid'])));
		$this->assertNull(actual: (new RequestedPortal())->slug(request: $this->request(header: '', param: '  ')));
	}//end testNothingNamedIsNull()
}//end class
