<?php

/**
 * Portaliq Traffic Gzip Extractor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic\Geo;

use RuntimeException;

/**
 * Streams a gzip file, or one member of a gzipped tar, to disk.
 *
 * Streaming because the uncompressed city database is over a hundred
 * megabytes, and `gzdecode()` on a string of that size is a memory limit
 * hit on an ordinary PHP-FPM worker. Both archive shapes the providers
 * ship are handled here: DB-IP is a bare gzip, MaxMind is a tarball with
 * the database one directory down.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */
class GzipExtractor {

	/**
	 * Bytes per read.
	 */
	private const CHUNK = 1048576;

	/**
	 * A tar header block.
	 */
	private const BLOCK = 512;

	/**
	 * Decompress a bare gzip file to the target.
	 *
	 * @param string $source The .gz file.
	 * @param string $target The output path.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When either file cannot be handled.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function extract(string $source, string $target): void {
		$archive = $this->open(source: $source);
		$out = $this->create(target: $target);
		if ($out === null) {
			gzclose($archive);
			throw new RuntimeException('The target file cannot be written.');
		}

		$written = 0;
		while (gzeof($archive) === false) {
			$chunk = gzread($archive, self::CHUNK);
			if ($chunk === false) {
				break;
			}

			$written += (int)fwrite($out, $chunk);
		}

		gzclose($archive);
		fclose($out);
		if ($written === 0) {
			throw new RuntimeException('The download decompressed to nothing.');
		}
	}

	/**
	 * Extract the first member whose name ends in the suffix from a
	 * gzipped tar, to the target.
	 *
	 * A minimal ustar reader: a 512-byte header with the name at 0, the
	 * octal size at 124, the type at 156 and the ustar prefix at 345, then
	 * the content padded to a block. Nothing more is needed to find one
	 * file, and a hand-written reader has no `phar` setting to trip over.
	 *
	 * @param string $source The .tar.gz file.
	 * @param string $suffix The member name suffix, such as `.mmdb`.
	 * @param string $target The output path.
	 *
	 * @return string The member name that was extracted.
	 *
	 * @throws RuntimeException When no such member exists.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function extractTarMember(string $source, string $suffix, string $target): string {
		$archive = $this->open(source: $source);
		try {
			while (true) {
				$member = $this->header(archive: $archive);
				if ($member === null) {
					break;
				}

				if ($member['file'] === true && str_ends_with($member['name'], $suffix) === true) {
					$this->copyMember(archive: $archive, size: $member['size'], target: $target);

					return $member['name'];
				}

				$this->skip(archive: $archive, bytes: $this->padded(size: $member['size']));
			}
		} finally {
			gzclose($archive);
		}

		throw new RuntimeException('The archive carries no ' . $suffix . ' member.');
	}

	/**
	 * Open a gzip file for reading.
	 *
	 * @param string $source The .gz file.
	 *
	 * @return resource The open archive.
	 *
	 * @throws RuntimeException When it is not a readable gzip file.
	 */
	private function open(string $source) {
		// A plain file opens transparently in gzopen(), so an HTML error page
		// answered with a 200 would "decompress" to itself. The magic
		// bytes are checked first: a download that is not gzip is refused
		// here by name rather than a step later as "not a database".
		$archive = false;
		if (is_readable($source) === true && $this->isGzip(source: $source) === true) {
			$archive = gzopen($source, 'rb');
		}

		if ($archive === false) {
			throw new RuntimeException('The download is not a gzip file.');
		}

		return $archive;
	}

	/**
	 * Whether a file starts with the gzip magic bytes.
	 *
	 * @param string $source The file.
	 *
	 * @return bool True for gzip.
	 */
	private function isGzip(string $source): bool {
		$handle = fopen($source, 'rb');
		if ($handle === false) {
			return false;
		}

		$magic = fread($handle, 2);
		fclose($handle);

		return $magic === "\x1f\x8b";
	}

	/**
	 * Open the target for writing.
	 *
	 * @param string $target The output path.
	 *
	 * @return resource|null The handle, or null when the directory refuses.
	 */
	private function create(string $target) {
		if (is_dir(dirname($target)) === false || is_writable(dirname($target)) === false) {
			return null;
		}

		$out = fopen($target, 'wb');
		if ($out === false) {
			return null;
		}

		return $out;
	}

	/**
	 * Read one member header.
	 *
	 * @param resource $archive The open archive.
	 *
	 * @return array{name: string, size: int, file: bool}|null The member, or null at the end.
	 */
	private function header($archive): ?array {
		$header = gzread($archive, self::BLOCK);
		if ($header === false || strlen($header) < self::BLOCK || trim($header, "\0") === '') {
			return null;
		}

		$name = rtrim(substr($header, 0, 100), "\0");
		$prefix = rtrim(substr($header, 345, 155), "\0");
		if ($prefix !== '') {
			$name = $prefix . '/' . $name;
		}

		$type = substr($header, 156, 1);

		return [
			'name' => $name,
			'size' => (int)octdec(trim(substr($header, 124, 12), "\0 ")),
			'file' => ($type === '0' || $type === "\0"),
		];
	}

	/**
	 * Copy one member's content to the target and skip its padding.
	 *
	 * @param resource $archive The open archive.
	 * @param int      $size    The member size.
	 * @param string   $target  The output path.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the target cannot be written.
	 */
	private function copyMember($archive, int $size, string $target): void {
		$out = $this->create(target: $target);
		if ($out === null) {
			throw new RuntimeException('The target file cannot be written.');
		}

		$remaining = $size;
		while ($remaining > 0) {
			$chunk = gzread($archive, min(self::CHUNK, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}

			fwrite($out, $chunk);
			$remaining -= strlen($chunk);
		}

		fclose($out);
		if ($remaining > 0) {
			throw new RuntimeException('The archive ended inside a member.');
		}

		$this->skip(archive: $archive, bytes: $this->padded(size: $size) - $size);
	}

	/**
	 * Read and discard bytes.
	 *
	 * @param resource $archive The open archive.
	 * @param int      $bytes   How many.
	 *
	 * @return void
	 */
	private function skip($archive, int $bytes): void {
		while ($bytes > 0) {
			$chunk = gzread($archive, min(self::CHUNK, $bytes));
			if ($chunk === false || $chunk === '') {
				return;
			}

			$bytes -= strlen($chunk);
		}
	}

	/**
	 * A size rounded up to whole blocks.
	 *
	 * @param int $size The member size.
	 *
	 * @return int The padded size.
	 */
	private function padded(int $size): int {
		return (int)(ceil($size / self::BLOCK) * self::BLOCK);
	}
}
