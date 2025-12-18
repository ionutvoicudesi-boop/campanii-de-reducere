<?php
/**
 * Plugin Name: Campanii de reducere
 * Description: Campanii de reducere WooCommerce pe categorii (sumă fixă sau procente) cu perioadă + badge + preț tăiat + licență Free/Premium cu token offline.
 * Version: 1.2.0
 * Author: IonutVoicu
 * Text Domain: iv-campanii
 */


if (is_admin()) {
    $pucPath = __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
    if (file_exists($pucPath)) {
        require $pucPath;

        $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/ionutvoicudesi-boop/campanii-de-reducere/',
            __FILE__,
            'campanii-de-reducere'
        );

        $updateChecker->setBranch('main');

        // IMPORTANT: folosim ZIP-ul atașat la Release (nu "Source code zip")
        $updateChecker->getVcsApi()->enableReleaseAssets();
    }
}

if (!defined('ABSPATH')) exit;

class IV_Campanii_Reducere {
    const CPT = 'iv_campaign';

    // FREE limit (număr maxim de campanii totale)
    const FREE_MAX_CAMPAIGNS = 1;

    /**
     * Token list (OFFLINE licensing)
     * - cheia = token
     * - value = durata în zile (ex: 60 = ~2 luni, 180 = ~6 luni)
     *
     * Poți adăuga oricâte token-uri vrei aici.
     */
    private function token_catalog(): array {
        return [
            'QQv8YRKeDqUgTRVWKgFWzY96hTQjXbLYQqzP9itqY01xUPWKiGBf6mEVgTBuACVr'  => 60,   
            'vl8AUOap7jSitwPwjbMutSIw2v1zGT4gwfTWTeJTIKJa6GQc08a9gC0X77T7eFbG' => 30,  
            'oH9DYaf8OXVb5x4CRe4AJGCQ0ZsURthZmkWUT9SEo29ttqxryL2lFqVMAgcvLW9a' => 365,
            '2UORyEsX8S9ZvhI0ciHOLXi2DQL0gZbpQhXEUwc7ExJHSevsZVgzDdCLFvw2bafM' => 14,
            '0aCfhoEIAEFMHWoo0ryb1Y6L5F5UrFW7wvw' => 30,
            'uLryd3F1EZrbF93G5pcapVhRBqI9k6DyCiq' => 30,
            'RlJ32EZ55prKuypgKKbgT5h2jgbGKmgIDVIpxAqY83tBXrXYd4X3cQpOqJ6smJ0Q' => 30,
            'ysTkcmC0yPcApSA9aHjbmo4yE9mOWA8ar8kKAtm21r2OgC2MWySgKJylGlZCEDB0' => 30,
            '3IMitJ4HyRVUcZCTO3X9ewj2kLcxyvQ3h1OhV' => 30,
            'zQXxoFoWq7c9PvFKcvaxTvlCJq8MFxCelayPd' => 30,
            'BvoMbTjBwtcem5xmRR3r5MQPdyEqvPbuBVht7' => 30,
            'itw3VSfVCxDEvuvtS4qQc8QuThht227coXxss' => 30,
            'CpHiRBXtPigGP3tc5QMDKeT7QSo0O2kaf8gHG' => 30,
            'bf82jU8swle7pJwXrem1EBjekyOZKDPFM4PAH' => 30,
        ];
    }

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post_' . self::CPT, [$this, 'save_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_filter('post_updated_messages', [$this, 'updated_messages']);
        add_filter('redirect_post_location', [$this, 'redirect_after_save'], 10, 2);

        // Admin list columns
        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_columns_render'], 10, 2);
        add_filter('manage_edit-' . self::CPT . '_sortable_columns', [$this, 'admin_columns_sortable']);
        add_action('pre_get_posts', [$this, 'admin_columns_sort_query']);

