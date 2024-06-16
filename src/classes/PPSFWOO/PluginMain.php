<?php

namespace PPSFWOO;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use PPSFWOO\AjaxActions;
use PPSFWOO\Webhook;
use PPSFWOO\PayPal;
use PPSFWOO\User;

class PluginMain
{
    private static $instance = NULL;

    public static $options_group = "ppsfwoo_options_group";

    public static $upgrade_link = "https://wp-subscriptions.com/";

    public static $ppcp_settings_url = "admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway&ppcp-tab=ppcp-connection";

    public static $options = [
        'ppsfwoo_thank_you_page_id' => [
            'name'    => 'Order thank you page',
            'type'    => 'select',
            'default' => 0
        ],
        'ppsfwoo_rows_per_page' => [
            'name'    => 'Subscribers Rows Per Page',
            'type'    => 'select',
            'options' => [
                '10' => 10,
                '20' => 20,
                '30' => 30,
                '40' => 40,
                '50' => 50
            ],
            'default' => '10'
        ],
        'ppsfwoo_delete_plugin_data' => [
            'name'    => 'Delete plugin data on deactivation',
            'type'    => 'checkbox',
            'default' => 0
        ],
        'ppsfwoo_subscribed_webhooks' => [
            'type'    => 'skip_settings_field',
            'default' => ''
        ],
        'ppsfwoo_webhook_id' => [
            'type'    => 'skip_settings_field',
            'default' => ''
        ],
        'ppsfwoo_plans' => [
            'type'    => 'skip_settings_field',
            'default' => [
                '000' => [
                    'plan_name'     => 'Refresh required',
                    'product_name'  => '',
                    'frequency'     => ''
                ]
            ]
        ]
    ];

    public $client_id,
           $paypal_url,
           $ppsfwoo_webhook_id,
           $ppsfwoo_subscribed_webhooks,
           $ppsfwoo_plans,
           $ppsfwoo_thank_you_page_id,
           $ppsfwoo_rows_per_page,
           $ppsfwoo_delete_plugin_data,
           $user,
           $event_type,
           $template_dir,
           $plugin_dir_url;

    protected function __construct()
    {
        $env = PayPal::env();

        $this->template_dir = plugin_dir_path(PPSFWOO_PLUGIN_PATH) . "templates/";

        $this->plugin_dir_url = plugin_dir_url(PPSFWOO_PLUGIN_PATH);

        $this->client_id = $env['client_id'];

        $this->paypal_url = $env['paypal_url'];

        foreach (self::$options as $option => $option_value)
        {
            $this->$option = get_option($option);
        }

        register_activation_hook(PPSFWOO_PLUGIN_PATH, [$this, 'plugin_activation']);

        register_deactivation_hook(PPSFWOO_PLUGIN_PATH, [$this, 'plugin_deactivation']);

        $this->add_actions();

        $this->add_filters();
    }

    public static function get_instance()
    {
        if (self::$instance === null) {

            self::$instance = new self();

        }

        return self::$instance;
    }

    protected function add_actions()
    {
        add_action('admin_init', [$this, 'register_settings']);

        add_action('admin_init', [$this, 'handle_export_action']);

        add_action('admin_menu', [$this, 'register_options_page']);

        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);

        add_action('wp_ajax_ppsfwoo_admin_ajax_callback', [new AjaxActions(), 'admin_ajax_callback']);

        add_action('wp_ajax_nopriv_ppsfwoo_admin_ajax_callback', [new AjaxActions(), 'admin_ajax_callback']);

        add_action('edit_user_profile', [$this, 'add_custom_user_fields']);
        
        add_action('rest_api_init', [new Webhook(), 'rest_api_init']);
        
        add_action('before_woocommerce_init', [$this, 'wc_declare_compatibility']);

        add_action('woocommerce_product_meta_start', [$this, 'add_custom_paypal_button']);

        add_action('plugins_loaded', 'ppsfwoo_register_product_type');
        
        add_action('woocommerce_product_data_panels', [$this, 'options_product_tab_content']);
        
        add_action('woocommerce_process_product_meta_ppsfwoo', [$this, 'save_option_field']);

        add_action('admin_head', [$this, 'edit_product_css']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend']);

        add_action('wc_ajax_ppc-webhooks-resubscribe', [$this, 'shutdown']);

        add_action('woocommerce_paypal_payments_gateway_migrate_on_update', [$this, 'shutdown'], 999);
    }

