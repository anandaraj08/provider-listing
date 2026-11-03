<?php
/**
 * Plugin Name: Provider Listing
 * Description: Manage providers, search by category and area, reveal phone numbers and track unique leads.
 * Version: 1.1.0
 * Author: Anand
 */
if (!defined('ABSPATH')) {
    exit;
}
/* =========================================
   1. DATABASE TABLES
========================================= */
function pl_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $providers_table = $wpdb->prefix . 'providers';
    $leads_table = $wpdb->prefix . 'provider_leads';
    $sql1 = "CREATE TABLE $providers_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        category VARCHAR(100) NOT NULL,
        area VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset;";
    $sql2 = "CREATE TABLE $leads_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_id BIGINT UNSIGNED NOT NULL,
        visitor_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY provider_visitor (provider_id, visitor_hash),
        KEY provider_id (provider_id)
    ) $charset;";
    dbDelta($sql1);
    dbDelta($sql2);
}
register_activation_hook(__FILE__, 'pl_create_tables');
/* =========================================
   2. DATABASE UPGRADE
========================================= */
function pl_check_database_version() {
    if (get_option('pl_database_version') !== '1.1.0') {
        pl_create_tables();
        update_option('pl_database_version', '1.1.0');
    }
}
add_action('plugins_loaded', 'pl_check_database_version');
/* =========================================
   3. ADMIN MENU
========================================= */
function pl_admin_menu() {
    add_menu_page(
        'Provider Management',
        'Providers',
        'manage_options',
        'provider-listing',
        'pl_admin_page',
        'dashicons-businessperson',
        25
    );

    add_submenu_page(
        'provider-listing',
        'Provider Leads',
        'Leads',
        'manage_options',
        'provider-leads',
        'pl_admin_leads_page'
    );
}
add_action('admin_menu', 'pl_admin_menu');

/* =========================================
   4. ADD PROVIDER ADMIN PAGE
========================================= */