        // Licensing admin
        add_action('admin_menu', [$this, 'add_license_page']);
        add_action('admin_init', [$this, 'register_license_settings']);
        add_action('admin_init', [$this, 'maybe_block_free_add_new']);
        add_action('admin_notices', [$this, 'admin_notices']);

        // WooCommerce pricing / sale
        add_filter('woocommerce_product_get_price', [$this, 'filter_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'filter_price'], 99, 2);

        add_filter('woocommerce_product_get_sale_price', [$this, 'filter_sale_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'filter_sale_price'], 99, 2);

        add_filter('woocommerce_product_is_on_sale', [$this, 'force_on_sale'], 99, 2);
        add_filter('woocommerce_product_variation_is_on_sale', [$this, 'force_on_sale'], 99, 2);

        add_filter('woocommerce_sale_flash', [$this, 'sale_badge_flash'], 99, 3);
    }

    /* -------------------- CPT -------------------- */

    public function register_cpt() {
        $labels = [
            'name'               => 'Campanii Publicitare',
            'singular_name'      => 'Campanie',
            'menu_name'          => 'Campanii Publicitare',
            'name_admin_bar'     => 'Campanie',
            'add_new'            => 'Adaugă Campanie Nouă',
            'add_new_item'       => 'Adaugă Campanie Nouă',
            'new_item'           => 'Campanie nouă',
            'edit_item'          => 'Editează Campanie',
            'view_item'          => 'Vezi Campanie',
            'all_items'          => 'Toate Campaniile',
            'search_items'       => 'Caută Campanii',
            'not_found'          => 'Nu există campanii.',
            'not_found_in_trash' => 'Nu există campanii în coș.',
        ];

        register_post_type(self::CPT, [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_position'      => 56,
            'menu_icon'          => 'dashicons-tag',
            'supports'           => ['title'],
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
        ]);
    }

    public function add_metaboxes() {
        add_meta_box(
            'iv_campaign_details',
            'Detalii campanie',
            [$this, 'render_metabox'],
            self::CPT,
            'normal',
            'high'
        );
    }

    /* -------------------- Licensing (OFFLINE) -------------------- */

    public function register_license_settings() {
        register_setting('iv_campanii_license', 'iv_campanii_token', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
    }

    public function add_license_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            'Licență',
            'Licență',
            'manage_options',
            'iv-campanii-license',
            [$this, 'render_license_page']
        );
    }

    private function license_status(): array {
        $token = trim((string) get_option('iv_campanii_token', ''));
        $expires_at = (string) get_option('iv_campanii_expires_at', '');
        $activated_at = (string) get_option('iv_campanii_activated_at', '');

        $now = current_time('timestamp');
        $is_valid = false;

        if ($token !== '' && $expires_at !== '') {
            $exp_ts = strtotime($expires_at . ' 23:59:59');
            if ($exp_ts !== false && $now <= $exp_ts) {
                $is_valid = true;
            }
        }

        return [
            'token' => $token,
            'valid' => $is_valid,
            'activated_at' => $activated_at,
            'expires_at' => $expires_at,
        ];
    }

    private function is_premium(): bool {
        $st = $this->license_status();
        return !empty($st['valid']);
    }

    private function activate_token_offline(string $token): array {
        $token = trim($token);
        $catalog = $this->token_catalog();

        if (!isset($catalog[$token])) {
            // invalid token
            update_option('iv_campanii_expires_at', '');
            update_option('iv_campanii_activated_at', '');
            return ['ok' => false, 'msg' => 'Token invalid.'];
        }

        $days = (int) $catalog[$token];
        if ($days <= 0) $days = 1;

        $now = current_time('timestamp');
        $activated_at = date_i18n('Y-m-d', $now);
        $expires_ts = $now + ($days * DAY_IN_SECONDS);
        $expires_at = date_i18n('Y-m-d', $expires_ts);

        update_option('iv_campanii_token', $token);
        update_option('iv_campanii_activated_at', $activated_at);
        update_option('iv_campanii_expires_at', $expires_at);

        return ['ok' => true, 'msg' => 'Licență activată.', 'expires_at' => $expires_at];
    }

    public function render_license_page() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['iv_license_activate']) && check_admin_referer('iv_license_activate_action')) {
            $token = isset($_POST['iv_token']) ? sanitize_text_field($_POST['iv_token']) : '';
            $res = $this->activate_token_offline($token);
            if ($res['ok']) {
                echo '<div class="notice notice-success"><p>' . esc_html($res['msg']) . ' Expiră la: <code>' . esc_html($res['expires_at']) . '</code></p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($res['msg']) . '</p></div>';
            }
        }

