<?php

class HS_API_Logger {

    private $log_dir;

    public function __construct() {
        $this->log_dir = HS_PLUGIN_DIR . 'logs/';
        if (!file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
    }

    public function log($message) {
        $file_name = 'log-' . date('d-m-Y') . '.txt';
        $file_path = $this->log_dir . $file_name;
        $log_message = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
        file_put_contents($file_path, $log_message, FILE_APPEND);
    }
}
