<?php

/**
 * Golden test for PortalDomainVerifier::probeCertificate.
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

namespace OCA\Portaliq\Service\Cms {

	use OCA\Portaliq\Tests\Unit\Service\Cms\ProbeNetwork;

	/**
	 * Name resolution as the world has it; the real one outside a golden run.
	 *
	 * @param string $hostname The host.
	 *
	 * @return string The address.
	 */
	function gethostbyname(string $hostname): string {
		return ProbeNetwork::$active?->resolve(host: $hostname) ?? \gethostbyname($hostname);
	}//end gethostbyname()

	/**
	 * Records the options and hands over a real context.
	 *
	 * @param array<string, mixed> $options The context options.
	 *
	 * @return resource The context.
	 */
	function stream_context_create(array $options) {
		ProbeNetwork::$active?->note(call: ['context', $options]);
		return \stream_context_create($options);
	}//end stream_context_create()

	// phpcs:disable Squiz.NamingConventions.ValidVariableName -- lib calls PHP's stream_socket_client by these parameter names.
	/**
	 * The connection as the world has it, checked for a silenced warning handler.
	 *
	 * @param string   $address       The address.
	 * @param int|null $error_code    Set to the world's error code.
	 * @param string   $error_message Set to the world's error message.
	 * @param float    $timeout       The timeout.
	 * @param int      $flags         The flags.
	 * @param resource $context       The context.
	 *
	 * @return mixed A socket stand-in, or false.
	 */
	function stream_socket_client(
		string $address,
		&$error_code = null,
		&$error_message = null,
		?float $timeout = null,
		int $flags = STREAM_CLIENT_CONNECT,
		$context = null,
	) {
		return ProbeNetwork::$active->connect(address: $address, timeout: $timeout, flags: $flags, code: $error_code, message: $error_message);
		// phpcs:enable Squiz.NamingConventions.ValidVariableName
	}//end stream_socket_client()

	/**
	 * The certificate the world's connection holds.
	 *
	 * @param mixed $stream The socket stand-in.
	 *
	 * @return array<string, mixed> The options.
	 */
	function stream_context_get_params($stream): array {
		return ProbeNetwork::$active->params();
	}//end stream_context_get_params()

	/**
	 * Records the close.
	 *
	 * @param mixed $stream The socket stand-in.
	 *
	 * @return bool True.
	 */
	function fclose($stream): bool {
		ProbeNetwork::$active->note(call: ['close']);
		return true;
	}//end fclose()

	/**
	 * The parsed certificate as the world has it.
	 *
	 * @param mixed $certificate The certificate stand-in.
	 *
	 * @return mixed The parse.
	 */
	function openssl_x509_parse($certificate) {
		return ProbeNetwork::$active->parse(certificate: $certificate);
	}//end openssl_x509_parse()
}

namespace OCA\Portaliq\Tests\Unit\Service\Cms {

	use Closure;
	use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	/**
	 * The network one golden world answers with, and what was asked of it.
	 */
	final class ProbeNetwork {
		/**
		 * The world in play; null leaves every function to the real network.
		 *
		 * @var ProbeNetwork|null
		 */
		public static ?ProbeNetwork $active = null;

		/**
		 * What was asked, in order.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		public array $calls = [];

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $world The world.
		 */
		public function __construct(private readonly array $world) {
		}//end __construct()

		/**
		 * Record a call.
		 *
		 * @param array<int, mixed> $call The call.
		 *
		 * @return void
		 */
		public function note(array $call): void {
			$this->calls[] = $call;
		}//end note()

		/**
		 * Resolve a host.
		 *
		 * @param string $host The host.
		 *
		 * @return string The world's address, or the host when it does not resolve.
		 */
		public function resolve(string $host): string {
			$this->note(call: ['resolve', $host]);
			return ($this->world['address'] ?? $host);
		}//end resolve()

