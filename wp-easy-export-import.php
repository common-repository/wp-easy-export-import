<?php
/**
 * Plugin Name: WP Easy Export Import
 * Plugin URI:  https://wp.cafe
 * Description: Makes export and import easier.
 * Author:      Rahul Aryan
 * Version:     1.0.0
 */

// Prevent direct access to file.
defined( 'ABSPATH' ) or exit;

// Set plugin url and dir.
define( 'WPEEI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPEEI_URL', plugin_dir_url( __FILE__ ) );

class WP_Easy_Export_Import {
    /**
     * Singleton instance of this class.
     *
     * @var WP_Easy_Export_Import
     */
    private static $instance = null;

    /**
     * Type of site, Guest or host site.
     *
     * @var false|string
     */
    private $site_type = false;

    /**
     * URL to the admin page.
     *
     * @var mixed
     */
    private $admin_page_url;

    /**
     * Url to admin action page.
     *
     * @var string
     */
    private $switch_url;

    /**
     * Returns singleton instance of this class.
     *
     * @return WP_Easy_Export_Import Object of this class.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->hooks();
        }

        return self::$instance;
    }

    /**
     * Class constructor.
     *
     * @return void
     */
    private function __construct() {

    }

    /**
     * Register all hooks of this plugin.
     *
     * @return void
     */
    private function hooks() {
        $this->site_type      = get_option( 'wp_easy_export_import_site_type', false );
        $this->admin_page_url = admin_url( 'tools.php' ) . '?page=wp_easy_export_import';

        add_action( 'admin_menu', [ $this, 'register_sub_page' ] );
        add_action( 'admin_action_wp_easy_export_import_set_site_type', [ $this, 'set_site_type' ] );
        add_action( 'wp_ajax_wpeei_get_meta_keys', [ $this, 'ajax_get_meta_fields' ] );
        add_action( 'admin_action_wp_easy_export_import_save_host', [ $this, 'action_save_host_settings' ] );
        add_action( 'admin_action_wp_easy_export_import_save_guest', [ $this, 'action_save_guest_settings' ] );
        add_action( 'wp_ajax_nopriv_wp_easy_export_import_webhook', [ $this, 'webhook' ] );
        add_action( 'wp_ajax_wp_easy_export_import_fetch', [ $this, 'get_from_webhook' ] );
    }

    /**
     * Register submenu page.
     *
     * @return void
     */
    public function register_sub_page() {
        $this->switch_url = add_query_arg(
            [
                '__nonce' => wp_create_nonce( 'set_site_type' ),
                'action' => 'wp_easy_export_import_set_site_type',
            ],
            admin_url( 'admin.php' )
        );

        add_submenu_page(
            'tools.php',
            __( 'Easy export/import', 'wp-easy-export-import' ),
            __( 'Easy export/import' ),
            'manage_options',
            'wp_easy_export_import',
            [ $this, 'admin_page' ]
        );
    }

