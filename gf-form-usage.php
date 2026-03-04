<?php
/**
 * Plugin Name: Gravity Forms Usage for Bricks
 * Description: Shows where each Gravity Form is used across Bricks pages, templates, and post content. Includes a modern admin UI with caching and on-demand scans.
 * Version: 1.0.0
 * Author: Adam Pedersen
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gf-form-usage-bricks
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

final class GF_Form_Usage_Bricks {
	const VERSION           = '1.0.0';
	const NONCE_ACTION      = 'gffu_scan';
	const AJAX_ACTION       = 'gffu_scan';
	const PAGE_SLUG         = 'gffu_form_usage';
	const CACHE_TTL_SECONDS = 12 * HOUR_IN_SECONDS;
	private static function menu_cap(): string {
	// Prefer GF capability when available, otherwise fall back to admin cap.
	return current_user_can('gravityforms_edit_forms') ? 'gravityforms_edit_forms' : 'manage_options';
}

	/**
	 * Capability used to access UI.
	 * You can filter this to allow editors, etc:
	 * add_filter('gffu_capability', fn() => 'edit_pages');
	 */
	public static function cap(): string {
		$cap = apply_filters('gffu_capability', 'gravityforms_edit_forms');
		return is_string($cap) && $cap ? $cap : 'gravityforms_edit_forms';
	}

	public static function init(): void {
		// Register menus late to avoid GF timing issues.
		add_action('admin_menu', [__CLASS__, 'register_admin_page'], 9999);

		add_action('admin_enqueue_scripts', [__CLASS__, 'boot_on_gf_settings_or_usage_page']);
		add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_scan']);

		// Simple, safe invalidation
		add_action('save_post', [__CLASS__, 'invalidate_all_cache'], 20, 2);
		add_action('updated_postmeta', [__CLASS__, 'maybe_invalidate_on_meta_change'], 20, 4);
		add_action('added_postmeta', [__CLASS__, 'maybe_invalidate_on_meta_change'], 20, 4);
		add_action('deleted_postmeta', [__CLASS__, 'maybe_invalidate_on_meta_change'], 20, 4);
	}

	/**
	 * Adds:
	 * - Gravity Forms submenu (best effort)
	 * - Tools fallback (always works)
	 */
public static function register_admin_page(): void {
	if (!is_admin()) return;

	$cap = self::menu_cap();

	// Best effort: under Gravity Forms -> Forms
	add_submenu_page(
		'gf_edit_forms',
		__('Form Usage', 'gf-form-usage-bricks'),
		__('Form Usage', 'gf-form-usage-bricks'),
		$cap,
		self::PAGE_SLUG,
		[__CLASS__, 'render_usage_page']
	);

	// Guaranteed fallback: Tools -> Form Usage
	add_management_page(
		__('Form Usage', 'gf-form-usage-bricks'),
		__('Form Usage', 'gf-form-usage-bricks'),
		$cap,
		self::PAGE_SLUG,
		[__CLASS__, 'render_usage_page']
	);
}