		/**
		 * Connect.
		 *
		 * @param string   $address The address.
		 * @param float    $timeout The timeout.
		 * @param int      $flags   The flags.
		 * @param int|null $code    Set to the error code.
		 * @param mixed    $message Set to the error message.
		 *
		 * @return mixed The stand-in, or false.
		 */
		public function connect(string $address, ?float $timeout, int $flags, &$code, &$message) {
			$handler = set_error_handler(static fn (): bool => false);
			restore_error_handler();
			$silenced = ($handler instanceof Closure) && $handler() === true;
			$this->note(call: ['connect', $address, (int)$timeout, $flags, $silenced]);
			$code    = ($this->world['errorCode'] ?? 0);
			$message = ($this->world['errorMessage'] ?? '');
			if (($this->world['throws'] ?? false) === true) {
				throw new RuntimeException('connection broke');
			}

			return ($this->world['socket'] ?? 'socket');
		}//end connect()

		/**
		 * The stream's options.
		 *
		 * @return array<string, mixed> The options.
		 */
		public function params(): array {
			$certificate = 'certificate';
			if (array_key_exists('certificate', $this->world) === true) {
				$certificate = $this->world['certificate'];
			}

			return ['options' => ['ssl' => ['peer_certificate' => $certificate]]];
		}//end params()

		/**
		 * Parse a certificate.
		 *
		 * @param mixed $certificate The stand-in.
		 *
		 * @return mixed The world's parse.
		 */
		public function parse($certificate) {
			$this->note(call: ['parse', $certificate]);
			if (array_key_exists('parsed', $this->world) === true) {
				return $this->world['parsed'];
			}

			return ['validTo_time_t' => '1893456000'];
		}//end parse()
	}//end class

	/**
	 * What the probe asks of the network per world: only a public address is
	 * contacted, by address and not by name, and nothing but the end date is read.
	 *
	 * @covers \OCA\Portaliq\Service\Cms\PortalDomainVerifier
	 */
	class PortalDomainProbeGoldenTest extends TestCase {

		private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/portal-domain-probe.json';

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			ProbeNetwork::$active = null;
			parent::tearDown();
		}//end tearDown()

		/**
		 * @return void
		 */
		public function testEveryWorldMatchesTheGolden(): void {
			$actual = [];
			foreach ($this->worlds() as $name => $world) {
				$actual[$name] = $this->trace(world: $world);
			}

			if (getenv('GOLDEN_RECORD') === '1') {
				file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			}

			$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
			$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
			foreach ($expected as $name => $case) {
				$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
			}
		}//end testEveryWorldMatchesTheGolden()

		/**
		 * Probe in one world.
		 *
		 * @param array<string, mixed> $world The world.
		 *
		 * @return array<string, mixed> `calls`, the answer, and whether the handler came back.
		 */
		private function trace(array $world): array {
			ProbeNetwork::$active = new ProbeNetwork(world: $world);
			$marker = static fn (): bool => false;
			set_error_handler($marker);
			try {
				$answer = PortalDomainVerifier::probeCertificate(host: ($world['host'] ?? 'www.example.org'));
			} catch (RuntimeException $error) {
				$answer = 'threw ' . $error->getMessage();
			}

			$handler = set_error_handler(static fn (): bool => false);
			restore_error_handler();
			restore_error_handler();

			return ['calls' => ProbeNetwork::$active->calls, 'answer' => $answer, 'handlerRestored' => $handler === $marker];
		}//end trace()

		/**
		 * The worlds.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		private function worlds(): array {
			$public = ['address' => '93.184.216.34'];

			$worlds = [
				'does not resolve'         => [],
				'public, a certificate'    => $public,
				'socket false'             => $public + ['socket' => false, 'errorCode' => 110, 'errorMessage' => 'timed out'],
				'socket false, no error'   => $public + ['socket' => false],
				'error code and message'   => $public + ['errorCode' => 5, 'errorMessage' => 'refused'],
				'error code, no message'   => $public + ['errorCode' => 5],
				'error message, no code'   => $public + ['errorMessage' => 'odd'],
				'no certificate'           => $public + ['certificate' => null],
				'parse fails'              => $public + ['parsed' => false],
				'parse without an end'     => $public + ['parsed' => ['subject' => 'x']],
				'parse with a text end'    => $public + ['parsed' => ['validTo_time_t' => '12']],
				'connection throws'        => $public + ['throws' => true],
				'not an address'           => ['address' => 'not-an-ip'],
				'a public address as host' => ['host' => '93.184.216.34'],
			];
			foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '240.0.0.1', '255.255.255.255'] as $blocked) {
				$worlds['refused ' . $blocked] = ['address' => $blocked];
			}

			return $worlds;
		}//end worlds()
	}//end class
}
