<?php

/**
 * Unit tests for TrafficToken and TrafficImportLog.
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

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\TrafficImportLog;
use OCA\Portaliq\Command\TrafficToken;
use OCA\Portaliq\Service\Traffic\TrafficLogImporter;
use OCA\Portaliq\Service\Traffic\TrafficServerToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The token is printed once and an unknown portal exits 1; the import
 * reports its counts and refuses a bad format or file.
 */
class TrafficReportingCommandsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTheTokenCommandPrintsTheTokenOnce(): void {
		$tokens = $this->createMock(TrafficServerToken::class);
		$tokens->method('issue')->willReturnCallback(static fn (string $slug): ?string => ($slug === 'open-tilburg') ? 'the-token' : null);

		$output = new BufferedOutput();
		$code = (new TrafficToken($tokens))->run(new ArrayInput(['portal' => 'open-tilburg']), $output);
		$this->assertSame(0, $code);
		$this->assertStringContainsString("the-token\n", $output->fetch());

		$output = new BufferedOutput();
		$code = (new TrafficToken($tokens))->run(new ArrayInput(['portal' => 'nope']), $output);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('No published portal', $output->fetch());
	}//end testTheTokenCommandPrintsTheTokenOnce()


	/**
	 * @return void
	 */
	public function testTheImportCommandReportsAndRefuses(): void {
		$importer = $this->createMock(TrafficLogImporter::class);
		$importer->method('import')->willReturnCallback(
			static function (string $slug, $stream, string $format, string $host): ?array {
				if ($slug !== 'open-tilburg') {
					return null;
				}

				return ['lines' => 3, 'views' => 2, 'skipped' => 1, 'duplicates' => 0, 'accepted' => 2, 'refused' => []];
			}
		);
		$file = tempnam(sys_get_temp_dir(), 'log');
		file_put_contents($file, "x\n");

		$output = new BufferedOutput();
		$code = (new TrafficImportLog($importer))->run(new ArrayInput(['portal' => 'open-tilburg', 'file' => $file, '--format' => 'json']), $output);
		$this->assertSame(0, $code);
		$text = $output->fetch();
		$this->assertStringContainsString('Lines read: 3', $text);
		$this->assertStringContainsString('Accepted: 2', $text);

		$output = new BufferedOutput();
		$this->assertSame(1, (new TrafficImportLog($importer))->run(new ArrayInput(['portal' => 'nope', 'file' => $file]), $output));
		$this->assertStringContainsString('No published portal', $output->fetch());

		$output = new BufferedOutput();
		$this->assertSame(1, (new TrafficImportLog($importer))->run(new ArrayInput(['portal' => 'open-tilburg', 'file' => $file, '--format' => 'xml']), $output));
		$this->assertStringContainsString('Unknown format', $output->fetch());

		$output = new BufferedOutput();
		$this->assertSame(1, (new TrafficImportLog($importer))->run(new ArrayInput(['portal' => 'open-tilburg', 'file' => $file . '.missing']), $output));
		$this->assertStringContainsString('Cannot read', $output->fetch());
		unlink($file);
	}//end testTheImportCommandReportsAndRefuses()
}//end class
