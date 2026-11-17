<?php

/**
 * An IL10N double for the plain page tests: `t()` fills the parameters in
 * the English source, `n()` picks the form by count, as core does for `en`.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Builds the IL10N double.
 */
final class PlainTestWords {

	/**
	 * The double.
	 *
	 * @param TestCase $test The test that owns the mock.
	 *
	 * @return IL10N
	 */
	public static function for(TestCase $test): IL10N {
		$builder = new \PHPUnit\Framework\MockObject\MockBuilder($test, IL10N::class);
		$l10n = $builder->getMock();
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

		return $l10n;
	}//end for()
}//end class
