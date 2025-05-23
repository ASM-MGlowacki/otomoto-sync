<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cmu_otomoto_log' ) ) {
	/**
	 * Logs a message to the Otomoto sync log file.
	 *
	 * @param string $message The message to log.
	 * @param string $type    The type of log entry (e.g., INFO, ERROR, WARNING). Defaults to INFO.
	 * @param array  $context_data Optional context data to include in the log.
	 */
	function cmu_otomoto_log( $message, $type = 'INFO', $context_data = [] ) {
		// Ensure the logs directory exists.
		if ( ! defined( 'CMU_OTOMOTO_LOGS_DIR' ) ) {
			// Fallback if the constant is not defined, though it should be.
			$log_dir = WP_PLUGIN_DIR . '/otomoto-maszyny-integration/logs';
		} else {
			$log_dir = CMU_OTOMOTO_LOGS_DIR;
		}

		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$log_file = $log_dir . '/otomoto_sync.log';

		$formatted_message = sprintf(
			'[%s] [%s] - %s', 
			current_time( 'mysql' ), // WordPress function for current time in MySQL format
			strtoupper( $type ),
			trim( $message )
		);

		if ( ! empty( $context_data ) ) {
			$formatted_message .= ' [Context: ' . wp_json_encode( $context_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ']';
		}

		$formatted_message .= "\n";

		// Simple log rotation: if file > 5MB, archive it.
		// Clear stat cache to get the real current file size.
		clearstatcache(true, $log_file);
		if ( file_exists( $log_file ) && filesize( $log_file ) > 5 * 1024 * 1024 ) { // 5 MB
			$archived_log_file = $log_dir . '/otomoto_sync_archive_' . time() . '_' . wp_generate_password(8, false) . '.log';
			@rename( $log_file, $archived_log_file );
		}

		$bytes_written = file_put_contents( $log_file, $formatted_message, FILE_APPEND );
		if ( false === $bytes_written ) {
			// Fallback: log do error_log PHP jeśli zapis do pliku nie powiódł się
			$fallback_message = "[CMU Otomoto Plugin Log Fallback] Failed to write to plugin log file. Original message: " . trim($formatted_message);
			error_log( $fallback_message );
		}
	}
}