    /**
     * Callback for rendering admin page.
     *
     * @return void
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_attr_e( 'Easy import/export', 'wp-easy-export-import' ); ?></h1>

            <?php if ( empty( $this->site_type ) ) : ?>

                <p><?php esc_attr_e( 'Is this a host or guest site?', 'wp-easy-export-import' ); ?></p>

                <a href="<?php echo esc_url( $this->switch_url . '&type=host' ) ?>" class="button"><?php esc_attr_e( 'This is host site', 'wp-easy-export-import' ); ?></a>
                <a href="<?php echo esc_url( $this->switch_url . '&type=guest' ) ?>" class="button"><?php esc_attr_e( 'This is guest site', 'wp-easy-export-import' ); ?></a>

            <?php elseif ( 'host' === $this->site_type ) : ?>

                <?php $this->host_site_view(); ?>

            <?php elseif ( 'guest' === $this->site_type ) : ?>

                <?php $this->guest_site_view(); ?>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Callback for admin action to set site type.
     *
     * @return void
     */
    public function set_site_type() {
        // Check nonce and privilege.
        if ( ! wp_verify_nonce( $_GET['__nonce'], 'set_site_type' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Sorry, you are not allowed to perform this action.', 'wp-easy-export-import' ) );
        }

        $type = 'host' === $_GET['type'] ? 'host' : 'guest';

        // Delete host option when guest and vice versa.
        delete_option( 'wp_easy_export_import_' . ( 'host' === $type ? 'guest' : 'host' ) );

        // Update option.
        update_option( 'wp_easy_export_import_site_type', $type );

        // All done now redirect back to same page.
        wp_redirect( $this->admin_page_url );
        exit;
    }

    /**
     * Get all the meta keys related to a post type.
     *
     * @param mixed $post_type
     * @return mixed
     */
    private function get_meta_keys_to_export( $post_type ) {
        global $wpdb;

        // If not valid post type return empty.
        if ( ! post_type_exists( $post_type ) ) {
            return [];
        }

        $keys = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} JOIN {$wpdb->posts} ON post_id = ID WHERE post_type = %s GROUP BY meta_key", $post_type ) );

        return $keys;
    }

    /**
     * Render host site settings.
     *
     * @return void
     */
    public function host_site_view() {
        $options             = get_option( 'wp_easy_export_import_host', [ 'secret' => '', 'post_type' => ''] );
        $post_types          = get_post_types( [], 'objects' );
        ?>
            <?php echo esc_attr_e( 'Is this not a guest site? change it: ', 'wp-easy-export-import'); ?> <a href="<?php echo esc_url( $this->switch_url . '&type=guest' ) ?>"><?php esc_attr_e( 'Switch as guest site', 'wp-easy-export-import' ); ?></a>
            <br>

            <h2><?php esc_attr_e( 'Host site settings', 'wp-easy-export-import' ); ?></h2>

            <p><?php esc_attr_e( 'After adding secret key, selecting post type and meta fields go to guest site and install same plugin and set "Site Type" as guest site and add domain and secret key', 'wp-easy-export-import' ); ?></p>

            <form id="host_site_settings" method="POST" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">

                <label for="wpeei_secret"><?php esc_attr_e( 'Secret Key', 'wp-easy-export-import' ); ?></label>
                <br>

                <div>
                    <input
                        id="wpeei_secret"
                        type="text"
                        name="wpeei[secret]"
                        placeholder="<?php esc_attr_e( 'Security key' ); ?>"
                        required
                        value="<?php echo ! empty( $options['secret'] ) ? esc_attr( $options['secret'] ) : ''; ?>" />
                </div>

                <br>

                <label for="wpeei_post_type"><?php esc_attr_e( 'Post Type', 'wp-easy-export-import' ); ?></label>
                <br>
                <select name="wpeei[post_type]" id="wpeei_post_type" required>
                    <option><?php esc_attr_e( 'Select a post type', 'wp-easy-export-import' ); ?></option>

                    <?php foreach ( $post_types as $cpt ) : ?>
                        <option <?php selected( $options['post_type'], $cpt->name ); ?> value="<?php echo esc_attr( $cpt->name ); ?>"><?php esc_attr_e( $cpt->label ); ?></option>
                    <?php endforeach; ?>

                </select>

                <br>
                <br>
                <div id="meta-keys">
                </div>

                <?php wp_nonce_field( 'wp_easy_export_import' ); ?>
                <input type="hidden" name="action" value="wp_easy_export_import_save_host" />

                <br>
                <br>

                <input class="button button-primary" name="submit" type="submit" value="<?php esc_attr_e( 'Save', 'wp-easy-export-import' ); ?>" />
            </form>

            <script type="text/javascript">
                (function($){

                    $(document).ready(function(){
                        var existingMeta = '<?php echo json_encode( $options['meta'] ); ?>'

                        function addMetaFields(meta, selected){
                            selected = selected||[]
                            $('#meta-keys').html('')
                            $.each(meta, function(key, value){
                                var html = '<div><label><input type="checkbox" name="wpeei[meta][]" ' + ( selected.includes(value) ? 'checked="checked"' : '') + ' value="'+value+'"/> '+value+'</label></div>'

                                $('#meta-keys').append(html)
                            })
                        }

                        if ( existingMeta ){
                            var existingMeta = JSON.parse( existingMeta )
                            addMetaFields(existingMeta, existingMeta)
                        }

                        $('#wpeei_post_type').on('change', function(){
                            $.ajax({
                                method: 'POST',
                                url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                                dataType: 'json',
                                data: {
                                    __nonce: '<?php echo wp_create_nonce( 'get_meta_fields' ); ?>',
                                    post_type: $(this).val(),
                                    action: 'wpeei_get_meta_keys'
                                },
                                success: function(data){
                                    if ( !data.success ) {
                                        return;
                                    }
                                    addMetaFields(data.data.meta)
                                }
                            })
                        })
                    })
                })(jQuery)
            </script>
        <?php
    }

