<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Add admin menu page.
 */
function wsi_add_admin_menu() {
    add_menu_page(
        __( 'Web Story Importer', 'web-story-importer' ),
        __( 'Story Importer', 'web-story-importer' ),
        'upload_files', // Capability required - allows users who can upload media
        'web-story-importer',
        'wsi_render_admin_page',
        'dashicons-media-archive',
        30
    );
}
add_action( 'admin_menu', 'wsi_add_admin_menu' );

/**
 * Create the imported stories tracking table during plugin activation.
 */
function wsi_create_imported_stories_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        import_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        import_message text,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(WSI_PLUGIN_FILE, 'wsi_create_imported_stories_table');

/**
 * Enqueue admin scripts and styles.
 */
function wsi_enqueue_admin_scripts($hook) {
    // Only load on plugin page
    if($hook != 'toplevel_page_web-story-importer') {
        return;
    }
    
    // Register and enqueue scripts
    wp_enqueue_style('wsi-admin-styles', WSI_PLUGIN_URL . 'assets/css/admin.css', array(), WSI_VERSION);
    wp_enqueue_script('wsi-admin-script', WSI_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WSI_VERSION, true);
    
    // Pass data to script
    wp_localize_script('wsi-admin-script', 'wsiData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wsi_ajax_nonce'),
        'uploading' => __('Uploading...', 'web-story-importer'),
        'processing' => __('Processing Web Story...', 'web-story-importer'),
        'importing' => __('Importing...', 'web-story-importer'),
        'success' => __('Import successful!', 'web-story-importer'),
        'error' => __('Error importing story.', 'web-story-importer'),
        'noFileSelected' => __('No file selected', 'web-story-importer')
    ));
}
add_action('admin_enqueue_scripts', 'wsi_enqueue_admin_scripts');

/**
 * Render the admin page content with tabs.
 */
