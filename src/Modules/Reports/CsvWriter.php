<?php
/**
 * Writes a report table out as CSV.
 *
 * Two details that matter for a file whose whole purpose is to be opened
 * in Excel and emailed to a manufacturer:
 *
 *   - A UTF-8 BOM is written first. Without it Excel on Windows reads the
 *     file as the system codepage and mangles any non-ASCII character in a
 *     product name or a customer's name.
 *   - Values that begin with =, +, - or @ are prefixed with a tab, because
 *     Excel and Sheets will otherwise evaluate them as formulas. Product
 *     names and customer-supplied fields end up in this file, so this is a
 *     real CSV-injection surface, not a theoretical one.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CsvWriter
 */
final class CsvWriter {

	/**
	 * Write a table to a CSV file.
	 *
	 * @param string $path   Absolute destination path.
	 * @param array  $header Header cells.
	 * @param array  $rows   Data rows.
	 * @param array  $footer Optional trailing row (the TOTALS row).
	 * @return true|\WP_Error
	 */
	public function write( $path, array $header, array $rows, array $footer = array() ) {
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			return new \WP_Error(
				'blt_sce_csv_open_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Could not open %s for writing.', 'blt-surecart-extensions' ),
					$path
				)
			);
		}

		// UTF-8 BOM, so Excel doesn't mangle accented names.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->put_row( $handle, $header );

		foreach ( $rows as $row ) {
			$this->put_row( $handle, $row );
		}

		if ( ! empty( $footer ) ) {
			$this->put_row( $handle, $footer );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return true;
	}

	/**
	 * Write one row, sanitizing each cell against formula injection.
	 *
	 * @param resource $handle Open file handle.
	 * @param array    $row    Row cells.
	 * @return void
	 */
	private function put_row( $handle, array $row ) {
		fputcsv( $handle, array_map( array( $this, 'sanitize_cell' ), $row ) );
	}

	/**
	 * Neutralize a cell that a spreadsheet would otherwise treat as a
	 * formula, without altering what the value reads as.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function sanitize_cell( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "\t" . $value;
		}

		return $value;
	}
}