    /**
     * Render meta keys selection fields.
     *
     * @param string $post_type Post type.
     * @return void
     */
    private function meta_fields_view( $post_type ) {
        $keys = $this->get_meta_keys_to_export( $post_type );

        ?>
            <?php if ( ! empty( $keys ) ) : ?>
                <h4><?php esc_attr_e( 'Select Meta keys to be exported', 'wp-easy-export-import' ); ?></h4>

                <?php foreach ( $keys as $key ) : ?>
                    <div>
                        <label><input type="checkbox" name="wpeei_meta" value="<?php echo esc_attr( $key ); ?>"/> <?php echo esc_attr( $key ); ?></label>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php
    }

    /**
     * Ajax callback to return all meta keys associated with a post type.
     *
     * @return void
     */
    public function ajax_get_meta_fields() {
        if ( ! wp_verify_nonce( $_POST['__nonce' ], 'get_meta_fields' ) ) {
            wp_send_json_error();
        }

        $post_type = sanitize_text_field( wp_unslash( $_POST['post_type'] ) );

        // Return all meta keys.
        wp_send_json_success( [ 'meta' => $this->get_meta_keys_to_export( $post_type ) ] );
    }

    /**
     * Process save action for host settings.
     *
     * @return exit
     */
    public function action_save_host_settings() {
        // Check nonce and privilege.
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_easy_export_import' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Sorry, you are not allowed to perform this action.', 'wp-easy-export-import' ) );
        }

        $data = wp_kses_post_deep( $_POST['wpeei'] );

        if ( empty( $data['secret'] ) ) {
            $error = [
                'secret' => __( 'Secret key is required.', 'wp-easy-export-import' )
            ];

            wp_redirect( add_query_arg( $error, $this->admin_page_url ) );
            exit;
        }

        // Delete host site settings.
        delete_option( 'wp_easy_export_import_guest' );

        // Update option.
        update_option( 'wp_easy_export_import_host', $data );

        // All done now redirect back to same page.
        wp_redirect( $this->admin_page_url );
        exit;
    }

    /**
     * Guest site settings.
     *
     * @return exit
     */
    public function action_save_guest_settings() {
        // Check nonce and privilege.
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_easy_export_import' ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Sorry, you are not allowed to perform this action.', 'wp-easy-export-import' ) );
        }

        $data = wp_kses_post_deep( $_POST['wpeei'] );

        if ( empty( $data['secret'] ) ) {
            $error = [
                'secret' => __( 'Secret key is required.', 'wp-easy-export-import' )
            ];

            wp_redirect( add_query_arg( $error, $this->admin_page_url ) );
            exit;
        }

        // Delete host site settings.
        delete_option( 'wp_easy_export_import_host' );

        // Update option.
        update_option( 'wp_easy_export_import_guest', $data );

        // All done now redirect back to same page.
        wp_redirect( $this->admin_page_url );
        exit;
    }

    /**
     * Renders guest site settings view.
     *
     * @return void
     */
    private function guest_site_view() {
        $options = get_option(
            'wp_easy_export_import_guest', [
                'secret' => '',
                'post_type' => ''
            ]
        );
        ?>
            <?php echo esc_attr_e( 'Is this not a host site? change it: ', 'wp-easy-export-import'); ?> <a href="<?php echo esc_url( $this->switch_url . '&type=host' ) ?>"><?php esc_attr_e( 'Switch as host site', 'wp-easy-export-import' ); ?></a>
            <br>

            <h2><?php esc_attr_e( 'Guest site settings', 'wp-easy-export-import' ); ?></h2>

            <p><?php esc_attr_e( 'Add url of host site and secret to start import.', 'wp-easy-export-import' ); ?></p>

            <form id="guest_site_settings" method="POST" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">

                <label for="wpeei_secret"><?php esc_attr_e( 'Host site URL', 'wp-easy-export-import' ); ?></label>
                <br>

                <div>
                    <input
                        id="wpeei_url"
                        type="url"
                        name="wpeei[url]"
                        placeholder="<?php esc_attr_e( 'https://urlOfAnotherSite.com' ); ?>"
                        required
                        value="<?php echo ! empty( $options['url'] ) ? esc_attr( $options['url'] ) : ''; ?>" />
                </div>

                <br>

                <label for="wpeei_secret"><?php esc_attr_e( 'Secret Key', 'wp-easy-export-import' ); ?></label>
                <br>

                <div>
                    <input
                        id="wpeei_secret"
                        type="text"
                        name="wpeei[secret]"
                        placeholder="<?php esc_attr_e( 'Security key' ); ?>"
                        required
                        value="<?php echo ! empty( $options['secret'] ) ? esc_attr( $options['secret'] ) : ''; ?>" />
                </div>

                <?php wp_nonce_field( 'wp_easy_export_import' ); ?>
                <input type="hidden" name="action" value="wp_easy_export_import_save_guest" />

                <br>
                <br>

                <input class="button button-primary" name="submit" type="submit" value="<?php esc_attr_e( 'Save', 'wp-easy-export-import' ); ?>" />
            </form>

            <div id="import-wrapper">
                <?php $this->import_view(); ?>
            </div>
        <?php
    }

    /**
     * Process webhook request from guest site.
     *
     * @return void
     */
    public function webhook() {
        $options = get_option( 'wp_easy_export_import_host' );

        // Check if host settings configured.
        if ( empty( $options ) ) {
            wp_send_json_error( new WP_Error( '011', __( 'Host setting not configured in this site, go to Wp-Admin->Tools->Easy export/import', 'wp-easy-export-import' ) ) );
        }

        // Check for secret.
        if ( empty( $options['secret'] ) || empty( $_REQUEST['secret'] ) || $options['secret'] !== $_REQUEST['secret'] ) {
            wp_send_json_error( new WP_Error( '012', __( 'Secret key does not match.', 'wp-easy-export-import' ) ) );
        }

        // Check if post type is selected.
        if ( empty( $options['post_type'] ) ) {
            wp_send_json_error( new WP_Error( '013', __( 'No post type configured in host site.', 'wp-easy-export-import' ) ) );
        }

        $paged = empty( $_REQUEST['paged'] ) ? 1 : max( 1, (int) $_REQUEST['paged'] );

        $posts = new WP_Query( [
            'post_type'      => $options['post_type'],
            'post_status'    => [ 'pending', 'draft', 'future', 'publish' ],
            'paged'          => $paged,
            'posts_per_page' => 1,
        ]);

        $post_arr = [];

        if ( $posts->have_posts() ) {
            while( $posts->have_posts() ) {
                $posts->the_post();
                $p = get_post( null, 'OBJECT', 'display' );
                $terms = [];

                $terms[] = wp_get_post_terms( $p->ID );
                $terms[] = wp_get_post_terms( $p->ID, 'category' );

                $post_arr[] = [
                    'post_data' => $p,
                    'meta'      => get_post_meta( $p->ID ),
                    'thumb'     => get_the_post_thumbnail_url( null, 'full' ),
                    'terms'     => $terms,
                ];
            }
        }

        // Send results.
        wp_send_json_success( [
            'posts'    => $post_arr,
            'found'    => count( $post_arr ),
            'total'    => $posts->found_posts,
            'has_more' => $posts->max_num_pages > $paged,
        ] );
    }

    /**
     * Log message to a file.
     *
     * @param mixed $msg
     * @return void
     */
    private function log( $msg ) {
        $file = WPEEI_DIR . '/logs/latest.txt';
        file_put_contents( $file, $msg.PHP_EOL , FILE_APPEND );
    }

    /**
     * Ajax callback for importing posts from host site.
     *
     * @return void
     */
    public function get_from_webhook() {
        $options = get_option( 'wp_easy_export_import_guest' );

        if ( ! wp_verify_nonce( $_POST['__nonce'], 'wpeei_import' ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( new WP_Error( '001', 'Do not have enough permission' ) );
        }

        // Check log file.
        if ( file_exists( WPEEI_DIR . '/logs/latest.txt' ) ) {
            unlink( WPEEI_DIR . '/logs/latest.txt' );
            file_put_contents( WPEEI_DIR . '/logs/latest.txt', '' );
        }

        // Check if url and secret are set.
        if ( empty( $options ) || empty( $options['url'] ) || empty( $options['secret'] ) ) {
            wp_send_json_error( new WP_Error( '002', 'No options for guest site is configured.' ) );
        }

        $host_url = add_query_arg(
            [
                'secret' => $options['secret'],
                'action' => 'wp_easy_export_import_webhook',
                'paged'  => $_POST['paged'],
            ],
            rtrim( $options['url'], '/' ) . '/wp-admin/admin-ajax.php'
        );

        $this->log( sprintf( __( 'Sending request to host site: %s' ), $options['url'] ) );

        $res = wp_remote_post( $host_url );

        if ( is_wp_error( $res ) ) {
            $this->log( sprintf( __( 'ERROR: %s' ), $res->get_error_message() ) );
            wp_send_json_error( new WP_Error( '003', $res->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $res ) );

        if ( $body && $body->success ) {
            $data = $body->data;

            //$this->log( sprintf( __( 'RECEIVED: %d posts' ), $data->found ) );

            if ( $data->found > 0 ) {
                foreach ( $data->posts as $post ) {
                    $this->log( sprintf( __( 'PROCESSING: [%d] - %s' ), $post->post_data->ID, $post->post_data->post_title ) );

                    $post_arr = $this->process_post_after_fetching( $post );

                    if ( ! empty( $post_arr ) ) {
                        $this->update_insert_post( $post_arr );
                        $this->log( sprintf( __( 'SAVED: [%d] - %s' ), $post->post_data->ID, $post->post_data->post_title ) );
                    } else {
                        $this->log( sprintf( __( 'SKIPPED: [%d] - overrided by filter.' ), $post->post_data->ID, $post->post_data->post_title ) );
                    }
                }
            }
        }

        wp_send_json_success( $body->data );

        exit;
    }


    /**
     * Import screen view.
     *
     * @return void
     */
    private function import_view() {
        $options = get_option( 'wp_easy_export_import_guest', false );

        if ( ! $options ) {
            return;
        }
        ?>
            <div id="import" style="height: 300px; border: solid 1px #000; padding: 10px; overflow-y: auto; background: #222; color: #fff; margin-top: 30px;font-size: 11px">
                <?php esc_attr_e( 'Logs will appear here', 'wp-easy-export-import' ); ?>
            </div>
            <br><br>
            <button id="start-import" class="button"><?php esc_attr_e( 'Start import', 'wp-easy-export-import' ); ?></button>

            <script type="text/javascript">
                (function($){
                    var latLogLine = 0;
                    var paged = 1;
                    var max = 10;
                    var log = function(msg){
                        $('#import').append('<pre>' + msg + '</pre>')
                        $('#import').scrollTop($('#import')[0].scrollHeight);
                    }
                    var getLog = function(cb){
                        return $.get("<?php echo esc_url( WPEEI_URL ); ?>/logs/latest.txt").done(function(progress){
                            log(progress)
                            cb()
                        })
                    }
                    var sendReq = function(){
                        $.ajax({
                            // beforeSend: function(){
                            //     interval = setInterval(function(){
                            //         getLog()
                            //     },1000);
                            // },
                            method: 'POST',
                            url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                            data: {
                                __nonce: '<?php echo wp_create_nonce( 'wpeei_import' ); ?>',
                                action: 'wp_easy_export_import_fetch',
                                paged: paged
                            },
                            success: function(res){
                                paged++;
                                //clearInterval(interval);
                                getLog(function(){
                                    console.log(res)
                                    //if(max === paged) return
                                    if (res.success && res.data.found > 0 && res.data.has_more) {
                                        sendReq();
                                    }
                                })
                            }
                        })
                    }
                    $(document).ready(function(){
                        $('#start-import').on('click', function(e){
                            e.preventDefault();
                            log( '<?php esc_attr_e( 'Import started', 'wp-easy-export-import' ); ?>' )

                            sendReq()
                        })
                    })
                })(jQuery);
            </script>
        <?php
    }

    /**
     * Process post data after fetching from host site but prior to
     * inserting to guest site. Returning null|false will result in
     * not processing current post.
     *
     * @param mixed $post_data
     * @return object
     */
    private function process_post_after_fetching( $post_data ) {
        return apply_filters( 'wpeei/process_post_after_fetching', $post_data );
    }

    /**
     * Insert or update a post.
     *
     * @param mixed $post_data
     * @return true
     */
    private function update_insert_post( $post_data ) {
        $post_arr = (array) $post_data->post_data;

        // Get existing post by name.
        $existing = get_page_by_path( $post_data->post_data->post_name, OBJECT, $post_data->post_data->post_type );

        // If post found by name then use found ID.
        if ( $existing ) {
            $post_arr['ID'] = $existing->ID;
            // Insert post.
            $ret = wp_update_post( $post_arr, true );
        } else {
            unset( $post_arr['ID'] );
            // Insert post.
            $ret = wp_insert_post( $post_arr, true );
        }

        if ( is_wp_error( $ret ) ) {
            $this->log( sprintf( 'FAILED: %d insert failed, ERROR: %s', $post_data->post_data->ID, $ret->get_error_message() ) );

            return $ret;
        }

        // Update post meta.
        if ( ! empty( $post_data->meta ) ) {
            foreach ( $post_data->meta as $meta_key => $meta_value ) {
                // If is array then add for individual value.
                if ( is_array( $meta_value ) ) {
                    foreach( $meta_value as $sub ) {
                        add_post_meta( $ret, $meta_key, $sub );
                    }
                } else {
                    update_post_meta( $ret, $meta_key, $meta_value );
                }
            }
        }

        // Check images.
        $images = $this->get_images_in_string( $post_arr['post_content'] );

        $replaced_images = [];
        if ( ! empty( $images ) ) {
            $this->log( sprintf( 'IMAGES: found %d images in content', count( $images ) ) );

            foreach ( $images as $image ) {
                $id = $this->download_image( $ret, $image );

                if ( is_wp_error( $id ) ) {
                    $this->log( sprintf( 'ERROR: Failed to import attachment: %s, Error: %s', $image, $id->get_error_message() ) );
                    continue;
                }

                $this->log( sprintf( 'SUCCESS: image imported and attached, id: %d', $id ) );

                $replaced_images[ $image ] = wp_get_attachment_url( $id );
            }
        }

        $replaced_content = $post_arr['post_content'];

        // Replace url.
        $replaced_content = $this->replace_all_url( $replaced_content );

        if ( ! empty( $replaced_images ) ) {
            foreach ( $replaced_images as $old => $new ) {
                $replaced_content = str_replace( $old, $new, $replaced_content );
            }

            $this->log( sprintf( 'SUCCESS: %d images replaced', count( $replaced_images ) ) );
        }

        // Set post thumbnail.
        if ( ! empty( $post_data->thumb ) ) {
            $id = $this->download_image( $ret, $post_data->thumb );

            if ( is_wp_error( $id ) ) {
                $this->log( sprintf( 'ERROR: Failed to import featured image: %s, Error: %s', $post_data->thumb, $id->get_error_message() ) );
            } else {
                set_post_thumbnail( $ret, $id );
                $this->log( sprintf( 'SUCCESS: Featured image created and set, ID: %d', $id ) );
            }
        }

        $this->process_terms( $ret, $post_data->terms );

        wp_update_post( [ 'ID' => $ret, 'post_content' => $replaced_content ] );

        return $ret;
    }

    /**
     * Get all images from a string.
     *
     * @param string $string
     * @return void
     */
    public function get_images_in_string( $string ) {
        preg_match_all( '/<img.*?src=["\']+(.*?)["\']+/', $string, $urls );

        if ( empty( $urls ) || empty( $urls[1] )) {
            return false;
        }

        return $urls[1];
    }

    /**
     * Download a image form URL.
     *
     * @param mixed $post_id
     * @param mixed $image_url
     * @return mixed
     */
    private function download_image( $post_id, $image_url ) {
        $tmp = download_url( $image_url );

        $file_array = array(
            'name'     => basename( $image_url ),
            'tmp_name' => $tmp
        );

        /**
         * Check for download errors
         */
        if ( is_wp_error( $tmp ) ) {
            return new WP_Error( 'Failed to download image' );
        }

        $ret = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $ret ) ) {
            @unlink( $file_array[ 'tmp_name' ] );
        }

        return $ret;
    }

    /**
     * Process post terms.
     *
     * @param mixed $post_id
     * @param mixed $taxos
     * @return void
     */
    public function process_terms( $post_id, $taxos ) {
        if ( ! empty( $taxos ) ) {
            foreach ( $taxos as $terms ) {
                if ( ! empty( $terms ) ) {
                    foreach ( $terms as $term ) {
                        if ( ! term_exists( $term->slug, $term->taxonomy, $term->parent ) ) {
                            $ret = wp_insert_term(
                                $term->name,   // the term
                                $term->taxonomy, // the taxonomy
                                array(
                                    'description' => $term->description,
                                    'slug'        => $term->slug,
                                    'parent'      => $term->parent,
                                )
                            );

                            $this->log( sprintf( 'SUCCESS: Added term: %s', $term->name ) );

                            $term_id = $ret['term_id'];
                        } else {
                            $ret = get_term_by( 'slug', $term->slug, $term->taxonomy );
                            $term_id = $ret->term_id;
                        }

                        wp_set_post_terms( $post_id, [ $term_id ], $term->taxonomy );

                        $this->log( sprintf( 'SUCCESS: Attached term %s to post %d', $term->name, $post_id ) );
                    }
                }
            }
        }
    }

    /**
     * replace all links of host site with guest site.
     *
     * @param mixed $string
     * @return mixed
     */
    private function replace_all_url( $string ) {
        $options = get_option( 'wp_easy_export_import_guest', false );

        preg_match_all( "|<a.*(?=href=\"([^\"]*)\")[^>]*>([^<]*)</a>|i", $string, $matches );

        $host_url  = rtrim( $options['url'], '/' );
        $guest_url = str_replace( 'http://', '', get_site_url( null, '', 'http') );

        if ( ! empty( $matches ) && ! empty( $matches[1] ) ) {
            foreach( $matches[1] as $link ) {
                if ( false !== strpos( $link, $host_url ) ) {
                    $new_link = str_replace( $host_url, $guest_url, $link );
                    $string = str_replace( $link, $new_link, $string );
                }
            }
        }

        return $string;
    }
}

// Boot the plugin.
WP_Easy_Export_Import::get_instance();
