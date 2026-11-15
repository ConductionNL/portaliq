<?php

/**
 * Request double for the parameter handling ContributionLanguage relies on.
 * `setUrlParameters()`, `getParam()` and the `urlParams` property behave as in
 * Nextcloud's `OC\AppFramework\Http\Request` (server master, 2026-09-22):
 * setting URL parameters replaces `urlParams` and merges them over the
 * request parameters, and `getParam()` answers the default for a key whose
 * value is null. The unit tests run without Nextcloud's private classes, so
 * this double carries that behaviour; everything else is inert.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Doubles;

use OCP\IRequest;

/**
 * A request whose parameters and headers a test chooses.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- IRequest's own surface.
 */
class UrlParameterRequest implements IRequest {
	/**
	 * The parameters merged from URL and query, as Request keeps them.
	 *
	 * @var array<string, mixed>
	 */
	private array $parameters;

	/**
	 * The URL parameters (read through `urlParams`, as on Request).
	 *
	 * @var array<string, mixed>
	 */
	private array $urlParameters = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $headers    Header name to value.
	 * @param array<string, mixed>  $parameters Query parameters.
	 */
	public function __construct(
		private readonly array $headers=[],
		array $parameters=[],
	) {
		$this->parameters = $parameters;
	}//end __construct()

	/**
	 * As `Request::__get('urlParams')`.
	 *
	 * @param string $name The property.
	 *
	 * @return mixed
	 */
	public function __get(string $name): mixed {
		if ($name === 'urlParams') {
			return $this->urlParameters;
		}

		return null;
	}//end __get()

	/**
	 * As `Request::setUrlParameters()`.
	 *
	 * @param array<string, mixed> $parameters The URL parameters.
	 *
	 * @return void
	 */
	public function setUrlParameters(array $parameters): void {
		$this->urlParameters = $parameters;
		$this->parameters = array_merge($this->parameters, $parameters);
	}//end setUrlParameters()

	public function getHeader(string $name): string {
		foreach ($this->headers as $key => $value) {
			if (strcasecmp($key, $name) === 0) {
				return $value;
			}
		}

		return '';
	}//end getHeader()

	public function getParam(string $key, $default=null) {
		return isset($this->parameters[$key]) ? $this->parameters[$key] : $default;
	}//end getParam()

	public function getParams(): array {
		return $this->parameters;
	}//end getParams()

	public function getMethod(): string {
		return 'GET';
	}//end getMethod()

	public function getUploadedFile(string $key) {
		return null;
	}//end getUploadedFile()

	public function getEnv(string $key) {
		return null;
	}//end getEnv()

	public function getCookie(string $key) {
		return null;
	}//end getCookie()

	public function passesCSRFCheck(): bool {
		return false;
	}//end passesCSRFCheck()

	public function passesStrictCookieCheck(): bool {
		return false;
	}//end passesStrictCookieCheck()

	public function passesLaxCookieCheck(): bool {
		return false;
	}//end passesLaxCookieCheck()

	public function getId(): string {
		return 'test';
	}//end getId()

	public function getRemoteAddress(): string {
		return '127.0.0.1';
	}//end getRemoteAddress()

	public function getServerProtocol(): string {
		return 'https';
	}//end getServerProtocol()

	public function getHttpProtocol(): string {
		return 'HTTP/1.1';
	}//end getHttpProtocol()

	public function getRequestUri(): string {
		return '/';
	}//end getRequestUri()

	public function getRawPathInfo(): string {
		return '/';
	}//end getRawPathInfo()

	public function getPathInfo() {
		return '/';
	}//end getPathInfo()

	public function getScriptName(): string {
		return 'index.php';
	}//end getScriptName()

	public function isUserAgent(array $agent): bool {
		return false;
	}//end isUserAgent()

	public function getInsecureServerHost(): string {
		return 'localhost';
	}//end getInsecureServerHost()

	public function getServerHost(): string {
		return 'localhost';
	}//end getServerHost()

	public function throwDecodingExceptionIfAny(): void {
	}//end throwDecodingExceptionIfAny()

	public function getFormat(): ?string {
		return null;
	}//end getFormat()
}//end class