    protected function add_filters()
    {
        add_filter('plugin_action_links_subscriptions-for-woo/subscriptions-for-woo.php', [$this, 'settings_link']);

        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        add_filter('wp_new_user_notification_email', [$this, 'new_user_notification_email'], 10, 4);

        add_filter('woocommerce_get_price_html', [$this, 'change_product_price_display']);

        add_filter('product_type_selector', [$this, 'add_product']);

        add_filter('woocommerce_product_data_tabs', [$this, 'custom_product_tabs']);
    }

    public static function plugin_data($data)
    {
        $plugin_data = get_file_data(PPSFWOO_PLUGIN_PATH, [
            'Version' => 'Version',
            'Name'    => 'Plugin Name'
        ], 'plugin');

        return $plugin_data[$data];
    }

    public function shutdown()
    {
        add_action('shutdown', [new Webhook(), 'resubscribe']);
    }

    public function handle_export_action()
    {
        $export_table = isset($_GET['export_table']) ? sanitize_text_field(wp_unslash($_GET['export_table'])): "";

        if(empty($export_table) || $export_table !== 'true') {

            return;

        }

        if (!isset($_GET['_wpnonce'] ) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'db_export_nonce')) {

            wp_die('Security check failed.');

        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ppsfwoo_subscriber", ARRAY_A);

        if(!isset($data[0])) {

            exit;

        }

        $columns = array_keys($data[0]);

        $values = array();

        foreach ($data as $row)
        {
            $row_values = array_map([$wpdb, 'prepare'], array_fill(0, count($row), '%s'), $row);

            $values[] = '(' . implode(', ', $row_values) . ')';
        }

        $db_name = DB_NAME;

        $sql_content = "INSERT INTO `$db_name`.`{$wpdb->prefix}ppsfwoo_subscriber` (`" . implode('`, `', $columns) . "`) VALUES \n";
            
        $sql_content .= implode(",\n", $values) . ";\n";

        header('Content-Type: application/sql');

        header('Content-Disposition: attachment; filename="table_backup.sql"');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $sql_content;

        exit();
    }

    public function subs_id_redirect_nonce()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing 
        $is_ajax = isset($_POST['action'], $_POST['method']) && $_POST['method'] === __FUNCTION__;

        $nonce_name = "";

        if(!session_id()) session_start();

        if (!isset($_SESSION['ppsfwoo_customer_nonce'])) {

            $nonce_name = $_SESSION['ppsfwoo_customer_nonce'] = wp_generate_password(24, false);

        } else {

            $nonce_name = $_SESSION['ppsfwoo_customer_nonce'];

        }

