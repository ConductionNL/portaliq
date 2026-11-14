<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ContributionLanguage;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Tests\Doubles\UrlParameterRequest;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An exact locale match wins, and a request whose URL parameters do not read
 * as an array starts the forced language from an empty set.
 */
#[CoversClass(ContributionLanguage::class)]
class ContributionLanguageParametersTest extends TestCase {

	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('force_language', false)->willReturn(false);
		return $config;
	}//end config()

	public function testAnExactRegionalLocaleIsPreferredByItsOwnSpelling(): void {
		$request = new UrlParameterRequest(['Accept-Language' => 'en_GB;q=0.8']);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['locales' => ['nl', ' en_GB ']]);

		$this->assertSame('en_GB', (new ContributionLanguage($request, $this->config(), $portals))->languageFor());
	}//end testAnExactRegionalLocaleIsPreferredByItsOwnSpelling()

	public function testNonArrayUrlParametersAreTreatedAsNone(): void {
		$request = new class extends UrlParameterRequest {

			public array $written = [];

			public function __get(string $name): mixed {
				return 'not-an-array';
			}

			public function setUrlParameters(array $parameters): void {
				$this->written[] = $parameters;
				parent::setUrlParameters($parameters);
			}
		};

		$language = new ContributionLanguage($request, $this->config(), $this->createMock(PortalResolver::class));
		$this->assertSame('nl', $language->speak('nl', static fn () => $request->getParam(ContributionLanguage::PARAMETER)));
		$this->assertSame([[ContributionLanguage::PARAMETER => 'nl'], [ContributionLanguage::PARAMETER => null]], $request->written);
	}//end testNonArrayUrlParametersAreTreatedAsNone()
}//end class
