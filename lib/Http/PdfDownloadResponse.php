<?php

/**
 * Portaliq PDF Download Response
 *
 * A PDF held in memory, sent as an attachment.
 *
 * @category Http
 * @package  OCA\Portaliq\Http
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
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Http;

use OCP\AppFramework\Http\Response;

/**
 * Plain bytes with the headers of a download, and nothing the framework has to
 * guess: the name is reduced to safe characters here.
 *
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t02
 */
class PdfDownloadResponse extends Response {

	/**
	 * The file's name, with its extension.
	 *
	 * @var string
	 */
	private readonly string $fileName;

	/**
	 * Constructor.
	 *
	 * @param string $bytes The PDF.
	 * @param string $name The file's name without its extension.
	 */
	public function __construct(private readonly string $bytes, string $name) {
		$this->fileName = $this->safeName(name: $name);
		parent::__construct();
		$this->addHeader(name: 'Content-Type', value: 'application/pdf');
		$this->addHeader(name: 'Content-Disposition', value: 'attachment; filename="'.$this->fileName.'"');
		$this->addHeader(name: 'Content-Length', value: (string)strlen($bytes));
		$this->addHeader(name: 'Cache-Control', value: 'private, no-store');
	}//end __construct()

	/**
	 * The file's name as it is sent.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t02
	 */
	public function fileName(): string {
		return $this->fileName;
	}//end fileName()

	/**
	 * Reduce a name to lower-case letters, digits and hyphens.
	 *
	 * @param string $name The wanted name.
	 *
	 * @return string The safe file name.
	 */
	private function safeName(string $name): string {
		$safe = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
		if ($safe === '') {
			$safe = 'export';
		}

		return $safe.'.pdf';
	}//end safeName()

	/**
	 * The body.
	 *
	 * @return string The PDF.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t02
	 */
	public function render(): string {
		return $this->bytes;
	}//end render()
}//end class