        return $is_ajax ? wp_json_encode(['nonce' => wp_create_nonce($nonce_name)]): $nonce_name;
    }

    public function enqueue_frontend()
    {
        if(!is_admin()) {
            
            wp_enqueue_style('ppsfwoo-styles', $this->plugin_dir_url . "css/frontend.min.css", [], self::plugin_data('Version'));

        }
        
        $subs_id = isset($_GET['subs_id']) ? sanitize_text_field(wp_unslash($_GET['subs_id'])): NULL;

        if (
            !isset($subs_id, $_GET['subs_id_redirect_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['subs_id_redirect_nonce'])), $this->subs_id_redirect_nonce())
        ) {

            return;

        }

        wp_enqueue_script('ppsfwoo-scripts', $this->plugin_dir_url . "js/get-sub.min.js", ['jquery'], self::plugin_data('Version'), true);

        wp_localize_script('ppsfwoo-scripts', 'ppsfwoo_ajax_var', [
            'subs_id' => $subs_id
        ]);
    }

    public static function add_product($types)
    {
        if(PPSFWOO_PLUGIN_EXTRAS && !current_user_can('ppsfwoo_manage_subscription_products')) {

            return $types;
        }

        $types['ppsfwoo'] = "Subscription";

        return $types;
    }

    public static function edit_product_css()
    {
        echo '<style>ul.wc-tabs li.ppsfwoo_options a::before {
          content: "\f515" !important;
        }</style>';
    }

    public static function custom_product_tabs($tabs)
    {
        if(PPSFWOO_PLUGIN_EXTRAS && !current_user_can('ppsfwoo_manage_subscription_products')) {

            return $tabs;
        }
        
        $tabs['ppsfwoo'] = [
            'label'     => 'Subscription Plan',
            'target'    => 'ppsfwoo_options',
            'class'     => [
                'show_if_ppsfwoo'
            ],
            'priority' => 11
        ];

        return $tabs;
    }

    public function options_product_tab_content()
    {
        ?><div id='ppsfwoo_options' class='panel woocommerce_options_panel'><?php

            ?><div class='options_group'><?php

                if($plans = $this->ppsfwoo_plans) {

                    foreach($plans as $plan_id => $plan_data)
                    {
                        $plans[$plan_id] = "{$plan_data['product_name']} [{$plan_data['plan_name']}] [{$plan_data['frequency']}]";
                    }

                    wp_nonce_field('ppsfwoo_plan_id_nonce', 'ppsfwoo_plan_id_nonce', false);

                    woocommerce_wp_select([
                        'id'          => 'ppsfwoo_plan_id',
                        'label'       => 'PayPal Subscription Plan',
                        'options'     => $plans,
                        'desc_tip'    => true,
                        'description' => 'Subscription plans created in your PayPal account will be listed here in the format:<br />"Product [Plan] [Frequency]"',
                    ]);
                }

            ?></div>

        </div><?php
    }

    public function save_option_field($post_id)
    {
        if (!isset($_POST['ppsfwoo_plan_id']) ||
            !isset($_POST['ppsfwoo_plan_id_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ppsfwoo_plan_id_nonce'])), 'ppsfwoo_plan_id_nonce')
        ) {

            wp_die("Security check failed");

        }

        $plan_id = sanitize_text_field(wp_unslash($_POST['ppsfwoo_plan_id']));

        update_post_meta($post_id, 'ppsfwoo_plan_id', $plan_id);
    }

    protected function activate_subscriber($response)
    {
        if(isset($response['response']['status']) && "ACTIVE" === $response['response']['status']) {

            $this->subscribe(new User($response));

            return true;
        }

        return false;
    }

    public function display_subs($email = "")
    {
        if(PPSFWOO_PLUGIN_EXTRAS && !current_user_can('ppsfwoo_manage_subscriptions')) {

            echo "<p>You're user permissions do not allow you to view this content. Please contact your website administrator.</p>";

            return false;

        }

        global $wpdb;

        $per_page = $this->ppsfwoo_rows_per_page ?: 10;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
        $subs_page_num = isset($_GET['subs_page_num']) ? sanitize_text_field(wp_unslash($_GET['subs_page_num'])): NULL;

        $page = isset($subs_page_num) ? absint($subs_page_num) : 1;

        $offset = max(0, ($page - 1) * $per_page);

        if($email) {

            $stmt = $wpdb->prepare(
                "SELECT `s`.*
                FROM {$wpdb->prefix}ppsfwoo_subscriber `s`
                JOIN {$wpdb->prefix}users `u`
                    ON `s`.`wp_customer_id` = `u`.`ID`
                WHERE `u`.`user_email` = %s;",
                $email
            );

        } else {

            $stmt = $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ppsfwoo_subscriber ORDER BY order_id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            );

        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($stmt);

        $num_subs = is_array($results) ? sizeof($results): 0;

        if($num_subs) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ppsfwoo_subscriber");

            $total_pages = ceil($total_rows / $per_page);

            self::display_template("subscriber-table-settings-page", [
                'results'    => $results,
                'paypal_url' => PayPal::env()['paypal_url']
            ]);

            if($email === "" && $total_pages > 1) {

                echo "<div class='pagination'>Page: ";

                for ($i = 1; $i <= $total_pages; $i++)
                {
                    $href = esc_url(add_query_arg([
                        'page'          => 'subscriptions_for_woo',
                        'subs_page_num' => $i
                    ]));

                    $class = $i === $page ? " current": "";

                    echo "<a href='" . esc_attr($href) . "' class='pagination-link" . esc_attr($class) . "'>" . esc_attr($i) . "</a>";
                }

                echo "</div>";
            }
        }

        return $num_subs;
    }

    public function wc_declare_compatibility()
    {
        if (class_exists(FeaturesUtil::class)) {

            FeaturesUtil::declare_compatibility('custom_order_tables', PPSFWOO_PLUGIN_PATH);
            
        }
    }

    protected static function display_template($template = "", $args = [])
    {
        $instance = PluginMain::get_instance();

        $template = $instance->template_dir . "/$template.php";

        if(!file_exists($template)) {

            return;

        }

        extract($args);
            
        include $template;
    }

    public function add_custom_paypal_button()
    {
        global $product;

        if(!$product->is_type('ppsfwoo')) {

            return;
        }
        
        if($plan_id = self::get_plan_id_by_product_id(get_the_ID())) {

            self::display_template("paypal-button", [
                'plan_id' => $plan_id
            ]);

            wp_enqueue_script('paypal-sdk', $this->plugin_dir_url . "js/paypal-button.min.js", [], self::plugin_data('Version'), true);

            wp_localize_script('paypal-sdk', 'ppsfwoo_paypal_ajax_var', [
                'client_id' => $this->client_id,
                'plan_id'   => $plan_id,
                'redirect'  => get_permalink($this->ppsfwoo_thank_you_page_id)
            ]);
        }
    }

    public function change_product_price_display($price)
    {
        global $product;

        $product_id = $product ? $product->get_id(): false;

        if(false === $product_id || !$product->is_type('ppsfwoo')) {

            return $price;

        }

        if ($frequency = $this->get_plan_frequency_by_product_id($product_id)) {

            $dom = new \DOMDocument();

            @$dom->loadHTML($price, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $span = $dom->getElementsByTagName('span');

            foreach ($span as $tag)
            {
                $current = $tag->nodeValue;

                $new = $current . "/" . ucfirst(strtolower($frequency));

                $tag->nodeValue = $new;
            }

            return $dom->saveHTML();
        }

        return $price;
    }

    public function new_user_notification_email($notification_email, $user, $blogname)
    {
        $key = get_password_reset_key($user);

        if (is_wp_error($key)) { return; }

        $message  = sprintf('Username: %s', $user->user_login) . "\r\n\r\n";

        $message .= 'To set your password, visit the following address:' . "\r\n\r\n";

        $message .= get_permalink(wc_get_page_id('myaccount')) . "?action=rp&key=$key&login=" . rawurlencode($user->user_login) . "\r\n\r\n";

        $notification_email['message'] = $message;

        return $notification_email;
    }

    protected function get_page_by_title($title)
    {
        $query = new \WP_Query([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'title'          => $title,
        ]);

        if ($query->have_posts()) {

            $query->the_post();
            
            $page_id = get_the_ID();
            
            wp_reset_postdata();
            
            return $page_id;
        
        } else {
        
            return false;
        
        }
    }

    protected function create_thank_you_page()
    {
        $title = "Thank you for your order";

        $page_id = $this->get_page_by_title($title);

        if (!$page_id) {

            $thank_you_template = $this->plugin_dir_url . "templates/thank-you.php";

            $response = wp_remote_get($thank_you_template);

            $page_id = wp_insert_post([
                'post_title'     => $title,
                'post_content'   => wp_remote_retrieve_body($response),
                'post_status'    => 'publish',
                'post_type'      => 'page'
            ]);

        }

        update_option('ppsfwoo_thank_you_page_id', $page_id);
    }

    public function plugin_activation()
    {
        foreach (self::$options as $option => $option_value)
        {
            add_option($option, $option_value['default']);
        }

        $this->db_install();

        $this->create_thank_you_page();

        $Webhook = new Webhook();

        if(!$Webhook->id()) {

            $Webhook->create();

        }
    }

    public function plugin_deactivation()
    {
        global $wpdb;

        if("1" === $this->ppsfwoo_delete_plugin_data) {
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ppsfwoo_subscriber");

            $Webhook = new Webhook();

            $Webhook->delete();
            
            wp_delete_post($this->ppsfwoo_thank_you_page_id, true);
            
            foreach(self::$options as $option => $option_value) {

                delete_option($option);

            }
        }
    }

    protected function db_install()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $create_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ppsfwoo_subscriber ( 
          id varchar(64) NOT NULL,
          wp_customer_id bigint(20) UNSIGNED NOT NULL,
          paypal_plan_id varchar(64) NOT NULL,
          order_id bigint(20) UNSIGNED DEFAULT NULL,
          event_type varchar(35) NOT NULL,
          created datetime DEFAULT current_timestamp(),
          last_updated datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          canceled_date datetime DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
          PRIMARY KEY (id),
          KEY idx_wp_customer_id (wp_customer_id),
          KEY idx_order_id (order_id),
          FOREIGN KEY fk_user_id (wp_customer_id)
            REFERENCES {$wpdb->prefix}users(ID)
            ON UPDATE CASCADE ON DELETE CASCADE,
          FOREIGN KEY fk_order_id (order_id)
            REFERENCES {$wpdb->prefix}wc_orders(id)
            ON UPDATE CASCADE ON DELETE CASCADE
        ) $charset_collate;";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($create_table);
    }

    public function plugin_row_meta($links, $file)
    {
        if(plugin_basename(PPSFWOO_PLUGIN_PATH) !== $file) {

            return $links;
        }

        $upgrade = [
            'docs' => '<a href="' . esc_url(self::$upgrade_link) . '" target="_blank"><span class="dashicons dashicons-star-filled" style="font-size: 14px; line-height: 1.5"></span>Upgrade</a>'
        ];

        $bugs = [
            'bugs' => '<a href="' . esc_url("https://github.com/marknokes/subscriptions-for-woo/issues/new?assignees=marknokes&labels=bug&template=bug_report.md") . '" target="_blank">Submit a bug</a>'
        ];

        if (!PPSFWOO_PLUGIN_EXTRAS) {

            return array_merge($links, $upgrade, $bugs);

        }

        return array_merge($links, $bugs);
    }

    public function settings_link($links)
    {
        $settings_url = esc_url(admin_url('admin.php?page=subscriptions_for_woo'));

        $settings = ["<a href='$settings_url'>Settings</a>"];
        
        return array_merge($settings, $links);
    }

    public function admin_enqueue_scripts($hook)
    {
        if ('woocommerce_page_subscriptions_for_woo' !== $hook) {

            return;

        }

        wp_enqueue_style('ppsfwoo-styles', $this->plugin_dir_url . "css/style.min.css", [], self::plugin_data('Version'));

        wp_enqueue_script('ppsfwoo-scripts', $this->plugin_dir_url . "js/main.min.js", ['jquery'], self::plugin_data('Version'), true);

        wp_localize_script('ppsfwoo-scripts', 'ppsfwoo_ajax_var', [
            'settings_url' => admin_url(self::$ppcp_settings_url)
        ]);
    }

    public function register_settings()
    {
        foreach (self::$options as $option => $option_value)
        {
            if('skip_settings_field' === $option_value['type']) continue;
            
            register_setting(self::$options_group, $option);
        }
    }

    public function register_options_page()
    {
        add_submenu_page(
            'woocommerce',
            'Settings',
            'Subscriptions',
            'manage_options',
            'subscriptions_for_woo',
            [$this, 'options_page']
        );
    }

    public function add_custom_user_fields($user)
    {
        self::display_template("edit-user");
    }

    public function options_page()
    {
        include $this->template_dir . "/options-page.php";
    }

    public static function get_plan_id_by_product_id($product_id)
    {
        return $product_id ? get_post_meta($product_id, 'ppsfwoo_plan_id', true): "";
    }

    public function get_plan_frequency_by_product_id($product_id)
    {
        $plan_id = get_post_meta($product_id, 'ppsfwoo_plan_id', true);

        return $product_id && isset($this->ppsfwoo_plans[$plan_id]['frequency']) ? $this->ppsfwoo_plans[$plan_id]['frequency']: "";
    }

    public function log_paypal_buttons_error()
    {
        $logged_error = false;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $message = isset($_POST['message'], $_POST['method']) && $_POST['method'] === __FUNCTION__ ? sanitize_text_field(wp_unslash($_POST['message'])): false;
        
        if($message) {

            wc_get_logger()->error("PayPal subscription button error: $message", ['source' => self::plugin_data('Name')]);

            $logged_error = true;

        }

        return wp_json_encode(['logged_error' => $logged_error]);
    }

    protected function get_order_id_by_subscription_id($subs_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT `order_id` FROM {$wpdb->prefix}ppsfwoo_subscriber WHERE `id` = %s",
                $subs_id
            )
        );

        return isset($result[0]->order_id) ? $result[0]->order_id: false;
    }

    protected function insert_subscriber()
    {
        global $wpdb;

        $wp_user = !empty($this->user->email) ? get_user_by('email', $this->user->email): false;

        $this->user->user_id = $wp_user->ID ?? false;

        if(!$this->user->user_id) {

            $this->user->user_id = $this->user->create_wp_user();

            $this->user->create_woocommerce_customer();

        }

        $order_id = $this->get_order_id_by_subscription_id($this->user->subscription_id);

        if(false === $order_id) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('SET time_zone = "+00:00"');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}ppsfwoo_subscriber (
                        `id`,
                        `wp_customer_id`,
                        `paypal_plan_id`,
                        `event_type`
                    )
                    VALUES (%s,%d,%s,%s)",
                    [
                        $this->user->subscription_id,
                        $this->user->user_id,
                        $this->user->plan_id,
                        $this->event_type
                    ]
                )
            );

        } else {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('SET time_zone = "+00:00"');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ppsfwoo_subscriber SET
                        `paypal_plan_id` = %s,
                        `event_type` = %s,
                        `canceled_date` = '0000-00-00 00:00:00'
                    WHERE `id` = %s;",
                    [
                        $this->user->plan_id,
                        $this->event_type,
                        $this->user->subscription_id
                    ]
                )
            );

            $this->update_download_permissions($order_id, 'grant');
        }

        $errors = !empty($wpdb->last_error) ? $wpdb->last_error: false;

        return [
            'errors' => $errors,
            'action' => false === $order_id ? 'insert': 'update'
        ];
    }

    public function cancel_subscriber($user, $event_type)
    {
        global $wpdb;

        if($order_id = $this->get_order_id_by_subscription_id($user->subscription_id)) {
        
            $this->update_download_permissions($order_id, 'revoke');

        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query('SET time_zone = "+00:00"');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ppsfwoo_subscriber SET `event_type` = %s WHERE `id` = %s;",
                $event_type,
                $user->subscription_id
            )
        );

        return [
            'errors' => $wpdb->last_error
        ];
    }

    protected function get_download_count($download_id, $order_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT `download_count` FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
                WHERE `download_id` = %s
                AND `order_id` = %d;",
                $download_id,
                $order_id
            )
        );

        return isset($results[0]->download_count) ? $results[0]->download_count: 0;
    }

    public function update_download_permissions($order_id, $action = "grant") {

        global $wpdb;

        if (class_exists('\WC_Product')) {

            $order = wc_get_order($order_id);

            if(!$order) {

                return;

            }

            foreach ($order->get_items() as $item)
            {
                $product = $item->get_product(); 

                if ($product && $product->exists() && $product->is_downloadable()) {

                    $default_download_limit = get_post_meta($product->get_id(), '_download_limit', true);
                    
                    $downloads = $product->get_downloads();

                    foreach (array_keys($downloads) as $download_id)
                    {
                        $download_count = $this->get_download_count($download_id, $order_id);

                        if($action === 'grant' && $default_download_limit === "-1") {

                            $downloads_remaining = "";

                        } else if($action === 'revoke') {

                            $downloads_remaining = "0";

                        } else {

                            $downloads_remaining = (int) $default_download_limit - (int) $download_count;

                        }

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$wpdb->prefix}woocommerce_downloadable_product_permissions
                                SET `downloads_remaining` = %s
                                WHERE `download_id` = %s
                                AND `order_id` = %d;",
                                (string) $downloads_remaining,
                                $download_id,
                                $order_id
                            )
                        );              
                    } 
                } 
            }
        }
    }

    protected function get_product_id_by_plan_id()
    {
        $query = new \WP_Query ([
            'post_type'      => 'product',
            'posts_per_page' => 1, 
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'     => 'ppsfwoo_plan_id',
                    'value'   => $this->user->plan_id,
                    'compare' => '='
                ],
            ],
        ]);

        $products = $query->get_posts();

        return $products ? $products[0]->ID: 0;
    }

    protected function insert_order()
    {   
        $order = wc_create_order();

        $order->set_customer_id($this->user->user_id);

        $order->add_product(wc_get_product($this->get_product_id_by_plan_id()));

        $address = [
            'first_name' => $this->user->first_name,
            'last_name'  => $this->user->last_name,
            'company'    => '',
            'email'      => $this->user->email,
            'phone'      => '',
            'address_1'  => $this->user->address_line_1,
            'address_2'  => $this->user->address_line_2,
            'city'       => $this->user->city,
            'state'      => $this->user->state,
            'postcode'   => $this->user->postal_code,
            'country'    => $this->user->country_code
        ];

        $order->set_address($address, 'billing');

        $order->set_address($address, 'shipping');

        $order->set_payment_method('paypal');

        $order->set_payment_method_title('Online');

        $order->calculate_shipping();
        
        $order->calculate_totals();
        
        $order->set_status('wc-completed', 'Order created programmatically.');

        $order->save();

        return $order->get_id();
    }

    public function subscribe($user)
    {
        global $wpdb;

        $this->user = $user;

        $this->event_type = Webhook::ACTIVATED;

        $response = $this->insert_subscriber();

        if(false === $response['errors'] && 'insert' === $response['action']) {

            $order_id = $this->insert_order();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('SET time_zone = "+00:00"');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ppsfwoo_subscriber SET
                        `order_id` = %d,
                        `canceled_date` = '0000-00-00 00:00:00'
                        WHERE `id` = %s;",
                    $order_id,
                    $this->user->subscription_id
                )
            );
        }

        return $this->user->subscription_id ?? false;
    }
}