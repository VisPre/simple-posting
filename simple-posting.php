<?php
/**
 *  Plugin Name: Simple Posting
 *  Description: Send planned Post(ing) to a maximum of 5 Zapier Webhooks. Free Zapier account needed in order to use this plugin.
 *  Version: 1.0
 *  Requires at least: 6.1
 *  Tested up to: 6.3
 *  Requires PHP: 8.0
 *  Author: VisPre e.K.
 *  Author URI: https://stephanie-ruderer.de
 *  License: GPL v2 or later
 *  License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *  Text Domain: simple-posting
 * */
defined('ABSPATH') || exit;

/**
 * Class Simple_Posting
 */
class Simple_Posting {

    public $post_type = 'simple-post';
    public $simple_posting_settings;

    /**
     *  Class constructor
     */
    public function __construct() {
        $this->simple_posting_settings = get_option('simple_posting_settings');
        $this->define_constants();
        $this->set_capabilities();
        if ($this->simple_posting_settings == null || $this->simple_posting_settings == '') {
            $count = 1;
            $default_options = array();
            while ($count < SIMPLE_POSTING_NUMBER) {
                $default_options['zapier_' . $count] = '';
                $count++;
            }
            add_option('simple_posting_settings', $default_options);
        }
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('init', array($this, 'localization_setup'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('init', array($this, 'register_posting_templates'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts_handler'));
        add_action('admin_notices', array($this, 'display_setting_errors'));
        add_action('pre_get_posts', array($this, 'add_posting_templates_to_query'));
        add_action('save_post', array($this, 'save_template_custom_meta'), 1, 2);
        add_action('transition_post_status', array($this, 'publish_via_zapier'), 10, 3);
        add_action('admin_action_duplicate_posting_as_draft', array($this, 'save_duplicate_posting_as_draft'), 10, 2);
        add_filter('page_row_actions', array($this, 'duplicate_posting_menu_link'), 10, 2);
        add_filter('parent_file', array($this, 'highlight_menu'));
        add_filter('use_block_editor_for_post_type', array($this, 'disable_gutenberg'), 10, 2);
        global $pagenow;
        if ($pagenow == 'edit-tags.php') {
            add_filter('submenu_file', array($this, 'highlight_submenu'), 10, 2);
        }
    }

    /**
     * Modifies admin, editor and contributor user role.
     *
     * @since 1.0.0
     * @return void
     */
    public function set_capabilities() {
        $roles = array('editor', 'administrator');
        foreach ($roles as $rolename) {
            $role = get_role($rolename);
            $role->add_cap('use_simple_posting');
        }
    }

    /**
     * Initialize plugin for localization.
     *
     * @since 1.0.0
     * @return void
     */
    public function localization_setup() {
        load_plugin_textdomain('simple-posting', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Define the admin main menu.
     *
     * @since 1.0.0
     * @return void
     */
    public function admin_menu() {
        if (current_user_can('manage_options')) {
            add_menu_page('Simple Posting', 'Simple Posting', 'manage_options', 'simple-posting', array($this, 'setting_page_content'), plugin_dir_url(__FILE__) . 'assets/img/favicon.png', 2);
        } else {
            add_menu_page('Simple Posting', 'Simple Posting', 'use_simple_posting', 'simple-posting', array($this, 'setting_page_content'), plugin_dir_url(__FILE__) . 'assets/img/favicon.png', 2);
        }
        add_submenu_page('simple-posting', esc_html(__('All Postings', 'simple-posting')), esc_html(__('All Postings', 'simple-posting')), 'use_simple_posting', 'edit.php?post_type=simple-post');
        add_submenu_page('simple-posting', esc_html(__('New Posting', 'simple-posting')), esc_html(__('New Posting', 'simple-posting')), 'use_simple_posting', 'post-new.php?post_type=simple-post');
        add_submenu_page('simple-posting', esc_html(__('Categories', 'simple-posting')), esc_html(__('Categories', 'simple-posting')), 'use_simple_posting', 'edit-tags.php?taxonomy=posting-categories&post_type=simple-post');
        add_submenu_page('simple-posting', esc_html(__('Topics', 'simple-posting')), esc_html(__('Topics', 'simple-posting')), 'use_simple_posting', 'edit-tags.php?taxonomy=posting-topics&post_type=simple-post');
    }

    /**
     * Define constants used in the plugin.
     *
     * @since 1.0.0
     * @return void
     */
    private function define_constants() {
        !defined('SIMPLE_POSTING_VERSION') && define('SIMPLE_POSTING_VERSION', '1.0');
        !defined('SIMPLE_POSTING_URI') && define('SIMPLE_POSTING_URI', plugin_dir_url(__FILE__));
        !defined('SIMPLE_POSTING_PLUGIN_DIR') && define('SIMPLE_POSTING_PLUGIN_DIR', plugin_dir_path(__FILE__));
        !defined('SIMPLE_POSTING_SUPPORT') && define('SIMPLE_POSTING_SUPPORT', 'mailto:support@vispre.com');
        !defined('WC_URI') && define('WC_URI', SIMPLE_POSTING_URI);
        !defined('SIMPLE_POSTING_VERSION') && define('SIMPLE_POSTING_VERSION', '1.0');
        !defined('SIMPLE_POSTING_NUMBER') && define('SIMPLE_POSTING_NUMBER', '6');
    }

    /**
     * Register all of the scripts related to the admin area functionality.
     *
     * @since 1.0.0
     * @return void
     */
    public function admin_scripts_handler() {
        wp_enqueue_style('simple-posting', SIMPLE_POSTING_URI . 'assets/css/style.min.css', array(), SIMPLE_POSTING_VERSION, 'all');
    }

    /**
     * Register all of the settings related to the plugin.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_settings() {
        register_setting('simple_posting_settings', 'simple_posting_settings', array($this, 'validate_options'));
        add_settings_section(
                'simple_posting_section', esc_html(__('Plugin settings', 'simple-posting')), array($this, 'simple_posting_section'), 'simple_posting_section'
        );
        $count = 1;
        while ($count < SIMPLE_POSTING_NUMBER) {
            add_settings_field('zapier_' . $count, 'Zapier Webhook ' . $count . ':', array($this, 'check_for_key'), 'simple_posting_section', 'simple_posting_section', $count
            );
            $count++;
        }
    }

    function simple_posting_section() {
        echo '<h3>' . esc_html(__('You need a free ', 'simple-posting')) . '<a href="' . esc_url('https://zapier.com/') . '" target="_blank">Zapier</a>' . esc_html(__(' account in order to use this plugin.', 'simple-posting')) . '</h3>';
    }

    /**
     * Content for module setting defined in register_settings. 
     * 
     * @param int $count just a number to differentiate html fields later
     * @return void
     */
    function check_for_key($count) {
        if (isset($this->simple_posting_settings['key_' . $count . '_is_active'])) {
            echo '<input id="channel_' . $count . '_name" name="simple_posting_settings[channel_' . $count . '_name]" type="text" class="field-control channel-name" value=' . $this->simple_posting_settings['channel_' . $count . '_name'] . '>';
            echo '<input id="zapier_' . $count . '" name="simple_posting_settings[zapier_' . $count . ']" type="text" class="field-control" value=' . $this->key_helper($this->simple_posting_settings['zapier_' . $count], 'decrypt') . '>';
            echo '<input id="key_' . $count . '_is_active" name="simple_posting_settings[key_' . $count . '_is_active]" value="1" type="checkbox" checked>';
        } else {
            echo '<input id="channel_' . $count . '_name" name="simple_posting_settings[channel_' . $count . '_name]" type="text" class="field-control channel-name" value="">';
            echo '<input id="zapier_' . $count . '" name="simple_posting_settings[zapier_' . $count . ']" type="text" class="field-control" value="">';
            echo '<input id="key_' . $count . '_is_active" name="simple_posting_settings[key_' . $count . '_is_active]" value="1" type="checkbox" >';
        }
    }

    /**
     * Define the content shown if someone clicks on the menu item.
     *
     * @since 1.0.0
     * @return void
     */
    public function setting_page_content() {
        ?>
        <div class="wrap">
            <h1><?php echo 'Simple Posting ' . ' <span class="version">' . SIMPLE_POSTING_VERSION . '</span>'; ?></h1>
            <div class='page-wrapper'>
                <div class="row">
                    <div class="column help">
                        <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/img/bubble.svg'; ?>" class="icon"> 
                        <h2><?php echo esc_html(__('Contact', 'simple-posting')); ?></h2>
                        <p> <?php echo esc_html(__('If you got a question, found an error or looking for a feature write us a message.', 'simple-posting')); ?></p>
                        <a target="_blank" class="button button-primary" href="mailto:support@vispre.com"><?php echo esc_html(__('Contact us', 'simple-posting')); ?></a>
                    </div>
                    <div class="column description">
                        <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/img/folder.svg'; ?>" class="icon"> 
                        <h2><?php echo esc_html(__('User Guide', 'simple-posting')); ?></h2>
                        <p> <?php echo esc_html(__('Check out user guide for further information about plugin usage.', 'simple-posting')); ?></p>
                        <a target="_blank" class="button button-primary" href="https://stephanie-ruderer.de/simple-posting/"><?php echo esc_html(__('Go to user guide', 'simple-posting')); ?></a>
                    </div>
                </div>
                <div class="row">
                    <div class="column simple-posting-form-tabs">
                        <form method="post" action="options.php" id="simple_posting_form" class="simple-posting-form">
                            <?php
                            if (current_user_can('manage_options')) {
                                settings_fields('simple_posting_settings');
                                do_settings_sections('simple_posting_section');
                                submit_button();
                            } else {
                                echo esc_html(__('<h2>Unfortunately, you are not authorized to change settings.</h2><h3 style="margin-bottom:20px">Please contact an administrator.</h3>', 'simple-posting'));
                            }
                            ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Register custom post type, custom category and tags.
     * 
     * @since 1.0.0
     * @return void
     */
    public function register_posting_templates() {
        if (!current_user_can('use_simple_posting'))
            return;

        $labels = array(
            'name' => esc_html(__('Categories', 'simple-posting')),
            'singular_name' => esc_html(__('Category', 'simple-posting')),
            'search_items' => esc_html(__('Search Category', 'simple-posting')),
            'all_items' => esc_html(__('All Categories', 'simple-posting')),
            'parent_item' => esc_html(__('Parent Category', 'simple-posting')),
            'parent_item_colon' => esc_html(__('Parent Category', 'simple-posting')),
            'edit_item' => esc_html(__('Edit Category', 'simple-posting')),
            'update_item' => esc_html(__('Update Category', 'simple-posting')),
            'add_new_item' => esc_html(__('New Category', 'simple-posting')),
            'new_item_name' => esc_html(__('New Category Name', 'simple-posting')),
            'menu_name' => esc_html(__('Categories', 'simple-posting')),
        );

        register_taxonomy('posting-categories', array('simple-post'), array(
            'hierarchical' => true,
            'labels' => $labels,
            'query_var' => true,
            'rewrite' => array('slug' => 'posting-categories'),
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'show_in_rest' => true,
            'has_archive' => true,
        ));

        $labels = array(
            'name' => esc_html(__('Topics', 'simple-posting')),
            'singular_name' => esc_html(__('Topic', 'simple-posting')),
            'search_items' => esc_html(__('Search Topics', 'simple-posting')),
            'popular_items' => esc_html(__('Popular Topics', 'simple-posting')),
            'all_items' => esc_html(__('All Topics', 'simple-posting')),
            'parent_item' => null,
            'parent_item_colon' => null,
            'edit_item' => esc_html(__('Edit Topic', 'simple-posting')),
            'update_item' => esc_html(__('Update Topic', 'simple-posting')),
            'add_new_item' => esc_html(__('Add New Topic', 'simple-posting')),
            'new_item_name' => esc_html(__('New Topic Name', 'simple-posting')),
            'separate_items_with_commas' => false,
            'add_or_remove_items' => esc_html(__('Add or remove topics', 'simple-posting')),
            'choose_from_most_used' => esc_html(__('Choose from the most used topics', 'simple-posting')),
            'menu_name' => esc_html(__('Topics', 'simple-posting')),
        );

        register_taxonomy('posting-topics', 'simple-post', array(
            'hierarchical' => false,
            'labels' => $labels,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => false,
            'update_count_callback' => '_update_post_term_count',
            'query_var' => true,
            'rewrite' => array('slug' => 'posting-topics'),
        ));

        $labels = array(
            'name' => esc_html(__('Simple Posting', 'simple-posting')),
            'singular_name' => esc_html(__('Simple Posting', 'simple-posting')),
            'menu_name' => esc_html(__('Simple Posting', 'simple-posting')),
            'parent_item_colon' => esc_html(__('Simple Postings', 'simple-posting')),
            'all_items' => esc_html(__('All Postings', 'simple-posting')),
            'view_item' => esc_html(__('View Posting', 'simple-posting')),
            'add_new_item' => esc_html(__('Add Posting', 'simple-posting')),
            'add_new' => esc_html(__('Add Posting', 'simple-posting')),
            'edit_item' => esc_html(__('Edit Posting', 'simple-posting')),
            'update_item' => esc_html(__('Update Posting', 'simple-posting')),
            'search_items' => esc_html(__('Search Postings', 'simple-posting')),
            'not_found' => esc_html(__('No Postings found.', 'simple-posting')),
            'not_found_in_trash' => esc_html(__('No Posting found.', 'simple-posting')),
        );

        $args = array(
            'label' => esc_html(__('Simple Posting', 'simple-posting')),
            'description' => esc_html(__('Simple Postings', 'simple-posting')),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'thumbnail',),
            'hierarchical' => true,
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => true,
            'show_in_admin_bar' => true,
            'menu_position' => 5,
            'can_export' => true,
            'has_archive' => true,
            'exclude_from_search' => false,
            'publicly_queryable' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'show_in_rest' => true,
            'taxonomies' => array('posting-topics', 'posting-categories'),
            'register_meta_box_cb' => array($this, 'custom_meta_boxes'),
        );

        register_post_type($this->post_type, $args);
    }

    /**
     * Allows to loop through Simple Postings on frontend.
     *
     * @param WP_Query $query
     * @since 1.0.0
     * @return void
     */
    public function add_posting_templates_to_query($query) {
        if (is_home() && $query->is_main_query())
            $query->set('post_type', array('post', $this->post_type));
        return $query;
    }

    /**
     * Register Social Media Channel and Alt-Tag metabox for given posting.
     *
     * @param WP_Post $post
     * @since 1.0.0
     * @return void
     */
    function custom_meta_boxes($post) {
        if (!current_user_can('use_simple_posting'))
            return;
        add_meta_box(
                'simple_posting_alt_tag',
                'Alt-Tag',
                array($this, 'alt_tag_posting_image'),
                $this->post_type,
                'normal',
                'high',
        );
        add_meta_box(
                'simple_posting_channel',
                esc_html(__('Social Media Channels', 'simple-posting')),
                array($this, 'social_media_channels'),
                $this->post_type,
                'normal',
                'high',
        );
    }

    /**
     * Display metabox Social Media Channels for given post.
     *
     * @since 1.0.0
     * @return void
     */
    function social_media_channels() {
        if (!current_user_can('use_simple_posting'))
            return;

        global $post;
        wp_nonce_field(basename(__FILE__), 'social_channels');
        echo '<p>' . __('If you change something here please save post first before you plan it.<br>', 'simple-posting') . '</p>';

        $channels = 0;
        $count = 1;
        while ($count < SIMPLE_POSTING_NUMBER) {
            $key_check = '';
            if ($this->simple_posting_settings['zapier_' . $count] !== '' && $this->simple_posting_settings['key_' . $count . '_is_active']) {
                $channel = get_post_meta($post->ID, 'channel_' . $count, true);
                $channel_name = $this->simple_posting_settings['channel_' . $count . '_name'];
                if ($channel == 1)
                    $key_check = 'checked';
                echo '<label for="channel_' . $count . '">' . $channel_name . '</label><input id="channel_' . $count . '" name="channel_' . $count . '" value="1" type="checkbox" ' . $key_check . ' />';
                $channels++;
            }
            $count++;
        }
        if ($channels === 0)
            echo __('Please activate at least one channel on <a href="' . esc_url(get_admin_url()) . 'admin.php?page=simple-posting">settings</a> page.', 'simple-posting');
    }

    /**
     * Display metabox image alt tag for given post.
     *
     * @since 1.0.0
     * @return void
     */
    function alt_tag_posting_image() {
        if (!current_user_can('use_simple_posting'))
            return;

        global $post;
        wp_nonce_field(basename(__FILE__), 'alt_tag');
        echo '<p>' . esc_html(__('Enter an Alt Tag for your featured image. This tag is different to the field you´ll fill out unter Media.<br>', 'simple-posting')) . '</p>';
        echo '<input id="posting_alt_tag" name="posting_alt_tag" type="text" class="field-control" style="width:100%" value="' . esc_attr(get_post_meta($post->ID, 'posting_alt_tag', true)) . '">';
    }

    /**
     * Save data of Social Media Channel and Alt-Tag metabox.
     *
     * @param int $post_id
     * @param WP_Post $post
     * @since 1.0.0
     * @return void
     */
    function save_template_custom_meta($post_id, $post) {
        if (!current_user_can('use_simple_posting', $post_id))
            return;
        if (!wp_verify_nonce($_POST['social_channels'], basename(__FILE__)))
            return;
        if (!wp_verify_nonce($_POST['alt_tag'], basename(__FILE__)))
            return;

        $count = 1;
        while ($count < SIMPLE_POSTING_NUMBER) {
            $channel = sanitize_text_field($_POST['channel_' . $count]);
            if ($channel !== '')
                $channels['channel_' . $count] = $channel;
            $count++;
        }

        foreach ($channels as $key => $value) {
            if ('revision' === $post->post_type)
                return;
            if (get_post_meta($post_id, $key, false)) {
                update_post_meta($post_id, $key, $value);
            } else {
                add_post_meta($post_id, $key, $value);
            }
            if (!$value)
                delete_post_meta($post_id, $key);
        }

        if (!isset($_POST['posting_alt_tag']))
            return;
        $alt_tag = sanitize_text_field($_POST['posting_alt_tag']);
        if ($alt_tag !== '')
            update_post_meta($post_id, 'posting_alt_tag', $alt_tag);
    }

    /**
     * When post is set to status future it is sent to all active zapier hooks.
     *
     * @param string $new_status
     * @param string $old_status
     * @param WP_Post $post
     * @since 1.0.0
     * @return void
     */
    function publish_via_zapier($new_status, $old_status, $post) {
        if (!current_user_can('use_simple_posting'))
            return;
        if ($new_status !== 'future')
            return;
        if ($post->post_type !== $this->post_type)
            return;
        if ($new_status === $old_status)
            return;
        if ($old_status === 'publish')
            return;

        $zap_data = array();
        $zap_data['post_title'] = $post->post_title;
        $zap_data['post_content'] = str_replace('&nbsp;', '', $post->post_content);
        $zap_data['post_date'] = $post->post_date;
        $zap_data['post_image'] = get_the_post_thumbnail_url($post->ID, 'full');
        $zap_data['alt_tag'] = get_post_meta($post->ID, 'posting_alt_tag', true);

        $count = 1;
        while ($count < SIMPLE_POSTING_NUMBER) {
            if (get_post_meta($post->ID, 'channel_' . $count, true)) {
                $webhook_url = $this->simple_posting_settings['zapier_' . $count];
                if ($webhook_url !== '') {
                    $webhook_url = $this->key_helper($webhook_url, 'decrypt');
                    $this->send_to_zapier($webhook_url, $zap_data);
                }
            }
            $count++;
        }
    }

    /**
     * Sends data to Zapier Webhook URL.
     * No returning message as Zapier will send an email if something is wrong.
     * 
     * @param string $url
     * @param array $zap_data
     * @since 1.0.0
     * @return void
     */
    function send_to_zapier($url, $zap_data) {
        if (!current_user_can('use_simple_posting'))
            return;

        wp_remote_post($url, array(
            'body' => array(
                'method' => 'POST',
                'timeout' => 45,
                'sslverify' => true,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($zap_data),
            )
        ));
    }

    /**
     * Displays all messages registered to 'simple_posting_settings_errors'.
     *
     * @since 1.0.0
     * @return void
     */
    function display_setting_errors() {
        settings_errors('simple_posting_settings_errors');
    }

    /**
     * Validates fields before storing in database.
     * 
     * @param type $fields labels, webhook urls, checkboxes
     * @since 1.0.0
     * @return type
     */
    public function validate_options($fields) {
        if (!current_user_can('use_simple_posting'))
            return;
        $error_count = 0;
        $valid_fields = array();

        $count = 1;
        while ($count < SIMPLE_POSTING_NUMBER) {
            $webhook = trim($fields['zapier_' . $count]);
            $channel_name = trim($fields['channel_' . $count . '_name']);
            $checkbox = trim($fields['key_' . $count . '_is_active']);
            if (isset($webhook) && $webhook !== '') {
                $valid_fields['zapier_' . $count] = $this->key_helper($webhook, 'encrypt');
                if (isset($checkbox) && $checkbox !== '')
                    $valid_fields['key_' . $count . '_is_active'] = 1;
                if (isset($channel_name) && $channel_name !== '') {
                    $valid_fields['channel_' . $count . '_name'] = $channel_name;
                } else {
                    add_settings_error('simple_posting_settings_errors', 'settings_saved_error', __('Please enter a name for Zapier Webhook ' . $count . '.', 'simple-posting'), 'error');
                    $error_count++;
                }
            } else {
                $valid_fields['zapier_' . $count] = '';
            }
            $count++;
        }

        if ($error_count == 0)
            add_settings_error('simple_posting_settings_errors', 'settings_saved_success', __('Settings saved.', 'simple-posting'), 'success');

        return apply_filters('validate_options', $valid_fields, $fields);
    }

    /**
     * Encrypts or decripts string data.
     *
     * @param string $data
     * @param string $direction encrypt, decrypt
     * @since 1.0.0
     * @return string
     */
    function key_helper($data, $direction) {
        if (!current_user_can('use_simple_posting'))
            return;
        $iv = substr(NONCE_SALT, 0, 16);
        if (strlen($iv) !== 16) {
            $iv = 'AtK$C&7@2q6=*fuJ';
        }

        if ('encrypt' === $direction) {
            if (function_exists('openssl_encrypt')) {
                $encryptedData = openssl_encrypt(
                        $data, 'aes-256-cbc', AUTH_SALT, OPENSSL_RAW_DATA, $iv
                );
            }

            if (isset($encryptedData)) {
                if (false === $encryptedData) {
                    return base64_encode($data);
                }

                return base64_encode($encryptedData);
            }

            return base64_encode($data);
        } else {
            $data = base64_decode($data);
            if (function_exists('openssl_encrypt')) {
                $decryptedData = openssl_decrypt(
                        $data, 'aes-256-cbc', AUTH_SALT, OPENSSL_RAW_DATA, $iv
                );
            }

            if (isset($decryptedData)) {
                if (false === $decryptedData)
                    return '';
                return $decryptedData;
            }

            return $data;
        }
    }

    /**
     * Highlights simple posting menu if custom category or topics submenu is active.
     * 
     * @global type $current_screen
     * @param string $parent_file
     * @since 1.0.0
     * @return string
     */
    public function highlight_menu($parent_file) {
        global $current_screen;
        if (in_array($current_screen->id, array('edit-posting-categories', 'edit-posting-topics'))) {
            $parent_file = 'simple-posting';
        }
        return $parent_file;
    }

    /**
     * Highlights according submenu if posting categories or topics are selected.
     * 
     * @global type $current_screen
     * @param string $parent_file
     * @since 1.0.0
     * @return string
     */
    function highlight_submenu($submenu_file) {
        global $current_screen, $pagenow;

        if ($current_screen->post_type == $this->post_type) {
            if ($pagenow == 'post.php') {
                $submenu_file = 'edit.php?post_type=simple-post';
            }
            if ($pagenow == 'post-new.php') {
                $submenu_file = 'post-new.php?post_type=simple-post';
            }
            if ($current_screen->id === 'edit-posting-categories') {
                $submenu_file = 'edit-tags.php?taxonomy=posting-categories&post_type=simple-post';
            }
            if ($current_screen->id === 'edit-posting-topics') {
                $submenu_file = 'edit-tags.php?taxonomy=posting-topics&post_type=simple-post';
            }
        }
        return $submenu_file;
    }

    /**
     * Adds duplicate link to posting list.
     * 
     * @param array $actions
     * @param WP_Post $post
     * @since 1.0.0
     * @return array
     */
    function duplicate_posting_menu_link($actions, $post) {
        if (!current_user_can('use_simple_posting'))
            return;
        if ($post->post_type == $this->post_type) {
            $actions['duplicate'] = '<a href="' . wp_nonce_url('admin.php?action=duplicate_posting_as_draft&post=' . $post->ID, basename(__FILE__), 'duplicate_nonce') . '" title="' . __('Duplicate Posting', 'simple-posting') . '" rel="permalink">' . __('Duplicate', 'simple-posting') . '</a>';
        }
        return $actions;
    }

    /**
     * Duplicates posting.
     * 
     * @since 1.0.0
     * @return void
     */
    function save_duplicate_posting_as_draft() {
        if (!current_user_can('use_simple_posting'))
            return;
        global $wpdb;
        $post_copy = sanitize_text_field($_POST['post']);
        $get_copy = sanitize_text_field($_GET['post']);
        $request_copy = sanitize_text_field($_REQUEST['action']);

        if (!( isset($get_copy) || isset($post_copy) || ( isset($request_copy) && 'save_duplicate_posting_as_draft' == $request_copy ) ))
            return;

        $post_id = (isset($get_copy) ? $get_copy : $post_copy );
        $post = get_post($post_id);
        $current_user = wp_get_current_user();

        if (!isset($post) || $post == null) {
            add_settings_error('simple_posting_settings_errors', 'duplicate_posting_error', __('Posting couldn´t be duplicated.', 'simple-posting'), 'error');
            return;
        }

        $args = array('comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'post_author' => $current_user->ID,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_name' => $post->post_name,
            'post_parent' => $post->post_parent,
            'post_password' => $post->post_password,
            'post_status' => 'draft',
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'to_ping' => $post->to_ping,
            'menu_order' => $post->menu_order
        );
        $new_post_id = wp_insert_post($args);

        $taxonomies = get_object_taxonomies($post->post_type);
        if (!empty($taxonomies) && is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $post_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
                wp_set_object_terms($new_post_id, $post_terms, $taxonomy, false);
            }
        }

        $post_meta_infos = $wpdb->get_results('SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=' . $post_id);
        if (count($post_meta_infos) > 0) {
            $sql_query = 'INSERT INTO ' . $wpdb->postmeta . ' (post_id, meta_key, meta_value) ';
            foreach ($post_meta_infos as $meta_info) {
                $meta_key = $meta_info->meta_key;
                $meta_value = addslashes($meta_info->meta_value);
                $sql_query_sel[] = 'SELECT' . $new_post_id . ', ' . $meta_key . ', ' . $meta_value;
            }
            $sql_query .= implode(' UNION ALL ', $sql_query_sel);
            $wpdb->query($sql_query);
        }
        wp_redirect(admin_url('edit.php?post_type=' . $post->post_type));
    }

    /**
     * Disables Gutenberg editor for this post type as we need a wysiwyg editor.
     * 
     * @param bool $current_status gutenberg yes or no
     * @param string $post_type
     * @since 1.0.0
     * @return bool
     */
    function disable_gutenberg($current_status, $post_type) {
        if ($post_type === $this->post_type)
            return false;
        return $current_status;
    }

    /**
     * 
     * @global object $wpdb
     * @param string $option_name
     * @since 1.0.0
     * @return bool
     */
    function option_exists($option_name) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $option_name));
        if (is_object($row))
            return true;
        return false;
    }

}

new Simple_Posting();