// @ts-check
const { test, expect } = require('@playwright/test');
const { resetPlugin, configurePlugin, wp, wpEval } = require('../lib/wp');
const { gotoQuant, rest } = require('../lib/admin');

// The queue table as 2.0.3-2.0.5 created it (no error_detail column).
const TABLE_2_0_5 = `
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id VARCHAR(36) NOT NULL,
  type VARCHAR(32) NOT NULL,
  object_id VARCHAR(255) NOT NULL,
  action VARCHAR(20) NOT NULL,
  status VARCHAR(20) DEFAULT 'pending',
  attempts TINYINT DEFAULT 0,
  error_message TEXT NULL,
  meta LONGTEXT NULL,
  claim VARCHAR(36) NULL,
  dedupe_key CHAR(32) NULL,
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_type_object (type, object_id(191)),
  KEY idx_job_id (job_id),
  KEY idx_claim (claim),
  KEY idx_dedupe (dedupe_key, status)`;

function sql(query) {
  return wpEval(`global $wpdb; echo json_encode(${query});`);
}

test.describe('queue table upgrade', () => {
  test.setTimeout(120_000);

  test.beforeEach(async () => {
    await resetPlugin();
    configurePlugin({ mode: 'static' });
  });

  test('a 2.0.5 table with rows gets error_detail and keeps its rows', async ({ page }) => {
    const before = wpEval(`
      global $wpdb;
      $t = $wpdb->prefix . 'quant_sync_queue';
      $wpdb->query("DROP TABLE IF EXISTS $t");
      $wpdb->query("CREATE TABLE $t (${TABLE_2_0_5.replace(/\n/g, ' ')}) " . $wpdb->get_charset_collate());
      $wpdb->query("INSERT INTO $t (job_id, type, object_id, action, status, attempts, error_message, created_at, processed_at)
        VALUES ('old-failed', 'post', '7', 'publish', 'failed', 1, 'HTTP 500', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
               ('old-pending', 'post', '8', 'publish', 'pending', 0, NULL, UTC_TIMESTAMP(), NULL)");
      update_option('quant_db_version', '2.0.3');
      echo json_encode($wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'error_detail'"));
    `);
    expect(JSON.parse(before)).toBeNull();

    // The next request runs the upgrade (Upgrader on init, before the command itself).
    wp(['option', 'get', 'quant_version']);

    expect(wp(['option', 'get', 'quant_db_version']).trim()).toBe('2.0.6');
    expect(JSON.parse(sql(`$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}quant_sync_queue LIKE 'error_detail'")`))).toBe('error_detail');
    const rows = JSON.parse(sql(`$wpdb->get_results("SELECT job_id, status, attempts, error_message, error_detail FROM {$wpdb->prefix}quant_sync_queue ORDER BY id", ARRAY_A)`));
    expect(rows).toEqual([
      { job_id: 'old-failed', status: 'failed', attempts: '1', error_message: 'HTTP 500', error_detail: null },
      { job_id: 'old-pending', status: 'pending', attempts: '0', error_message: null, error_detail: null },
    ]);

    // The old failure still shows in the card.
    await gotoQuant(page, 'dashboard');
    const errors = await rest(page, '/queue/errors');
    expect(errors).toEqual([expect.objectContaining({ object_id: '7', error_message: 'HTTP 500', error_detail: null })]);
    await expect(page.getByText('HTTP 500')).toBeVisible();
  });
});