function wsi_render_admin_page() {
    // Get current tab or default to import
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'import';
    
    settings_errors();
    ?>
    <div class="wrap wsi-admin">
        <h1><?php echo esc_html__( 'Web Story Importer', 'web-story-importer' ); ?></h1>
        
        <nav class="nav-tab-wrapper wp-clearfix">
            <a href="<?php echo esc_url(add_query_arg('tab', 'import', remove_query_arg('paged'))); ?>" class="nav-tab <?php echo $active_tab == 'import' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Import New Story', 'web-story-importer'); ?>
            </a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'imported', remove_query_arg('paged'))); ?>" class="nav-tab <?php echo $active_tab == 'imported' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Imported Stories', 'web-story-importer'); ?>
            </a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'api', remove_query_arg('paged'))); ?>" class="nav-tab <?php echo $active_tab == 'api' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('API Documentation', 'web-story-importer'); ?>
            </a>
        </nav>
        
        <div class="tab-content">
            <?php
            switch ($active_tab) {
                case 'imported':
                    wsi_render_imported_stories_tab();
                    break;
                case 'api':
                    wsi_render_api_documentation_tab();
                    break;
                case 'import':
                default:
                    wsi_render_import_tab();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Render the main import tab.
 */
function wsi_render_import_tab() {
    ?>
    <div class="wsi-tab-section">
        <div class="wsi-card wsi-import-card">
            <h2><?php esc_html_e('Import a Web Story from ZIP', 'web-story-importer'); ?></h2>
            <p class="wsi-intro-text"><?php esc_html_e('Upload a .zip file containing your Web Story (HTML file and assets folder).', 'web-story-importer'); ?></p>
            
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wsi-import-form">
                <?php wp_nonce_field('wsi_upload_zip_action', 'wsi_upload_zip_nonce'); ?>
                <input type="hidden" name="action" value="wsi_handle_upload">
                
                <div class="wsi-form-group">
                    <label for="wsi_story_zip" class="wsi-label">
                        <?php esc_html_e('Web Story ZIP File', 'web-story-importer'); ?>
                    </label>
                    <div class="wsi-file-input-wrapper">
                        <div class="wsi-file-input-container">
                            <input type="file" id="wsi_story_zip" name="wsi_story_zip" accept=".zip" required class="wsi-file-input">
                            <div class="wsi-file-preview">
                                <span class="wsi-file-name"><?php esc_html_e('No file selected', 'web-story-importer'); ?></span>
                            </div>
                        </div>
                        <label for="wsi_story_zip" class="wsi-file-button">
                            <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Choose File', 'web-story-importer'); ?>
                        </label>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Must be a .zip archive containing one .html file and an assets folder.', 'web-story-importer'); ?>
                    </p>
                </div>
                
                <div class="wsi-import-progress" style="display: none;">
                    <div class="wsi-progress-bar">
                        <div class="wsi-progress-bar-inner"></div>
                    </div>
                    <div class="wsi-progress-status">
                        <span class="wsi-progress-text"><?php esc_html_e('Importing...', 'web-story-importer'); ?></span>
                    </div>
                </div>
                
                <div class="wsi-submit-container">
                    <button type="submit" class="button button-primary wsi-submit-button">
                        <span class="dashicons dashicons-migrate"></span> <?php esc_html_e('Import Story', 'web-story-importer'); ?>
                    </button>
                </div>
            </form>
        </div>
        
        <div class="wsi-card wsi-help-card">
            <h3><?php esc_html_e('How to prepare your ZIP file', 'web-story-importer'); ?></h3>
            <ol class="wsi-help-steps">
                <li>
                    <span class="wsi-step-number">1</span>
                    <span class="wsi-step-text"><?php esc_html_e('Create a ZIP file containing your Web Story HTML file at the root level.', 'web-story-importer'); ?></span>
                </li>
                <li>
                    <span class="wsi-step-number">2</span>
                    <span class="wsi-step-text"><?php esc_html_e('Include an "assets" folder containing all images, videos, and other media used in the story.', 'web-story-importer'); ?></span>
                </li>
                <li>
                    <span class="wsi-step-number">3</span>
                    <span class="wsi-step-text"><?php esc_html_e('Make sure the HTML file references the assets using relative paths (e.g., "assets/image.jpg").', 'web-story-importer'); ?></span>
                </li>
            </ol>
        </div>
    </div>
    <?php
}

/**
 * Render the imported stories tab.
 */
function wsi_render_imported_stories_tab() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    
    // Pagination setup
    $per_page = 10;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Get total records for pagination
    $total_items = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name}" ) );
    $total_pages = ceil($total_items / $per_page);
    
    // Get imported stories
    $imported_stories = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table_name} ORDER BY import_date DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) );
    
    ?>
    <div class="wsi-tab-section">
        <div class="wsi-card">
            <h2><?php esc_html_e('Imported Web Stories', 'web-story-importer'); ?></h2>
            
            <?php if (empty($imported_stories)): ?>
                <div class="wsi-empty-state">
                    <p><?php esc_html_e('No Web Stories have been imported yet.', 'web-story-importer'); ?></p>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'import', remove_query_arg('paged'))); ?>" class="button button-primary">
                        <?php esc_html_e('Import Your First Story', 'web-story-importer'); ?>
                    </a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped wsi-stories-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Story Title', 'web-story-importer'); ?></th>
                            <th><?php esc_html_e('Original File', 'web-story-importer'); ?></th>
                            <th><?php esc_html_e('Import Date', 'web-story-importer'); ?></th>
                            <th><?php esc_html_e('Status', 'web-story-importer'); ?></th>
                            <th><?php esc_html_e('Actions', 'web-story-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imported_stories as $story): ?>
                            <?php 
                            $post_exists = false;
                            if (!empty($story->post_id)) {
                                $post_exists = get_post($story->post_id);
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($story->story_title); ?>
                                </td>
                                <td><?php echo esc_html($story->original_file); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($story->import_date))); ?></td>
                                <td>
                                    <span class="wsi-status wsi-status-<?php echo esc_attr($story->status); ?>">
                                        <?php echo esc_html(ucfirst($story->status)); ?>
                                    </span>
                                    <?php if (!empty($story->messages)): ?>
                                        <span class="wsi-error-info dashicons dashicons-info" 
                                              title="<?php echo esc_attr($story->messages); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="wsi-actions">
                                    <?php if ($post_exists): ?>
                                        <a href="<?php echo esc_url(get_permalink($story->post_id)); ?>"
                                           class="button button-small" target="_blank">
                                            <?php esc_html_e('View', 'web-story-importer'); ?>
                                        </a>
                                        <a href="<?php echo esc_url(get_edit_post_link($story->post_id)); ?>"
                                           class="button button-small">
                                            <?php esc_html_e('Edit', 'web-story-importer'); ?>
                                        </a>
                                    <?php else: ?>
                                        <em><?php esc_html_e('Post deleted', 'web-story-importer'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                    <div class="wsi-pagination">
                        <?php
                        $big = 999999999; // need an unlikely integer

                        $translated = __( 'Page', 'web-story-importer' ); // Supply translatable text from the page_html method

                        $pagination = paginate_links( array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page,
                        ));
                        echo $pagination;
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Render the API Documentation tab.
 */
