<?php
namespace Layered\Wp;

class Q {

	private static $_instance = null;
	private $timeStart;
	private $memoryLimit;
	private $timeLimit;
	private $dbTable;

	/**
	 * Main Q instance. Ensures only one instance of Q can be loaded.
	 */
	public static function instance() {
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * NO Cloning.
	 */
	public function __clone() {
		_doing_it_wrong(__FUNCTION__, 'Cloning is forbidden.', null);
	}

	/**
	 * NO Unserializing.
	 */
	public function __wakeup() {
		_doing_it_wrong(__FUNCTION__, 'Unserializing instances of this class is forbidden.', null);
	}

	private function __construct() {
		global $wpdb;

		$this->dbTable = $wpdb->prefix . 'q';

		add_action('wp_ajax_layered-q-run', [$this, 'process']);
		add_action('wp_ajax_nopriv_layered-q-run', [$this, 'process']);
		add_action('layered_q_maintenance', [$this, 'doMaintenance']);
	}

	public function add(string $tag, array $args = []) {
		global $wpdb;

		$job = $wpdb->insert($this->dbTable, [
			'tag'		=>	$tag,
			'data'		=>	maybe_serialize($args),
			'createdAt'	=>	date('Y-m-d H:i:s')
		]);

		$this->run();

		return $job ? $wpdb->insert_id : false;
	}

	public function run() {
		$run = false;

		if (!get_transient('layered-q-running')) {

			// maybe improve this
			$nonce = wp_generate_password(16, false);
			set_transient('layered-q-nonce', $nonce, HOUR_IN_SECONDS);

			$url = add_query_arg([
				'action'	=>	'layered-q-run',
				'_nonce'	=>	$nonce
			], admin_url('admin-ajax.php'));

			wp_remote_post($url, [
				'timeout'	=>	0.01,
				'blocking'	=>	false,
				'sslverify'	=>	apply_filters('https_local_ssl_verify', false),
			]);

			$run = true;
		}

		return $run;
	}

	public function process() {
		global $wpdb;
		session_write_close();

		if (!get_transient('layered-q-running') && get_transient('layered-q-nonce') === $_GET['_nonce']) {
			$this->startTime = time();
			$this->timeLimit = $this->getTimeLimit();
			$this->memoryLimit = $this->getMemoryLimit();

			set_transient('layered-q-running', time(), $this->timeLimit);

			while (($task = $this->getQueuedTask()) && $this->hasTime() && $this->hasMemory()) {
				$wpdb->update($this->dbTable, [
					'status'	=>	'started',
					'startedAt'	=>	date('Y-m-d H:i:s')
				], [
					'id'		=>	$task->id
				]);

				do_action_ref_array($task->tag, maybe_unserialize($task->data));

				$wpdb->update($this->dbTable, [
					'status'		=>	'completed',
					'finishedAt'	=>	date('Y-m-d H:i:s')
				], [
					'id'		=>	$task->id
				]);
			}

			delete_transient('layered-q-running');

			if ($this->hasTasks()) {
				$this->run();
			}
		}

		wp_die('Q');
	}

	protected function getQueuedTask() {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->dbTable WHERE status = %s LIMIT 1", ['queued']));
	}

	protected function hasTasks() {
		global $wpdb;
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT count(id) FROM $this->dbTable WHERE status = %s LIMIT 1", ['queued']));
	}

	protected function hasTime() {
		return time() - $this->startTime < $this->timeLimit * 0.9;
	}

	protected function hasMemory() {
		return memory_get_usage(true) < $this->memoryLimit * 0.9;
	}

	public function doMaintenance() {
		global $wpdb;

		// mark jobs as error
		$startedAfter = date('Y-m-d H:i:s', time() + $this->getTimeLimit());
		$wpdb->query($wpdb->prepare("UPDATE $this->dbTable SET status = %s WHERE status = %s AND startedAt < %s", ['error', 'started', $startedAfter]));

		// maybe start queue
		if ($this->hasTasks()) {
			$this->run();
		}
	}


	public static function install() {
		global $wpdb;

 		// Create DB table for queued actions
		$dbTable = $wpdb->prefix . 'q';
		if ($wpdb->get_var("show tables like '$dbTable'") != $dbTable) {
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

			dbDelta("CREATE TABLE `$dbTable` (
				`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
				`tag` varchar(255) NOT NULL,
				`status` enum('queued','started','error','completed') NOT NULL DEFAULT 'queued',
				`data` text NOT NULL,
				`createdAt` datetime NOT NULL,
				`startedAt` datetime DEFAULT NULL,
				`finishedAt` datetime DEFAULT NULL,
				PRIMARY KEY (`id`),
				KEY `tag` (`tag`)
			) " . $wpdb->get_charset_collate() . ";");
		}

		// schedule maintenance process
		if (!wp_next_scheduled('layered_q_maintenance')) {
			wp_schedule_event(time(), 'hourly', 'layered_q_maintenance');
		}

	}

	protected function getTimeLimit() {
		$timeLimit = function_exists('ini_get') ? ini_get('max_execution_time') : 30;

		return (int) apply_filters('layered_q_time_limit', $timeLimit ?: 600);
	}

	protected function getMemoryLimit() {
		$memoryLimit = function_exists('ini_get') ? ini_get('memory_limit') : '128M';

		if (!$memoryLimit || $memoryLimit == '-1' || 1) {
			$memoryLimit = '16G';
		}

		return apply_filters('layered_q_memory_limit', wp_convert_hr_to_bytes($memoryLimit));
	}

}