public static function render_usage_page(): void {
	if (!current_user_can(self::menu_cap())) {
		wp_die('Insufficient permissions.');
	}

		$selected_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;

		$forms = [];
		if (class_exists('GFAPI')) {
			$forms = GFAPI::get_forms();
		}

		// Default to first form if none selected
		if (!$selected_id && !empty($forms) && !empty($forms[0]['id'])) {
			$selected_id = absint($forms[0]['id']);
		}

		echo '<div class="wrap">';
		echo '<h1 style="margin-bottom:10px;">Form Usage</h1>';
		echo '<p style="margin-top:0;color:#5b6776;">Select a form to see where it’s used (Bricks pages/templates + shortcode/block detection).</p>';

		if (empty($forms)) {
			echo '<div class="notice notice-warning"><p>Could not load forms. Make sure Gravity Forms is active and GFAPI is available.</p></div>';
			echo '</div>';
			return;
		}

		// Works whether you arrived via Tools or GF submenu.
		$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : self::PAGE_SLUG;
		if ($current_page !== self::PAGE_SLUG) $current_page = self::PAGE_SLUG;

		$action_url = esc_url(admin_url('admin.php?page=' . $current_page));

		echo '<form method="get" action="' . $action_url . '" style="margin: 14px 0 8px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
		echo '<input type="hidden" name="page" value="' . esc_attr($current_page) . '" />';
		echo '<label for="gffu_form_id" style="font-weight:600;">Form</label>';
		echo '<select id="gffu_form_id" name="form_id" style="min-width:320px; max-width: 520px;">';

		foreach ($forms as $f) {
			$id = absint($f['id'] ?? 0);
			$title = isset($f['title']) ? $f['title'] : ('Form #' . $id);
			if (!$id) continue;

			printf(
				'<option value="%d"%s>%s (ID: %d)</option>',
				$id,
				selected($selected_id, $id, false),
				esc_html($title),
				$id
			);
		}

		echo '</select>';
		echo '<button class="button button-primary" type="submit">View</button>';
		echo '</form>';

		echo '<div id="gffu-usage-page-mount" data-form-id="' . esc_attr((string)$selected_id) . '"></div>';
		echo '</div>';
	}

	/**
	 * Boot UI on:
	 * - GF Form Settings screen
	 * - Form Usage page (Tools or GF submenu, same slug)
	 */
	public static function boot_on_gf_settings_or_usage_page(string $hook): void {
		if (!is_admin()) return;
		if (!current_user_can(self::cap()) && !current_user_can('manage_options')) return;

		$page = $_GET['page'] ?? '';
		$view = $_GET['view'] ?? '';
		$id   = isset($_GET['id']) ? absint($_GET['id']) : 0;

		$is_gf_settings = ($page === 'gf_edit_forms' && $view === 'settings' && $id);
		$is_usage_page  = ($page === self::PAGE_SLUG);

		if (!$is_gf_settings && !$is_usage_page) return;

		add_action('admin_footer', function () use ($is_gf_settings, $id) {
			$nonce = wp_create_nonce(self::NONCE_ACTION);
			$ajax  = admin_url('admin-ajax.php');

			$formId = $is_gf_settings ? (int)$id : 0;
			?>
			<style>
				/* Modern panel styles (scoped) */
				#gffu-card{max-width:1120px;margin-top:18px;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);overflow:hidden}
				#gffu-card *{box-sizing:border-box}
				#gffu-head{padding:16px 18px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;border-bottom:1px solid rgba(0,0,0,.08);background:linear-gradient(180deg,rgba(245,247,250,.9),#fff)}
				#gffu-title{margin:0;font-size:16px;line-height:1.2}
				#gffu-sub{margin:6px 0 0;color:#5b6776;font-size:12.5px}
				#gffu-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
				.gffu-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;border:1px solid rgba(0,0,0,.10);background:#fff;color:#2c3338;font-size:12px}
				.gffu-dot{width:8px;height:8px;border-radius:50%;background:#b0b7c3}
				.gffu-dot.ok{background:#2bb673}.gffu-dot.warn{background:#ffb020}.gffu-dot.bad{background:#e55353}
				#gffu-body{padding:14px 18px 18px}
				#gffu-controls{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
				#gffu-search{min-width:280px;flex:1;max-width:520px}
				#gffu-search input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);background:#fff;font-size:13px}
				#gffu-btns{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.gffu-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.12);background:#fff;cursor:pointer;font-size:13px}
				.gffu-btn.primary{background:#2271b1;color:#fff;border-color:rgba(0,0,0,0)}
				.gffu-btn:disabled{opacity:.55;cursor:not-allowed}
				#gffu-summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;color:#5b6776;font-size:12.5px;margin:4px 0 12px}
				.gffu-group{margin-top:12px;border:1px solid rgba(0,0,0,.10);border-radius:14px;overflow:hidden;background:#fff}
				.gffu-group summary{list-style:none;cursor:pointer;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fbfcfe;border-bottom:1px solid rgba(0,0,0,.06);font-weight:600}
				.gffu-group summary::-webkit-details-marker{display:none}
				.gffu-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(0,0,0,.10);background:#fff;font-weight:600;font-size:12px;color:#2c3338}
				.gffu-table{width:100%;border-collapse:collapse}
				.gffu-table th,.gffu-table td{padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.06);vertical-align:middle;font-size:13px}
				.gffu-table th{text-align:left;color:#5b6776;font-weight:600;font-size:12px}
				.gffu-k{color:#5b6776;font-size:12px}
				.gffu-link{text-decoration:none}
				.gffu-link:hover{text-decoration:underline}
				.gffu-src{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;border:1px solid rgba(0,0,0,.10);background:#fff;font-size:12px;color:#2c3338}
				#gffu-note{margin:12px 0 0;color:#5b6776;font-size:12.5px;max-width:1020px}
				#gffu-empty{border:1px dashed rgba(0,0,0,.18);border-radius:14px;padding:14px;color:#5b6776;background:#fff}
			</style>

			<script>
			(function(){
				const ajaxUrl = <?php echo json_encode($ajax); ?>;
				const nonce   = <?php echo json_encode($nonce); ?>;
				const settingsFormId = <?php echo (int)$formId; ?>;

				function qs(sel, root=document){ return root.querySelector(sel); }
				function qsa(sel, root=document){ return Array.from(root.querySelectorAll(sel)); }
				function fmtTime(ts){ if(!ts) return ''; try { return new Date(ts*1000).toLocaleString(); } catch(e){ return ''; } }

				function groupByType(items){
					const groups = {};
					items.forEach(it => {
						const key = it.post_type || 'unknown';
						(groups[key] ||= []).push(it);
					});
					Object.keys(groups).forEach(k => groups[k].sort((a,b) => (a.title||'').localeCompare(b.title||'')));
					return groups;
				}

				function renderPanel(target, formId){
					const hr = document.createElement('hr');
					hr.style.margin = '24px 0';
					target.appendChild(hr);

					const card = document.createElement('div');
					card.id = 'gffu-card';
					card.innerHTML = `
						<div id="gffu-head">
							<div>
								<h2 id="gffu-title">Used on</h2>
								<p id="gffu-sub">Bricks pages + Bricks templates + shortcode/block detection for form ID <strong>${formId}</strong></p>
							</div>
							<div id="gffu-actions">
								<span class="gffu-pill" id="gffu-status"><span class="gffu-dot"></span><span>Loading…</span></span>
							</div>
						</div>
						<div id="gffu-body">
							<div id="gffu-controls">
								<div id="gffu-search"><input type="text" placeholder="Filter results (title, type, status, source)…" /></div>
								<div id="gffu-btns">
									<button type="button" class="gffu-btn" id="gffu-load">Load cached</button>
									<button type="button" class="gffu-btn primary" id="gffu-scan">Scan now</button>
									<button type="button" class="gffu-btn" id="gffu-copy" disabled>Copy links</button>
								</div>
							</div>
							<div id="gffu-summary"></div>
							<div id="gffu-results"></div>
							<p id="gffu-note">Note: If a form is rendered via custom PHP (e.g. <code>gravity_form()</code> in theme/plugin files) it may not be detectable from the database.</p>
						</div>
					`;
					target.appendChild(card);
					return card;
				}

				async function request(formId, mode){
					const status = qs('#gffu-status');
					const dot = qs('.gffu-dot', status);
					const label = status.lastElementChild;

					dot.className = 'gffu-dot';
					label.textContent = (mode === 'scan') ? 'Scanning…' : 'Loading…';

					const params = new URLSearchParams();
					params.set('action', <?php echo json_encode(self::AJAX_ACTION); ?>);
					params.set('nonce', nonce);
					params.set('form_id', String(formId));
					params.set('mode', mode);

					const res = await fetch(ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
						body: params.toString()
					});

					const json = await res.json();
					if(!json || !json.success){
						dot.classList.add('bad');
						label.textContent = 'Error';
						throw new Error(json?.data?.message || 'Unknown error');
					}
					return json.data;
				}

				function renderResults(data){
					const items = data.items || [];
					const cached = !!data.cached;
					const last = data.last_scanned || 0;

					const status = qs('#gffu-status');
					const dot = qs('.gffu-dot', status);
					const label = status.lastElementChild;

					const summary = qs('#gffu-summary');
					const out = qs('#gffu-results');
					const copyBtn = qs('#gffu-copy');

					out.innerHTML = '';
					summary.innerHTML = '';

					if(!items.length){
						dot.classList.add('warn');
						label.textContent = cached ? 'No matches (cached)' : 'No matches';
						out.innerHTML = `<div id="gffu-empty">No locations were detected for this form.</div>`;
						copyBtn.disabled = true;
						copyBtn.dataset.links = '';
						return;
					}

					dot.classList.add('ok');
					label.textContent = `${items.length} location(s) ${cached ? '(cached)' : '(fresh)'}${last ? ' • ' + fmtTime(last) : ''}`;

					const byType = groupByType(items);
					const types = Object.keys(byType).sort();

					summary.innerHTML = types.map(t => `<span class="gffu-pill"><strong>${t}</strong> <span class="gffu-k">(${byType[t].length})</span></span>`).join(' ');

					types.forEach((t, idx) => {
						const group = document.createElement('details');
						group.className = 'gffu-group';
						if (idx === 0) group.open = true;

						const rows = byType[t].map(r => `
							<tr class="gffu-row">
								<td><a class="gffu-link" href="${r.edit_link}">${(r.title || '(no title)')}</a></td>
								<td>${r.post_status}</td>
								<td><span class="gffu-src">${r.source}</span></td>
							</tr>
						`).join('');

						group.innerHTML = `
							<summary><span>${t}</span><span class="gffu-badge">${byType[t].length}</span></summary>
							<div style="padding:0 0 6px;">
								<table class="gffu-table">
									<thead><tr><th style="width:60%;">Title</th><th style="width:20%;">Status</th><th style="width:20%;">Source</th></tr></thead>
									<tbody>${rows}</tbody>
								</table>
							</div>
						`;
						out.appendChild(group);
					});

					const links = items.map(r => `${r.title || '(no title)'} — ${r.edit_link}`).join('\n');
					copyBtn.disabled = false;
					copyBtn.dataset.links = links;
				}

				function wireUI(card, formId){
					const loadBtn = qs('#gffu-load', card);
					const scanBtn = qs('#gffu-scan', card);
					const copyBtn = qs('#gffu-copy', card);
					const search = qs('#gffu-search input', card);

					async function run(mode){
						loadBtn.disabled = true;
						scanBtn.disabled = true;
						try{
							const data = await request(formId, mode);
							renderResults(data);
						}catch(e){
							const status = qs('#gffu-status', card);
							const dot = qs('.gffu-dot', status);
							const label = status.lastElementChild;
							dot.classList.add('bad');
							label.textContent = 'Error';
							qs('#gffu-results', card).innerHTML = `<div class="notice notice-error" style="margin:0;"><p style="margin:0;">${e.message}</p></div>`;
						}finally{
							loadBtn.disabled = false;
							scanBtn.disabled = false;
						}
					}

					loadBtn.addEventListener('click', () => run('cache'));
					scanBtn.addEventListener('click', () => run('scan'));

					copyBtn.addEventListener('click', async () => {
						const text = copyBtn.dataset.links || '';
						if(!text) return;
						try{
							await navigator.clipboard.writeText(text);
							copyBtn.textContent = 'Copied!';
							setTimeout(() => copyBtn.textContent = 'Copy links', 900);
						}catch(e){
							alert('Could not copy. Your browser may block clipboard access.');
						}
					});

					search.addEventListener('input', () => {
						const q = (search.value || '').toLowerCase().trim();
						qsa('.gffu-row', card).forEach(row => {
							const txt = row.textContent.toLowerCase();
							row.style.display = (!q || txt.includes(q)) ? '' : 'none';
						});
					});

					run('cache');
				}

				function mount(){
					let formId = settingsFormId;

					const usageMount = document.getElementById('gffu-usage-page-mount');
					if (usageMount && usageMount.dataset.formId) {
						const v = parseInt(usageMount.dataset.formId, 10);
						if (v) formId = v;
					}

					let target =
						document.querySelector('.gform_settings') ||
						document.querySelector('.gform-settings-panel') ||
						document.querySelector('#gform_form_settings');

					if (usageMount) target = usageMount;
					if (!target || !formId) return;

					const card = renderPanel(target, formId);
					wireUI(card, formId);
				}

				document.addEventListener('DOMContentLoaded', mount);
			})();
			</script>
			<?php
		});
	}

	public static function ajax_scan(): void {
		if (!current_user_can(self::cap()) && !current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}
		$nonce = $_POST['nonce'] ?? '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			wp_send_json_error(['message' => 'Invalid nonce.'], 400);
		}

		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		$mode    = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'cache';
		if (!$form_id) wp_send_json_error(['message' => 'Missing form_id.'], 400);

		if ($mode === 'cache') {
			$cached = self::get_cache($form_id);
			if ($cached) {
				wp_send_json_success([
					'items'        => $cached['items'] ?? [],
					'cached'       => true,
					'last_scanned' => (int) ($cached['last_scanned'] ?? 0),
				]);
			}
		}

		$items = self::scan($form_id);

		self::set_cache($form_id, [
			'items'        => $items,
			'last_scanned' => time(),
		]);

		wp_send_json_success([
			'items'        => $items,
			'cached'       => false,
			'last_scanned' => time(),
		]);
	}

	private static function scan(int $form_id): array {
		global $wpdb;

		$sc1 = '%[gravityform%id="' . $form_id . '"%';
		$sc2 = "%[gravityform%id='" . $form_id . "'%";
		$sc3 = '%[gravityform%id=' . $form_id . '%';

		$blk1 = '%wp:gravityforms/form%';
		$blk2 = '%"formId":"' . $form_id . '"%';
		$blk3 = '%"formId":' . $form_id . '%';

		$content_sql = $wpdb->prepare("
			SELECT ID, post_title, post_type, post_status, 'post_content' AS source
			FROM {$wpdb->posts}
			WHERE post_status IN ('publish','draft','private')
			  AND post_type NOT IN ('revision','nav_menu_item')
			  AND (
					post_content LIKE %s
				 OR post_content LIKE %s
				 OR post_content LIKE %s
				 OR (post_content LIKE %s AND (post_content LIKE %s OR post_content LIKE %s))
			  )
		", $sc1, $sc2, $sc3, $blk1, $blk2, $blk3);

		$content_rows = $wpdb->get_results($content_sql);

		$meta_sql = $wpdb->prepare("
			SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status,
				CASE
					WHEN p.post_type = 'bricks_template' THEN 'bricks_template'
					ELSE 'bricks_meta'
				END AS source
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_status IN ('publish','draft','private')
			  AND p.post_type NOT IN ('revision','nav_menu_item')
			  AND pm.meta_key LIKE %s
			  AND (
					pm.meta_value LIKE %s
				 OR pm.meta_value LIKE %s
				 OR pm.meta_value LIKE %s
				 OR pm.meta_value LIKE %s
				 OR pm.meta_value LIKE %s
			  )
		",
			'_bricks_%',
			$blk2, $blk3, $sc1, $sc2, $sc3
		);

		$meta_rows = $wpdb->get_results($meta_sql);

		$map = [];
		foreach (array_merge($content_rows ?: [], $meta_rows ?: []) as $r) {
			$map[(int)$r->ID] = $r;
		}
		$rows = array_values($map);

		usort($rows, function($a, $b){
			$tc = strcmp((string)$a->post_type, (string)$b->post_type);
			if ($tc !== 0) return $tc;
			return strcmp((string)$a->post_title, (string)$b->post_title);
		});

		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'ID'          => (int) $r->ID,
				'title'       => (string) $r->post_title,
				'post_type'   => (string) $r->post_type,
				'post_status' => (string) $r->post_status,
				'source'      => (string) $r->source,
				'edit_link'   => (string) get_edit_post_link((int)$r->ID, 'raw'),
			];
		}

		return $out;
	}

	private static function cache_key(int $form_id): string {
		return 'gffu_' . get_current_blog_id() . '_' . $form_id;
	}
	private static function get_cache(int $form_id): ?array {
		$data = get_transient(self::cache_key($form_id));
		return is_array($data) ? $data : null;
	}
	private static function set_cache(int $form_id, array $data): void {
		set_transient(self::cache_key($form_id), $data, self::CACHE_TTL_SECONDS);
	}

	public static function invalidate_all_cache($post_id, $post): void {
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
		self::delete_all_usage_transients();
	}

	public static function maybe_invalidate_on_meta_change($meta_id, $object_id, $meta_key, $_meta_value): void {
		if (is_string($meta_key) && function_exists('str_starts_with') && str_starts_with($meta_key, '_bricks_')) {
			self::delete_all_usage_transients();
		}
	}

	private static function delete_all_usage_transients(): void {
		global $wpdb;

		$like1 = '_transient_gffu_' . get_current_blog_id() . '_%';
		$like2 = '_transient_timeout_gffu_' . get_current_blog_id() . '_%';

		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like1));
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2));
	}
}

GF_Form_Usage_Bricks::init();