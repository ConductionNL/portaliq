<?php

/**
 * The error report channel is wired: its notifier and its two routes.
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
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Application registers the report notifier, and routes.php maps the public
 * report route and the owner's close route to the controller.
 *
 * @coversNothing
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
class PublicationReportWiringTest extends TestCase {
	/**
	 * The notifier is registered beside the traffic notifier.
	 *
	 * @return void
	 */
	public function testTheNotifierIsRegistered(): void {
		$application = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');

		$this->assertStringContainsString(needle: '$context->registerNotifierService(PublicationReportNotifier::class);', haystack: $application);
		$this->assertStringContainsString(needle: 'use OCA\Portaliq\Notification\PublicationReportNotifier;', haystack: $application);
	}//end testTheNotifierIsRegistered()

	/**
	 * Both routes point at the controller with the verbs the site and the
	 * admin page use.
	 *
	 * @return void
	 */
	public function testTheRoutesAreMapped(): void {
		$routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$found  = [];
		foreach ($routes['routes'] as $route) {
			if (str_starts_with($route['name'], 'publicationReport#') === true) {
				$found[$route['name']] = [$route['url'], $route['verb']];
			}
		}

		$this->assertSame(
			expected: [
				'publicationReport#create' => ['/api/site/publication-reports', 'POST'],
				'publicationReport#close'  => ['/api/publication-reports/{id}/close', 'POST'],
			],
			actual: $found
		);
	}//end testTheRoutesAreMapped()
}//end class
