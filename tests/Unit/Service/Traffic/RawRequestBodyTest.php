<?php

/**
 * Unit tests for RawRequestBody.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Kernel\Contracts\RawRequestBodyInterface;
use OCA\Portaliq\Service\Traffic\RawRequestBody;
use PHPUnit\Framework\TestCase;

/**
 * Without a request (the CLI input stream is empty) the body is the empty
 * string at any cap, never null: only a body longer than the cap is refused.
 *
 * @covers \OCA\Portaliq\Service\Traffic\RawRequestBody
 */
class RawRequestBodyTest extends TestCase {

	/**
	 * An empty input stream reads as the empty body, also with a cap of zero.
	 *
	 * @return void
	 */
	public function testAnEmptyInputStreamIsAnEmptyBody(): void {
		$body = new RawRequestBody();

		$this->assertInstanceOf(RawRequestBodyInterface::class, $body);
		$this->assertSame('', $body->read(max: 65536));
		$this->assertSame('', $body->read(max: 0));
	}//end testAnEmptyInputStreamIsAnEmptyBody()
}//end class
