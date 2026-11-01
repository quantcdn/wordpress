// @ts-check
/**
 * Queue helpers: drain the sync queue with WP-CLI and inspect its rows.
 */
const { wp, wpEval, waitForMock } = require('./wp');

/** Process every pending job with WP-CLI and return the command output. */
function processQueue() {
  return wp(['quant', 'queue', 'process', '--all'], { allowFailure: true });
}

/** Queue counts as JSON: { pending, processing, failed, total }. */
function queueStatus() {
  return JSON.parse(wpEval('echo json_encode((new Quant\\Sync\\DatabaseQueue($GLOBALS["wpdb"]))->getStatus());'));
}

/** All queue rows (type, object_id, action, status, meta). */
function queueJobs() {
  const out = wpEval('global $wpdb; echo json_encode($wpdb->get_results("SELECT type, object_id, action, status, meta FROM {$wpdb->prefix}quant_sync_queue ORDER BY id", ARRAY_A));');
  return JSON.parse(out || '[]');
}

/** Remove every row from the queue table. */
function truncateQueue() {
  wpEval('global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}quant_sync_queue"); echo "ok";');
}

/** Run the WP-Cron hook that drains the queue, as the scheduler would. */
function runCron() {
  return wp(['cron', 'event', 'run', 'quant_process_queue'], { allowFailure: true });
}

module.exports = { processQueue, queueStatus, queueJobs, truncateQueue, runCron, waitForMock };
