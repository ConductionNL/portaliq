<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalPdfExport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The PDF export wrapper: service discovery, rendering outcomes and row shaping.
 */
#[CoversClass(PortalPdfExport::class)]
class PortalPdfExportTest extends TestCase {
	/**
	 * Build the export over a container that hands out the given service.
	 *
	 * @param object|null $service The export service, or null for a throwing container.
	 * @param string|null $class   The service class name to look up.
	 *
	 * @return PortalPdfExport
	 */
	private function export(?object $service, ?string $class=null): PortalPdfExport {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new \RuntimeException('cannot build'));
		} else {
			$container->method('get')->willReturn($service);
		}

		return new PortalPdfExport($container, $this->createMock(LoggerInterface::class), ($class ?? \stdClass::class));
	}//end export()

	/**
	 * Availability needs the class, a buildable service and the render method.
	 *
	 * @return void
	 */
	public function testAvailable(): void {
		$renderer = new class() {
			public function renderRowsToPdf(string $t, array $c, array $r): string {
				return '%PDF-1';
			}
		};

		$this->assertTrue($this->export($renderer)->available());
		$this->assertFalse($this->export($renderer, 'No\\Such\\Class')->available());
		$this->assertFalse($this->export(null)->available());
		$this->assertFalse($this->export(new \stdClass())->available());
	}//end testAvailable()

	/**
	 * Rendering returns PDF bytes, or null for anything else.
	 *
	 * @return void
	 */
	public function testRender(): void {
		$good = new class() {
			public function renderRowsToPdf(string $t, array $c, array $r): string {
				return '%PDF-1 ' . $t;
			}
		};
		$notPdf = new class() {
			public function renderRowsToPdf(string $t, array $c, array $r): string {
				return 'html';
			}
		};
		$broken = new class() {
			public function renderRowsToPdf(string $t, array $c, array $r): string {
				throw new \RuntimeException('boom');
			}
		};

		$this->assertSame('%PDF-1 T', $this->export($good)->render('T', [], []));
		$this->assertNull($this->export($notPdf)->render('T', [], []));
		$this->assertNull($this->export($broken)->render('T', [], []));
		$this->assertNull($this->export(null)->render('T', [], []));
	}//end testRender()

	/**
	 * An ExportTooLargeException is reported as TOO_LARGE.
	 *
	 * @return void
	 */
	public function testRenderTooLarge(): void {
		$service = new class() {
			public function renderRowsToPdf(string $t, array $c, array $r): string {
				throw new ExportTooLargeException('big');
			}
		};

		$this->assertSame(PortalPdfExport::TOO_LARGE, $this->export($service)->render('T', [], []));
	}//end testRenderTooLarge()

	/**
	 * List columns come from the columns, else the fields.
	 *
	 * @return void
	 */
	public function testListColumns(): void {
		$e = $this->export(null);

		$this->assertSame(
			[['key' => 'a', 'label' => 'A', 'valueLabels' => ['1' => 'one']], ['key' => 'b', 'label' => 'b']],
			$e->listColumns(['columns' => [['field' => 'a', 'label' => 'A', 'valueLabels' => ['1' => 'one']], ['field' => 'b'], ['field' => 5], 'junk']])
		);
		$this->assertSame([['key' => 'x', 'label' => 'x']], $e->listColumns(['fields' => ['x', '', 3]]));
		$this->assertSame([], $e->listColumns([]));
	}//end testListColumns()

	/**
	 * Record columns follow the detail fields, labelled from the list columns.
	 *
	 * @return void
	 */
	public function testRecordColumns(): void {
		$e          = $this->export(null);
		$collection = ['columns' => [['field' => 'a', 'label' => 'A']], 'detail' => ['fields' => ['b', 'a', 7]]];

		$this->assertSame([['key' => 'b', 'label' => 'b'], ['key' => 'a', 'label' => 'A']], $e->recordColumns($collection));
		$this->assertSame([['key' => 'a', 'label' => 'A']], $e->recordColumns(['columns' => [['field' => 'a', 'label' => 'A']], 'detail' => ['fields' => []]]));
	}//end testRecordColumns()

	/**
	 * Rows become text cells: booleans, lists and labelled values.
	 *
	 * @return void
	 */
	public function testTextRowsAndTitle(): void {
		$e       = $this->export(null);
		$columns = [['key' => 'ok'], ['key' => 'tags'], ['key' => 'state', 'valueLabels' => ['o' => 'Open']], ['key' => 'gone'], ['key' => 'obj']];

		$rows = $e->textRows($columns, [['ok' => true, 'tags' => ['a', false], 'state' => 'o', 'obj' => new \stdClass()], 'junk', ['ok' => false, 'state' => 'x']]);

		$this->assertSame(['ok' => 'true', 'tags' => 'a, false', 'state' => 'Open', 'gone' => '', 'obj' => ''], $rows[0]);
		$this->assertSame(['ok' => 'false', 'tags' => '', 'state' => 'x', 'gone' => '', 'obj' => ''], $rows[1]);
		$this->assertCount(2, $rows);

		$this->assertSame('Report, Zuid, 2026-05-10', $e->title('Report', 'Zuid', '2026-05-10'));
		$this->assertSame('Report', $e->title('Report', '', ''));
	}//end testTextRowsAndTitle()
}

/**
 * Stand-in for OpenRegister's export-size exception (matched by short name).
 */
class ExportTooLargeException extends \RuntimeException {
}