        if (isset($_POST['iv_license_clear']) && check_admin_referer('iv_license_clear_action')) {
            update_option('iv_campanii_token', '');
            update_option('iv_campanii_activated_at', '');
            update_option('iv_campanii_expires_at', '');
            echo '<div class="notice notice-success"><p>Licența a fost ștearsă. (Free)</p></div>';
        }

        $st = $this->license_status();
        $token = $st['token'];

        ?>
        <div class="wrap">
            <h1>Licență Campanii de reducere</h1>

            <div style="max-width:820px;background:#fff;border:1px solid #e5e5e5;padding:16px;border-radius:8px">
                <p>
                    Status:
                    <?php if ($st['valid']): ?>
                        <strong style="color:green">PREMIUM activ</strong>
                        <br>
                        Activat la: <code><?php echo esc_html($st['activated_at'] ?: '—'); ?></code><br>
                        Expiră la: <code><?php echo esc_html($st['expires_at'] ?: '—'); ?></code>
                    <?php else: ?>
                        <strong style="color:#cc0000">GRATUIT</strong>
                        <br>
                        Limită: <strong><?php echo (int) self::FREE_MAX_CAMPAIGNS; ?></strong> campanie.
                        <?php if (!empty($token) && empty($st['expires_at'])): ?>
                            <br><em>Tokenul salvat nu este valid.</em>
                        <?php elseif (!empty($token) && !empty($st['expires_at'])): ?>
                            <br><em>Token expirat la <?php echo esc_html($st['expires_at']); ?>.</em>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>

                <hr>