function wsi_render_api_documentation_tab() {
    $site_url = get_site_url();
    $endpoint = $site_url . '/wp-json/web-story-importer/v1/import';
    ?>
    <div class="wsi-tab-section">
        <div class="wsi-card wsi-api-card">
            <h2><?php esc_html_e('REST API Documentation', 'web-story-importer'); ?></h2>
            <p class="wsi-intro-text">
                <?php esc_html_e('Web Story Importer provides a REST API endpoint that allows you to programmatically import Web Stories from ZIP files using external applications or services.', 'web-story-importer'); ?>
            </p>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Endpoint Information', 'web-story-importer'); ?></h3>
                <table class="wsi-api-table">
                    <tr>
                        <th><?php esc_html_e('Endpoint URL', 'web-story-importer'); ?></th>
                        <td><code><?php echo esc_html($endpoint); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Method', 'web-story-importer'); ?></th>
                        <td><code>POST</code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Required Permission', 'web-story-importer'); ?></th>
                        <td><?php esc_html_e('User must have the "upload_files" capability (typically Editor or Administrator)', 'web-story-importer'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Authentication', 'web-story-importer'); ?></th>
                        <td><?php esc_html_e('Requires WordPress authentication (via cookie, basic auth, application password, or OAuth)', 'web-story-importer'); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Request Parameters', 'web-story-importer'); ?></h3>
                <p><?php esc_html_e('The endpoint supports various ways to upload ZIP files:', 'web-story-importer'); ?></p>
                <table class="wsi-api-table">
                    <tr>
                        <th><?php esc_html_e('Method', 'web-story-importer'); ?></th>
                        <th><?php esc_html_e('Description', 'web-story-importer'); ?></th>
                    </tr>
                    <tr>
                        <td><code>multipart/form-data</code></td>
                        <td><?php esc_html_e('Standard form upload with file parameter named "file"', 'web-story-importer'); ?></td>
                    </tr>
                    <tr>
                        <td><code>application/zip</code></td>
                        <td><?php esc_html_e('Direct binary upload with Content-Type: application/zip', 'web-story-importer'); ?></td>
                    </tr>
                    <tr>
                        <td><code>Base64</code></td>
                        <td><?php esc_html_e('Send base64-encoded file data in the "file_data" parameter', 'web-story-importer'); ?></td>
                    </tr>
                </table>

                <h4 class="wsi-mt-4"><?php esc_html_e('Additional Parameters', 'web-story-importer'); ?></h4>
                <table class="wsi-api-table">
                    <tr>
                        <th><?php esc_html_e('Parameter', 'web-story-importer'); ?></th>
                        <th><?php esc_html_e('Description', 'web-story-importer'); ?></th>
                        <th><?php esc_html_e('Type', 'web-story-importer'); ?></th>
                        <th><?php esc_html_e('Default', 'web-story-importer'); ?></th>
                    </tr>
                    <tr>
                        <td><code>post_status</code></td>
                        <td><?php esc_html_e('Status for the imported story. Use "publish" to make it live immediately or "draft" to save for later editing.', 'web-story-importer'); ?></td>
                        <td><code>string</code> <small>(draft|publish)</small></td>
                        <td><code>draft</code></td>
                    </tr>
                </table>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Response Format', 'web-story-importer'); ?></h3>
                <p><?php esc_html_e('On successful import, the API will return a JSON response with the following structure:', 'web-story-importer'); ?></p>
                <pre class="wsi-code-block">
{
  "success": true,
  "post_id": 123,
  "title": "Imported Story Title",
  "edit_url": "https://your-site.com/wp-admin/post.php?post=123&action=edit",
  "view_url": "https://your-site.com/web-stories/imported-story-title/",
  "message": "Web Story successfully imported."
}</pre>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Error Responses', 'web-story-importer'); ?></h3>
                <p><?php esc_html_e('The API will return appropriate error codes and messages if something goes wrong:', 'web-story-importer'); ?></p>
                <ul class="wsi-api-list">
                    <li><strong>400</strong>: <?php esc_html_e('Invalid request (missing file, wrong file type)', 'web-story-importer'); ?></li>
                    <li><strong>403</strong>: <?php esc_html_e('Unauthorized access (user doesn\'t have required permissions)', 'web-story-importer'); ?></li>
                    <li><strong>500</strong>: <?php esc_html_e('Server error during import process', 'web-story-importer'); ?></li>
                </ul>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Integration with n8n', 'web-story-importer'); ?></h3>
                <p><?php esc_html_e('To integrate with n8n, follow these steps:', 'web-story-importer'); ?></p>
                
                <ol class="wsi-api-list">
                    <li><?php esc_html_e('Create an HTTP Request node in n8n', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Set the request method to POST', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Set the URL to your endpoint', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Set Authentication to "Basic Auth" and provide WordPress credentials', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('For the binary data, select "Send Binary Data" and map the ZIP file from a previous node', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Set the Content-Type header to "application/zip"', 'web-story-importer'); ?></li>
                </ol>
                
                <p><?php esc_html_e('n8n Example Configuration:', 'web-story-importer'); ?></p>
                <pre class="wsi-code-block">
// n8n HTTP Request Node Configuration
{
  "parameters": {
    "url": "<?php echo esc_html($endpoint); ?>",
    "method": "POST",
    "authentication": "basicAuth",
    "username": "your-username",
    "password": "your-password",
    "sendBinaryData": true,
    "binaryPropertyName": "data",
    "headerParameters": {
      "parameters": [
        {
          "name": "Content-Type",
          "value": "application/zip"
        }
      ]
    }
  }
}</pre>
                <p class="wsi-note"><?php esc_html_e('Note: The "data" property should match the binary property name where your ZIP file is stored.', 'web-story-importer'); ?></p>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Code Examples', 'web-story-importer'); ?></h3>
                
                <h4><?php esc_html_e('cURL Example', 'web-story-importer'); ?></h4>
                <pre class="wsi-code-block">
curl -X POST \
  <?php echo esc_html($endpoint); ?> \
  -H 'Authorization: Basic YWRtaW46cGFzc3dvcmQ=' \
  -H 'Content-Type: multipart/form-data' \
  -F 'file=@/path/to/your/story.zip' \
  -F 'post_status=publish'</pre>
                
                <h4><?php esc_html_e('PHP Example', 'web-story-importer'); ?></h4>
                <pre class="wsi-code-block">
$request = wp_remote_post(
    '<?php echo esc_html($endpoint); ?>',
    [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('username:password'),
        ],
        'body' => [
            'file' => curl_file_create('/path/to/your/story.zip', 'application/zip', 'story.zip'),
            'post_status' => 'publish', // Optional: 'draft' (default) or 'publish'
        ],
    ]
);

if (!is_wp_error($request)) {
    $response = json_decode(wp_remote_retrieve_body($request), true);
    // Handle response
    echo "Story imported successfully! ID: " . $response['post_id'];
}</pre>
                
                <h4><?php esc_html_e('JavaScript/Fetch Example', 'web-story-importer'); ?></h4>
                <pre class="wsi-code-block">
const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('post_status', 'publish'); // Optional: 'draft' (default) or 'publish'

fetch('<?php echo esc_html($endpoint); ?>', {
  method: 'POST',
  headers: {
    'Authorization': 'Basic ' + btoa('username:password')
  },
  body: formData
})
.then(response => response.json())
.then(data => {
  console.log('Success:', data);
  // data.post_id contains the ID of the imported story
  // data.view_url contains the URL to view the story
})
.catch(error => {
  console.error('Error:', error);
});</pre>

                <h4><?php esc_html_e('Binary Data Upload Example', 'web-story-importer'); ?></h4>
                <pre class="wsi-code-block">
// Read file as binary
const fileBuffer = fs.readFileSync('/path/to/your/story.zip');

fetch('<?php echo esc_html($endpoint); ?>', {
  method: 'POST',
  headers: {
    'Authorization': 'Basic ' + btoa('username:password'),
    'Content-Type': 'application/zip'
  },
  body: fileBuffer
})
.then(response => response.json())
.then(data => console.log('Success:', data))
.catch(error => console.error('Error:', error));</pre>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Security Considerations', 'web-story-importer'); ?></h3>
                <ul class="wsi-api-list">
                    <li><?php esc_html_e('Make sure your WordPress site uses HTTPS', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Consider using application passwords for more secure authentication', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Implement IP restrictions if possible', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Be careful with error messages as they might reveal sensitive information', 'web-story-importer'); ?></li>
                </ul>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Troubleshooting', 'web-story-importer'); ?></h3>
                <p><?php esc_html_e('If you encounter issues with the API:', 'web-story-importer'); ?></p>
                <ul class="wsi-api-list">
                    <li><?php esc_html_e('Check the debug log at wp-content/uploads/wsi-api-debug.log', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Ensure your ZIP file is properly formatted with an HTML file and assets folder', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('Verify authentication credentials are correct', 'web-story-importer'); ?></li>
                    <li><?php esc_html_e('For n8n, make sure Content-Type is set to "application/zip" when sending binary data', 'web-story-importer'); ?></li>
                </ul>
            </div>
            
            <div class="wsi-api-section">
                <h3><?php esc_html_e('Application Passwords', 'web-story-importer'); ?></h3>
                <p>
                    <?php 
                    if (function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available()) {
                        $app_password_url = admin_url('profile.php#application-passwords-section');
                        echo sprintf(
                            __('For secure API authentication, you can use <a href="%s">Application Passwords</a>, which are specific to your account and can be revoked at any time.', 'web-story-importer'),
                            esc_url($app_password_url)
                        );
                    } else {
                        echo esc_html__('For secure API authentication, consider installing and activating the Application Passwords feature or using a security plugin that supports token-based authentication.', 'web-story-importer');
                    } 
                    ?>
                </p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Handle the file upload via admin-post.php.
 */
function wsi_handle_upload_action() {
    // Verify nonce
    if ( ! isset( $_POST['wsi_upload_zip_nonce'] ) || ! wp_verify_nonce( $_POST['wsi_upload_zip_nonce'], 'wsi_upload_zip_action' ) ) {
        wp_die( __( 'Security check failed. Please try again.', 'web-story-importer' ) );
    }
    
    // Check user capability
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_die( __( 'You do not have permission to upload files.', 'web-story-importer' ) );
    }
    
    // Check if file was uploaded
    if ( ! isset( $_FILES['wsi_story_zip'] ) || $_FILES['wsi_story_zip']['error'] != UPLOAD_ERR_OK ) {
        $error_message = '';
        if (isset($_FILES['wsi_story_zip'])) {
            $error_code = $_FILES['wsi_story_zip']['error'];
            switch ($error_code) {
                case UPLOAD_ERR_INI_SIZE:
                    $error_message = __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'web-story-importer');
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $error_message = __('The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'web-story-importer');
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_message = __('The uploaded file was only partially uploaded.', 'web-story-importer');
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_message = __('No file was uploaded.', 'web-story-importer');
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $error_message = __('Missing a temporary folder.', 'web-story-importer');
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $error_message = __('Failed to write file to disk.', 'web-story-importer');
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $error_message = __('A PHP extension stopped the file upload.', 'web-story-importer');
                    break;
                default:
                    $error_message = __('Unknown upload error.', 'web-story-importer');
            }
        }
        
        add_settings_error(
            'wsi_upload_error',
            sprintf(__('Error uploading file: %s', 'web-story-importer'), $error_message),
            'error'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=web-story-importer'));
        exit;
    }
    
    // Check file type
    $file_type = wp_check_filetype( $_FILES['wsi_story_zip']['name'] );
    if ( $file_type['ext'] !== 'zip' ) {
        add_settings_error(
            'wsi_filetype_error',
            __('Error: Please upload a ZIP file.', 'web-story-importer'),
            'error'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=web-story-importer'));
        exit;
    }
    
    // Process the upload
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/wsi-imports/';
    
    // Create directory if it doesn't exist
    if ( ! file_exists( $target_dir ) ) {
        wp_mkdir_p( $target_dir );
    }
    
    // Create a unique filename
    $timestamp = time();
    $target_file = $target_dir . 'story-' . $timestamp . '.zip';
    
    // Move uploaded file
    if ( ! move_uploaded_file( $_FILES['wsi_story_zip']['tmp_name'], $target_file ) ) {
        add_settings_error(
            'wsi_upload_move_error',
            __('Error: Could not move uploaded file.', 'web-story-importer'),
            'error'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=web-story-importer'));
        exit;
    }
    
    // Extract the zip file
    $extract_dir = $target_dir . 'story-' . $timestamp . '/';
    WP_Filesystem();
    $unzipped = unzip_file( $target_file, $extract_dir );
    
    if ( is_wp_error( $unzipped ) ) {
        add_settings_error(
            'wsi_unzip_error',
            sprintf(__('Error extracting zip file: %s', 'web-story-importer'), $unzipped->get_error_message()),
            'error'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=web-story-importer'));
        exit;
    }
    
    // Find the HTML file
    $files = glob($extract_dir . '*.html');
    
    if ( empty( $files ) ) {
        add_settings_error(
            'wsi_no_html_error',
            __('Error: No HTML file found in the uploaded ZIP.', 'web-story-importer'),
            'error'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_redirect(admin_url('admin.php?page=web-story-importer'));
        exit;
    }
    
    // Load the HTML content
    $html_file = $files[0]; // Use the first HTML file found
    $html_content = file_get_contents( $html_file );
    
    // Import assets
    $assets_dir = dirname( $html_file ) . '/assets/';
    if ( file_exists( $assets_dir ) ) {
        wsi_import_assets( $assets_dir );
    }
    
    // Convert HTML to Web Story JSON
    $story_data = wsi_convert_html_to_story_json( $html_content );
    
    // Get the filename for logging
    $file_name = '';
    $story_title = isset($story_data['title']) ? $story_data['title'] : 'Imported Story';
    
    if (isset($_FILES['wsi_story_zip']) && isset($_FILES['wsi_story_zip']['name'])) {
        $file_name = sanitize_text_field($_FILES['wsi_story_zip']['name']);
        $original_filename = basename($html_file);
    }
    
    // Create the story post using the original HTML content and story JSON
    $story_json = wp_json_encode($story_data);
    $post_id = wsi_create_story_post($html_content, $story_json, $html_file);
    
    // Track import in our database using wsi_log_imported_story function
    wsi_log_imported_story(
        $story_title,
        $file_name,
        $post_id,
        'completed',
        ''
    );
    
    // Clean up
    unlink( $target_file ); // Delete the zip file
    wsi_delete_directory( $extract_dir ); // Delete the extracted files
    
    // Redirect to the list of imported stories
    add_settings_error(
        'wsi_import_success',
        sprintf(
            __('Story imported successfully! <a href="%s">Edit Story</a> | <a href="%s">View Story</a>', 'web-story-importer'),
            get_edit_post_link($post_id),
            get_permalink($post_id)
        ),
        'success'
    );
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_redirect(admin_url('admin.php?page=web-story-importer&tab=imported'));
    exit;
}
add_action( 'admin_post_wsi_handle_upload', 'wsi_handle_upload_action' );