function pl_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'providers';
    if (
        isset($_POST['pl_add_provider']) &&
        check_admin_referer('pl_add_provider_action', 'pl_nonce')
    ) {
        $name = sanitize_text_field(
            wp_unslash($_POST['name'] ?? '')
        );
        $category = sanitize_text_field(
            wp_unslash($_POST['category'] ?? '')
        );
        $area = sanitize_text_field(
            wp_unslash($_POST['area'] ?? '')
        );
        $phone = sanitize_text_field(
            wp_unslash($_POST['phone'] ?? '')
        );
        if (
            $name !== '' &&
            $category !== '' &&
            $area !== '' &&
            preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)
        ) {
            $inserted = $wpdb->insert(
                $table,
                array(
                    'name' => $name,
                    'category' => $category,
                    'area' => $area,
                    'phone' => $phone
                ),
                array('%s', '%s', '%s', '%s')
            );
            if ($inserted !== false) {

                echo '<div class="notice notice-success"><p>Provider added successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Database error: Could not add provider.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Please enter valid provider details.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Provider Management</h1>
        <h2>Add New Provider</h2>
        <form method="post">
            <?php wp_nonce_field('pl_add_provider_action', 'pl_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Name</th>
                    <td>
                        <input type="text" name="name" required class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Category</th>
                    <td>
                        <input type="text" name="category" required class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Area</th>
                    <td>
                        <input type="text" name="area" required class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Phone</th>
                    <td>
                        <input type="tel" name="phone" required class="regular-text">
                    </td>
                </tr>

            </table>
            <?php
            submit_button(
                'Add Provider',
                'primary',
                'pl_add_provider' );
            ?>

        </form>
        <hr>
        <h2>Provider List</h2>
        <?php
        $results = $wpdb->get_results(
            "SELECT id, name, category, area
             FROM $table
             ORDER BY id DESC"
        );
        if ($results) :
        ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Area</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $provider) : ?>
                        <tr>
                            <td><?php echo esc_html($provider->id); ?></td>
                            <td><?php echo esc_html($provider->name); ?></td>
                            <td><?php echo esc_html($provider->category); ?></td>
                            <td><?php echo esc_html($provider->area); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No providers added yet.</p>
        <?php endif; ?>
    </div>
    <?php
}

/* =========================================
   5. PROVIDER SEARCH SHORTCODE
========================================= */
function pl_provider_search_shortcode() {
    global $wpdb;
    $table = $wpdb->prefix . 'providers';
    $page_id = get_queried_object_id();
    $category = isset($_GET['provider_category'])
        ? sanitize_text_field(wp_unslash($_GET['provider_category']))
        : '';
    $area = isset($_GET['provider_area'])
        ? sanitize_text_field(wp_unslash($_GET['provider_area']))
        : '';
    $is_search = isset($_GET['provider_search']);
    $providers = array();
    if ($is_search) {
        $sql = "SELECT id, name, category, area
                FROM $table
                WHERE 1=1";
        $values = array();
        if ($category !== '') {
            $sql .= " AND category LIKE %s";
            $values[] = '%' . $wpdb->esc_like($category) . '%';
        }
        if ($area !== '') {
            $sql .= " AND area LIKE %s";
            $values[] = '%' . $wpdb->esc_like($area) . '%';
        }
        $sql .= " ORDER BY id DESC";
        if (!empty($values)) {
            $providers = $wpdb->get_results(
                $wpdb->prepare($sql, $values)
            );
        } else {
            $providers = $wpdb->get_results($sql);
        }
    }
    $nonce = wp_create_nonce('pl_show_phone_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $search_url = $page_id
        ? get_permalink($page_id)
        : home_url('/');
    ob_start();
    ?>
    <div
        class="pl-provider-search"
        data-ajax-url="<?php echo esc_url($ajax_url); ?>"
        data-nonce="<?php echo esc_attr($nonce); ?>"
    >
        <h2>Find a Provider</h2>
        <form
            method="get"
            action="<?php echo esc_url($search_url); ?>"
            class="pl-search-form"
        >
            <div class="pl-form-group">
                <label for="provider_category">Category</label>
                <input
                    type="text"
                    id="provider_category"
                    name="provider_category"
                    value="<?php echo esc_attr($category); ?>"
                    placeholder="Example: Electrician"
                >
            </div>
            <div class="pl-form-group">
                <label for="provider_area">Area</label>
                <input
                    type="text"
                    id="provider_area"
                    name="provider_area"
                    value="<?php echo esc_attr($area); ?>"
                    placeholder="Example: Coimbatore"
                >
            </div>
            <button
                type="submit"
                name="provider_search"
                value="1"
                class="pl-search-button"
            >
                Search Providers
            </button>
        </form>
        <?php if ($is_search) : ?>
            <div class="pl-search-results">
                <h3>Search Results</h3>
                <?php if (!empty($providers)) : ?>
                    <p>
                        <?php echo esc_html(count($providers)); ?>
                        provider(s) found.
                    </p>
                    <?php foreach ($providers as $provider) : ?>
                        <div class="pl-provider-card">

                            <h3><?php echo esc_html($provider->name); ?></h3>

                            <p>
                                <strong>Category:</strong>
                                <?php echo esc_html($provider->category); ?>
                            </p>

                            <p>
                                <strong>Area:</strong>
                                <?php echo esc_html($provider->area); ?>
                            </p>

                            <p class="pl-phone-result">
                                <strong>Phone:</strong>
                                <span>**********</span>
                            </p>

                            <button
                                type="button"
                                class="pl-show-phone"
                                data-id="<?php echo esc_attr($provider->id); ?>"
                            >
                                Show Number
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p class="pl-no-results">
                        No providers found. Try another category or area.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <!-- CSS -->
    <style>
        .pl-provider-search {
            max-width: 750px;
            margin: 30px auto;
            padding: 20px;
            box-sizing: border-box;
        }
        .pl-form-group {
            margin-bottom: 20px;
        }
        .pl-form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .pl-form-group input {
            width: 100%;
            max-width: 450px;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .pl-search-button,
        .pl-show-phone {
            padding: 12px 22px;
            background: #3858e9;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }

        .pl-search-button:hover,
        .pl-show-phone:hover {
            background: #243fc0;
            color: #fff;
        }

        .pl-search-results {
            margin-top: 30px;
        }

        .pl-provider-card {
            max-width: 500px;
            padding: 22px;
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .pl-provider-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }

        .pl-provider-card p {
            margin: 10px 0;
        }

        .pl-phone-result {
            margin-top: 18px !important;
        }

        .pl-show-phone:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .pl-no-results {
            padding: 15px;
            background: #fff3cd;
            border-radius: 6px;
        }

        @media (max-width: 600px) {

            .pl-provider-search {
                padding: 12px;
            }

            .pl-provider-card {
                width: 100%;
                box-sizing: border-box;
            }

            .pl-form-group input {
                max-width: 100%;
            }
        }

    </style>
    <!-- JAVASCRIPT -->
    <script>
    document.addEventListener('click', async function(event) {
        const button = event.target.closest('.pl-show-phone');
        if (!button) {
            return;
        }
        if (button.disabled) {
            return;
        }
        const container = button.closest('.pl-provider-search');
        if (!container) {
            return;
        }
        // Prevent multiple clicks.
        button.disabled = true;
        button.textContent = 'Loading...';
        const formData = new FormData();
        formData.append('action', 'pl_reveal_phone');
        formData.append('provider_id', button.dataset.id);
        formData.append('nonce', container.dataset.nonce);

        try {
            const response = await fetch(
                container.dataset.ajaxUrl,
                {
                    method: 'POST',
                    body: formData
                }
            );
            const result = await response.json();
            if (result.success) {
                const phoneElement = button
                    .closest('.pl-provider-card')
                    .querySelector('.pl-phone-result span');

                if (phoneElement) {
                    phoneElement.textContent = result.data.phone;
                }
                button.textContent = 'Number Shown';
            } else {
                button.disabled = false;
                button.textContent = 'Show Number';
                alert(
                    result.data?.message || 'Unable to show number.'
                );
            }
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Show Number';
            alert('Something went wrong. Please try again.');
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode(
    'provider_search',
    'pl_provider_search_shortcode'
);

/* =========================================
   6. AJAX PHONE REVEAL
   7. UNIQUE LEAD TRACKING
========================================= */
function pl_reveal_phone_ajax() {
    check_ajax_referer(
        'pl_show_phone_nonce',
        'nonce'
    );
    global $wpdb;
    $provider_id = isset($_POST['provider_id'])
        ? absint($_POST['provider_id'])
        : 0;
    if (!$provider_id) {
        wp_send_json_error(
            array(
                'message' => 'Invalid provider.'
            ),
            400
        );
    }
    $providers_table = $wpdb->prefix . 'providers';
    $leads_table = $wpdb->prefix . 'provider_leads';
    // GET PROVIDER PHONE
    $provider = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, phone
             FROM $providers_table
             WHERE id = %d",
            $provider_id
        )
    );
    if (!$provider) {
        wp_send_json_error(
            array(
                'message' => 'Provider not found.'
            ),
            404
        );
    }
    // CREATE VISITOR HASH
    $visitor_ip = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : 'unknown';
    $visitor_agent = isset($_SERVER['HTTP_USER_AGENT'])
        ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
        : 'unknown';
    $visitor_hash = hash(
        'sha256',
        $visitor_ip . '|' . $visitor_agent . '|' . wp_salt('auth')
    );
    /*
     * DATABASE LEVEL DUPLICATE PREVENTION.
     *
     * UNIQUE KEY provider_visitor prevents
     * the same visitor from creating multiple
     * leads for the same provider.
     *
     * INSERT IGNORE:
     * 1 = New lead inserted.
     * 0 = Duplicate lead already exists.
     * false = Database error.
     */
    $saved = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO $leads_table
            (provider_id, visitor_hash)
            VALUES (%d, %s)",
            $provider_id,
            $visitor_hash
        )
    );
    if ($saved === false) {
        wp_send_json_error(
            array(
                'message' => 'Could not save lead: ' . $wpdb->last_error
            ),
            500
        );
    }
    // RETURN PHONE NUMBER
    wp_send_json_success(
        array(
            'phone' => $provider->phone,
            'message' => 'Phone number revealed successfully.'
        )
    );
}
/* =========================================
   8. AJAX REGISTRATION
========================================= */
add_action(
    'wp_ajax_pl_reveal_phone',
    'pl_reveal_phone_ajax'
);
add_action(
    'wp_ajax_nopriv_pl_reveal_phone',
    'pl_reveal_phone_ajax'
);
/* =========================================
   9. ADMIN LEAD TRACKING PAGE
========================================= */

function pl_admin_leads_page() {
    // Only administrators can access.
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
    global $wpdb;
    $providers_table = $wpdb->prefix . 'providers';
    $leads_table = $wpdb->prefix . 'provider_leads';

    // Get provider-wise lead information.
    $results = $wpdb->get_results(
        "SELECT
            p.id,
            p.name,
            p.category,
            p.area,
            COUNT(l.id) AS total_leads,
            COUNT(DISTINCT l.visitor_hash) AS unique_visitors,
            MAX(l.created_at) AS last_contact
        FROM $providers_table AS p
        LEFT JOIN $leads_table AS l
            ON p.id = l.provider_id
        GROUP BY
            p.id,
            p.name,
            p.category,
            p.area
        ORDER BY total_leads DESC"
    );
    ?>
    <div class="wrap">
        <h1>Provider Lead Tracking</h1>
        <p>Track customer interest in your providers.</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Provider Name</th>
                    <th>Category</th>
                    <th>Area</th>
                    <th>Total Leads</th>
                    <th>Unique Visitors</th>
                    <th>Last Contact</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $provider) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html($provider->id); ?>
                        </td>
                        <td>
                            <?php echo esc_html($provider->name); ?>
                        </td>
                        <td>
                            <?php echo esc_html($provider->category); ?>
                        </td>
                        <td>
                            <?php echo esc_html($provider->area); ?>
                        </td>
                        <td>
                            <?php echo esc_html($provider->total_leads); ?>
                        </td>
                        <td>
                            <?php echo esc_html($provider->unique_visitors); ?>
                        </td>
                        <td>
                            <?php
                            echo $provider->last_contact
                                ? esc_html($provider->last_contact)
                                : 'No leads yet';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="7">
                        No providers found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}