                <form method="post">
                    <?php wp_nonce_field('iv_license_activate_action'); ?>
                    <label for="iv_token"><strong>Token licență</strong></label><br>
                    <input type="text" id="iv_token" name="iv_token" value="<?php echo esc_attr($token); ?>" class="regular-text" style="max-width:420px">
                    <p class="description">
                    Daca imtampinati probleme la activare puteti lua contactul la:<br>
                    📧 <a href="mailto:ionutvoicudesi@gmail.com">ionutvoicudesi@gmail.com</a>
</p>
                    <p>
                        <button class="button button-primary" name="iv_license_activate" value="1">Activează token</button>
                    </p>
                </form>

                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('iv_license_clear_action'); ?>
                    <button class="button" name="iv_license_clear" value="1">Șterge licența (Free)</button>
                </form>
            </div>
        </div>
        <?php
    }

    private function campaign_count_total(): int {
        $counts = wp_count_posts(self::CPT);
        $total = 0;
        foreach (['publish','draft','pending','future','private'] as $st) {
            if (isset($counts->$st)) $total += (int)$counts->$st;
        }
        return $total;
    }

    public function maybe_block_free_add_new() {
        if (!is_admin()) return;
        if ($this->is_premium()) return;

        $is_new = isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'post-new.php'
            && isset($_GET['post_type']) && $_GET['post_type'] === self::CPT;

        if (!$is_new) return;

        if ($this->campaign_count_total() >= self::FREE_MAX_CAMPAIGNS) {
            wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT . '&iv_limit=1'));
            exit;
        }
    }

    public function admin_notices() {
        if (!is_admin()) return;

        if (isset($_GET['iv_limit']) && $_GET['iv_limit'] == '1') {
            echo '<div class="notice notice-warning"><p><strong>Versiunea FREE:</strong> poți crea doar '
                . (int)self::FREE_MAX_CAMPAIGNS
                . ' campanie. Activează Premium din <em>Campanii Publicitare → Licență</em>.</p></div>';
        }
    }

    /* -------------------- Admin UI (Metabox) -------------------- */

    public function render_metabox($post) {
        wp_nonce_field('iv_campaign_save', 'iv_campaign_nonce');

        $type   = get_post_meta($post->ID, '_iv_type', true) ?: 'percent';
        $value  = get_post_meta($post->ID, '_iv_value', true);
        $start  = get_post_meta($post->ID, '_iv_start', true);
        $end    = get_post_meta($post->ID, '_iv_end', true);

        $saved = get_post_meta($post->ID, '_iv_cat_ids', true);
        $saved = is_array($saved) ? $saved : (array)$saved;

        ?>
        <style>
            .iv-grid{display:grid;grid-template-columns:220px 1fr;gap:12px 18px;max-width:950px}
            .iv-grid label{font-weight:600}
            .iv-help{color:#666;font-size:12px;margin-top:4px}
            .iv-row{display:contents}
            .iv-input{max-width:520px;width:100%}
        </style>

        <div class="iv-grid">
            <div class="iv-row">
                <label for="iv_type">Tip Campanie</label>
                <div>
                    <select id="iv_type" name="iv_type" class="iv-input">
                        <option value="fixed" <?php selected($type, 'fixed'); ?>>Sumă fixă</option>
                        <option value="percent" <?php selected($type, 'percent'); ?>>Procente</option>
                    </select>
                </div>
            </div>

            <div class="iv-row">
                <label for="iv_value">Valoare</label>
                <div>
                    <input id="iv_value" name="iv_value" type="number" class="iv-input" step="0.01" min="0"
                           value="<?php echo esc_attr($value); ?>" required />
                    <div class="iv-help" id="iv_value_help">Se aplică în funcție de tipul selectat.</div>
                </div>
            </div>

            <div class="iv-row">
                <label for="iv_cat_ids">Categoriile pentru campanie</label>
                <div>
                    <?php
                    $terms = get_terms([
                        'taxonomy' => 'product_cat',
                        'hide_empty' => false,
                        'orderby' => 'name',
                        'order' => 'ASC',
                    ]);

                    $by_parent = [];
                    foreach ($terms as $t) $by_parent[(int)$t->parent][] = $t;

                    $walk = function($parent, $depth) use (&$walk, $by_parent, $saved) {
                        if (empty($by_parent[$parent])) return;
                        foreach ($by_parent[$parent] as $t) {
                            $pad = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
                            $sel = in_array((int)$t->term_id, $saved, true) ? 'selected' : '';
                            echo '<option value="'.esc_attr($t->term_id).'" '.$sel.'>'.$pad.esc_html($t->name).'</option>';
                            $walk((int)$t->term_id, $depth + 1);
                        }
                    };
                    ?>
                    <select id="iv_cat_ids" name="iv_cat_ids[]" class="iv-input" multiple size="10">
                        <?php $walk(0, 0); ?>
                    </select>
                    <div class="iv-help">Ține apăsat CTRL (Windows) / CMD (Mac) ca să selectezi mai multe.</div>
                </div>
            </div>

            <div class="iv-row">
                <label for="iv_start">Data început Campanie</label>
                <div>
                    <input id="iv_start" name="iv_start" type="text" class="iv-input iv-date"
                           value="<?php echo esc_attr($start); ?>" placeholder="YYYY-MM-DD" autocomplete="off" required />
                </div>
            </div>

            <div class="iv-row">
                <label for="iv_end">Data finalizare Campanie</label>
                <div>
                    <input id="iv_end" name="iv_end" type="text" class="iv-input iv-date"
                           value="<?php echo esc_attr($end); ?>" placeholder="YYYY-MM-DD" autocomplete="off" required />
                    <div class="iv-help">Reducerea este activă inclusiv în ziua de finalizare.</div>
                </div>
            </div>
        </div>

        <script>
            (function(){
                const type = document.getElementById('iv_type');
                const help = document.getElementById('iv_value_help');
                const val  = document.getElementById('iv_value');

                function sync(){
                    if(type.value === 'fixed'){
                        help.textContent = 'Sumă fixă în LEI (ex: 50 înseamnă -50 lei).';
                    } else {
                        help.textContent = 'Procent (ex: 10 înseamnă -10%).';
                    }
                }
                type.addEventListener('change', sync);
                sync();
            })();
        </script>
        <?php
    }

    public function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::CPT) return;

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style(
            'jquery-ui-css',
            'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css',
            [],
            '1.13.2'
        );

        wp_add_inline_script('jquery-ui-datepicker', "
            jQuery(function($){
                $('.iv-date').datepicker({ dateFormat: 'yy-mm-dd' });
            });
        ");
    }

    /* -------------------- Save meta -------------------- */

    public function save_meta($post_id, $post) {
        if (!isset($_POST['iv_campaign_nonce']) || !wp_verify_nonce($_POST['iv_campaign_nonce'], 'iv_campaign_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // FREE gating: blocăm crearea peste limită
        if (!$this->is_premium()) {
            $is_new = isset($_POST['original_post_status']) && $_POST['original_post_status'] === 'auto-draft';
            if ($is_new && $this->campaign_count_total() >= self::FREE_MAX_CAMPAIGNS) {
                wp_die('Versiunea FREE permite doar ' . (int)self::FREE_MAX_CAMPAIGNS . ' campanie. Activează Premium din Campanii Publicitare → Licență.');
            }
        }

        $type  = isset($_POST['iv_type']) ? sanitize_text_field($_POST['iv_type']) : 'percent';
        $value = isset($_POST['iv_value']) ? floatval($_POST['iv_value']) : 0;

        $cat_ids = isset($_POST['iv_cat_ids']) ? array_map('intval', (array) $_POST['iv_cat_ids']) : [];
        $cat_ids = array_values(array_filter($cat_ids));

        $start = isset($_POST['iv_start']) ? sanitize_text_field($_POST['iv_start']) : '';
        $end   = isset($_POST['iv_end']) ? sanitize_text_field($_POST['iv_end']) : '';

        if (!in_array($type, ['fixed', 'percent'], true)) $type = 'percent';
        if ($value < 0) $value = 0;
        if ($type === 'percent' && $value > 100) $value = 100;

        update_post_meta($post_id, '_iv_type', $type);
        update_post_meta($post_id, '_iv_value', $value);
        update_post_meta($post_id, '_iv_cat_ids', $cat_ids);
        update_post_meta($post_id, '_iv_start', $start);
        update_post_meta($post_id, '_iv_end', $end);
    }

    /* -------------------- Admin table columns -------------------- */

    public function admin_columns($columns) {
        $new = [];
        $new['cb'] = $columns['cb'] ?? '';
        $new['title'] = 'Titlu';
        $new['iv_type']  = 'Tip';
        $new['iv_value'] = 'Valoare';
        $new['iv_cat']   = 'Categorii';
        $new['author'] = 'Adăugat de';
        $new['iv_start'] = 'Data început';
        $new['iv_end']   = 'Data sfârșit';
        $new['date'] = $columns['date'] ?? 'Dată';
        return $new;
    }

    public function admin_columns_render($column, $post_id) {
        switch ($column) {
            case 'iv_type':
                $type = get_post_meta($post_id, '_iv_type', true);
                echo esc_html($type === 'fixed' ? 'Sumă fixă' : 'Procente');
                break;

            case 'author':
                $u = get_userdata((int) get_post_field('post_author', $post_id));
                echo $u ? esc_html($u->user_login) : '—';
                break;

            case 'iv_value':
                $type  = get_post_meta($post_id, '_iv_type', true);
                $value = get_post_meta($post_id, '_iv_value', true);
                if ($value === '' || $value === null) { echo '—'; break; }
                echo esc_html($type === 'fixed' ? strip_tags(wc_price((float)$value)) : ((float)$value . '%'));
                break;

            case 'iv_cat':
                $cat_ids = get_post_meta($post_id, '_iv_cat_ids', true);
                $cat_ids = is_array($cat_ids) ? $cat_ids : [];
                if (empty($cat_ids)) { echo '—'; break; }
                $names = [];
                foreach ($cat_ids as $id) {
                    $term = get_term((int)$id, 'product_cat');
                    if (!is_wp_error($term) && $term) $names[] = $term->name;
                }
                echo $names ? esc_html(implode(', ', $names)) : '—';
                break;

            case 'iv_start':
                echo esc_html(get_post_meta($post_id, '_iv_start', true) ?: '—');
                break;

            case 'iv_end':
                echo esc_html(get_post_meta($post_id, '_iv_end', true) ?: '—');
                break;
        }
    }

    public function admin_columns_sortable($columns) {
        $columns['iv_type']  = 'iv_type';
        $columns['iv_value'] = 'iv_value';
        $columns['iv_start'] = 'iv_start';
        $columns['iv_end']   = 'iv_end';
        return $columns;
    }

    public function admin_columns_sort_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::CPT) return;

        $orderby = $query->get('orderby');
        if (!$orderby) return;

        $map = [
            'iv_type'  => '_iv_type',
            'iv_value' => '_iv_value',
            'iv_start' => '_iv_start',
            'iv_end'   => '_iv_end',
        ];

        if (isset($map[$orderby])) {
            $query->set('meta_key', $map[$orderby]);
            $query->set('orderby', $orderby === 'iv_value' ? 'meta_value_num' : 'meta_value');
        }
    }

    /* -------------------- Messages + redirect -------------------- */

    public function updated_messages($messages) {
        $messages[self::CPT] = [
            0  => '',
            1  => 'Campania a fost salvată.',
            4  => 'Campania a fost actualizată.',
            6  => 'Campania a fost publicată.',
            7  => 'Campania a fost salvată.',
            10 => 'Draft salvat.',
        ];
        return $messages;
    }

    public function redirect_after_save($location, $post_id) {
        if (get_post_type($post_id) !== self::CPT) return $location;

        if (isset($_POST['save']) || isset($_POST['publish']) || isset($_POST['post_status'])) {
            return admin_url('edit.php?post_type=' . self::CPT . '&iv_saved=1');
        }
        return $location;
    }

    /* -------------------- Campaign logic -------------------- */

    private function get_active_campaigns_for_product($product_id) {
        $today = current_time('Y-m-d');

        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) return [];
        $product_cat_ids = array_map(fn($t) => (int)$t->term_id, $terms);

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => ['publish'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        if (empty($q->posts)) return [];

        $active = [];
        foreach ($q->posts as $cid) {
            $start = get_post_meta($cid, '_iv_start', true);
            $end   = get_post_meta($cid, '_iv_end', true);
            if (!$start || !$end) continue;
            if (!($today >= $start && $today <= $end)) continue;

            $campaign_cats = get_post_meta($cid, '_iv_cat_ids', true);
            $campaign_cats = is_array($campaign_cats) ? $campaign_cats : [];
            if (empty($campaign_cats)) continue;

            if (array_intersect($campaign_cats, $product_cat_ids)) {
                $active[] = $cid;
            }
        }

        return $active;
    }

    private function apply_best_discount_to_regular($regular_price, $product_id) {
        $campaign_ids = $this->get_active_campaigns_for_product($product_id);
        if (empty($campaign_ids)) return $regular_price;

        $best_price = $regular_price;

        foreach ($campaign_ids as $cid) {
            $type  = get_post_meta($cid, '_iv_type', true);
            $value = (float) get_post_meta($cid, '_iv_value', true);

            $new_price = $regular_price;
            if ($type === 'fixed') {
                $new_price = max(0, $regular_price - $value);
            } else {
                $new_price = max(0, $regular_price * (1 - ($value / 100)));
            }

            if ($new_price < $best_price) $best_price = $new_price;
        }

        return $best_price;
    }

    private function get_best_campaign_for_product($product_id) {
        $campaign_ids = $this->get_active_campaigns_for_product($product_id);
        if (empty($campaign_ids)) return null;

        $product = wc_get_product($product_id);
        if (!$product) return null;

        $regular = (float) $product->get_regular_price();
        if ($regular <= 0) return null;

        $best = null;
        $best_price = $regular;

        foreach ($campaign_ids as $cid) {
            $type  = get_post_meta($cid, '_iv_type', true);
            $value = (float) get_post_meta($cid, '_iv_value', true);

            $new_price = $regular;
            if ($type === 'fixed') $new_price = max(0, $regular - $value);
            else $new_price = max(0, $regular * (1 - ($value / 100)));

            if ($new_price < $best_price) {
                $best_price = $new_price;
                $best = [
                    'id' => $cid,
                    'type' => $type,
                    'value' => $value,
                    'regular' => $regular,
                    'sale' => $best_price,
                ];
            }
        }

        return $best;
    }

    /* -------------------- WooCommerce hooks -------------------- */

    public function filter_sale_price($sale_price, $product) {
        if (is_admin() && !wp_doing_ajax()) return $sale_price;
        if (!is_a($product, 'WC_Product')) return $sale_price;

        $regular = (float) $product->get_regular_price();
        if ($regular <= 0) return $sale_price;

        $product_id = $product->get_id();
        $campaign_ids = $this->get_active_campaigns_for_product($product_id);
        if (empty($campaign_ids)) return $sale_price;

        $best = $this->apply_best_discount_to_regular($regular, $product_id);
        if ($best >= $regular) return $sale_price;

        return $best;
    }

    public function filter_price($price, $product) {
        if (is_admin() && !wp_doing_ajax()) return $price;
        if (!is_a($product, 'WC_Product')) return $price;

        $regular = (float) $product->get_regular_price();
        if ($regular <= 0) return $price;

        $product_id = $product->get_id();
        $campaign_ids = $this->get_active_campaigns_for_product($product_id);
        if (empty($campaign_ids)) return $price;

        $best = $this->apply_best_discount_to_regular($regular, $product_id);
        if ($best >= $regular) return $price;

        return $best;
    }

    public function force_on_sale($on_sale, $product) {
        if (is_admin() && !wp_doing_ajax()) return $on_sale;
        if (!is_a($product, 'WC_Product')) return $on_sale;

        $regular = (float) $product->get_regular_price();
        if ($regular <= 0) return $on_sale;

        $product_id = $product->get_id();
        $campaign_ids = $this->get_active_campaigns_for_product($product_id);
        if (empty($campaign_ids)) return $on_sale;

        $best = $this->apply_best_discount_to_regular($regular, $product_id);
        return ($best < $regular) ? true : $on_sale;
    }

    public function sale_badge_flash($html, $post, $product) {
        if (!is_a($product, 'WC_Product')) return $html;

        $best = $this->get_best_campaign_for_product($product->get_id());
        if (!$best) return $html;

        if ($best['type'] === 'percent') {
            $label = '-' . rtrim(rtrim(number_format($best['value'], 2, '.', ''), '0'), '.') . '%';
        } else {
            $label = '-' . strip_tags(wc_price($best['value']));
        }

        return '<span class="onsale">' . esc_html($label) . '</span>';
    }
}

new IV_Campanii_Reducere();
