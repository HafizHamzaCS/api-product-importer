<?php

class HS_Cron {

	public function __construct() {
		add_action('init', [$this, 'schedule_cron']);
		add_action('hs_import_products_cron', [$this, 'run_import']);
		add_action('wp_ajax_import_products_manually', [$this, 'import_products_manually']);
		add_action('wp_ajax_nopriv_import_products_manually', [$this, 'import_products_manually']);
	}

	public function schedule_cron() {
		if (!wp_next_scheduled('hs_import_products_cron')) {
			wp_schedule_event(time(), 'hourly', 'hs_import_products_cron');
		}
	}

	public function run_import() {
		$logger = new HS_API_Logger();
		$logger->log("Running product import via cron.");

		// Call the function to run product import
		HS_Product_Importer::import_products();

		$logger->log("Product import completed.");
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled('hs_import_products_cron');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'hs_import_products_cron');
		}
	}

	public function import_products_manually() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Unauthorized access');
			return;
		}
		echo "It's down temporary";
//		die();
		$logger = new HS_API_Logger();
		$logger->log("Manual product import initiated.");

		// Call the function to run product import
		HS_Product_Importer::import_products();

		$logger->log("Manual product import completed.");
		wp_send_json_success('Product import executed successfully.');
	}
}
