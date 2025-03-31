<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register the REST API routes for Web Story Importer.
 */
function wsi_register_rest_routes() {
    register_rest_route(
        'web-story-importer/v1',
        '/import',
        array(
            'methods'             => 'POST',
            'callback'            => 'wsi_handle_rest_import',
            'permission_callback' => 'wsi_rest_permission_check',
            'args'                => array(
                'file' => array(
                    'description' => __('The ZIP file to import', 'web-story-importer'),
                    'required'    => false, // Changed from true to false to handle different request formats
                ),
                'post_status' => array(
                    'description' => __('Post status for the imported story (draft or publish)', 'web-story-importer'),
                    'type'        => 'string',
                    'enum'        => array('draft', 'publish'),
                    'default'     => 'draft',
                    'required'    => false,
                ),
            ),
        )
    );
}
add_action('rest_api_init', 'wsi_register_rest_routes');

/**
 * Check if the user has permission to upload files.
 *
 * @return bool|WP_Error True if the user has permission, WP_Error otherwise.
 */
function wsi_rest_permission_check() {
    // Check if user can upload files
    if (!current_user_can('upload_files')) {
        return new WP_Error(
            'rest_forbidden',
            __('You do not have permission to import web stories.', 'web-story-importer'),
            array('status' => 403)
        );
    }
    return true;
}

/**
 * Handle the REST API request to import a web story.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error The response or error.
 */
function wsi_handle_rest_import($request) {
    // Set up logging for debugging request
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/wsi-api-debug.log';
    
    // Log request information
    $log_data = "Request received: " . date('Y-m-d H:i:s') . "\n";
    $log_data .= "Headers: " . json_encode($request->get_headers()) . "\n";
    $log_data .= "Parameters: " . json_encode($request->get_params()) . "\n";
    $log_data .= "Files: " . json_encode($request->get_file_params()) . "\n";
    $log_data .= "Body: " . json_encode($request->get_body()) . "\n\n";
    file_put_contents($log_file, $log_data, FILE_APPEND);
    
    // Check for file in different possible locations
    $files = $request->get_file_params();
    $params = $request->get_params();
    
    $zip_file = null;
    $temp_file_path = null;
    
    // Approach 1: Standard WP REST API file uploads
    if (!empty($files) && isset($files['file']) && $files['file']['error'] === UPLOAD_ERR_OK) {
        $zip_file = $files['file'];
    } 
    // Approach 2: Check for binary data in request body (common with n8n and other API clients)
    else if ($request->get_content_type()['value'] === 'application/zip' || $request->get_header('Content-Type') === 'application/zip') {
        $body = $request->get_body();
        if (!empty($body)) {
            $temp_file_path = $upload_dir['basedir'] . '/temp_' . uniqid() . '.zip';
            file_put_contents($temp_file_path, $body);
            
            // Create a virtual $_FILES-like structure
            $zip_file = array(
                'name' => 'uploaded.zip',
                'type' => 'application/zip',
                'tmp_name' => $temp_file_path,
                'error' => 0,
                'size' => filesize($temp_file_path)
            );
        }
    }
    // Approach 3: Check for Base64-encoded file data
    else if (isset($params['file_data']) && !empty($params['file_data'])) {
        $file_data = $params['file_data'];
        // Strip data URI scheme if present
        if (strpos($file_data, 'data:') === 0) {
            $file_data = substr($file_data, strpos($file_data, ',') + 1);
        }
        
        // Decode the Base64 data
        $decoded_data = base64_decode($file_data);
        if ($decoded_data !== false) {
            $temp_file_path = $upload_dir['basedir'] . '/temp_' . uniqid() . '.zip';
            file_put_contents($temp_file_path, $decoded_data);
            
            // Create a virtual $_FILES-like structure
            $zip_file = array(
                'name' => isset($params['file_name']) ? $params['file_name'] : 'uploaded.zip',
                'type' => 'application/zip',
                'tmp_name' => $temp_file_path,
                'error' => 0,
                'size' => filesize($temp_file_path)
            );
        }
    }
    // Approach 4: Special handling for n8n binary data
    else if ($request->get_header('n8n-binary-data') || $request->get_header('n8n-node')) {
        // n8n might send binary data differently, try to find it
        $body = $request->get_body();
        if (!empty($body)) {
            // Try to detect if it's actually a ZIP file by checking magic numbers
            $is_zip = substr($body, 0, 4) === "PK\x03\x04";
            
            if ($is_zip) {
                $temp_file_path = $upload_dir['basedir'] . '/temp_' . uniqid() . '.zip';
                file_put_contents($temp_file_path, $body);
                
                // Create a virtual $_FILES-like structure
                $zip_file = array(
                    'name' => 'n8n_upload.zip',
                    'type' => 'application/zip',
                    'tmp_name' => $temp_file_path,
                    'error' => 0,
                    'size' => filesize($temp_file_path)
                );
            }
        }
    }

    // Log what we found
    $log_data = "Processing upload: " . date('Y-m-d H:i:s') . "\n";
    $log_data .= "ZIP file found: " . ($zip_file ? 'Yes' : 'No') . "\n";
    if ($zip_file) {
        $log_data .= "ZIP file details: " . json_encode($zip_file) . "\n";
    }
    file_put_contents($log_file, $log_data, FILE_APPEND);
    
    // If we still don't have a file, return an error
    if (!$zip_file) {
        return new WP_Error(
            'no_file',
            __('No file was uploaded or could not be processed. Please send a ZIP file with parameter name "file".', 'web-story-importer'),
            array('status' => 400)
        );
    }
    
    // Basic validation
    if ($zip_file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = array(
            UPLOAD_ERR_INI_SIZE   => __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'web-story-importer'),
            UPLOAD_ERR_FORM_SIZE  => __('The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.', 'web-story-importer'),
            UPLOAD_ERR_PARTIAL    => __('The uploaded file was only partially uploaded.', 'web-story-importer'),
            UPLOAD_ERR_NO_FILE    => __('No file was uploaded.', 'web-story-importer'),
            UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder.', 'web-story-importer'),
            UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'web-story-importer'),
            UPLOAD_ERR_EXTENSION  => __('A PHP extension stopped the file upload.', 'web-story-importer'),
        );
        
        $error_message = isset($error_messages[$zip_file['error']]) 
            ? $error_messages[$zip_file['error']] 
            : __('Unknown upload error.', 'web-story-importer');
        
        // Clean up temporary file if needed
        if ($temp_file_path && file_exists($temp_file_path)) {
            unlink($temp_file_path);
        }
        
        return new WP_Error('upload_error', $error_message, array('status' => 400));
    }
    
    // Check file extension or try to determine file type
    $file_name = $zip_file['name'];
    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
    
    if ($file_ext !== 'zip') {
        // If the extension isn't .zip, check file signature
        $file_start = file_get_contents($zip_file['tmp_name'], false, null, 0, 4);
        $is_zip = $file_start === "PK\x03\x04";
        
        if (!$is_zip) {
            // Clean up temporary file if needed
            if ($temp_file_path && file_exists($temp_file_path)) {
                unlink($temp_file_path);
            }
            
            return new WP_Error(
                'invalid_file_type',
                __('The uploaded file must be a ZIP archive.', 'web-story-importer'),
                array('status' => 400)
            );
        }
        
        // Fix the file name to have .zip extension
        $file_name .= '.zip';
    }
    
    // Create uploads directory if it doesn't exist
    if (!file_exists($upload_dir['path'])) {
        wp_mkdir_p($upload_dir['path']);
    }
    
    // Move the uploaded file to the uploads directory
    $new_file_name = wp_unique_filename($upload_dir['path'], $file_name);
    $new_file_path = $upload_dir['path'] . '/' . $new_file_name;
    
    // If we already have the file in a temporary location
    if ($temp_file_path && $temp_file_path === $zip_file['tmp_name']) {
        // Just rename/move it
        rename($temp_file_path, $new_file_path);
    } else {
        // Otherwise move the uploaded file
        if (!move_uploaded_file($zip_file['tmp_name'], $new_file_path)) {
            // If move_uploaded_file fails (it might if we're not dealing with a standard upload)
            // try to copy the file directly
            if (!copy($zip_file['tmp_name'], $new_file_path)) {
                return new WP_Error(
                    'upload_error',
                    __('Failed to move uploaded file.', 'web-story-importer'),
                    array('status' => 500)
                );
            }
        }
    }
    
    // Log successful file move
    $log_data = "File moved successfully: " . date('Y-m-d H:i:s') . "\n";
    $log_data .= "New path: " . $new_file_path . "\n";
    file_put_contents($log_file, $log_data, FILE_APPEND);
    
    // Process the uploaded ZIP file
    $post_status = $request->get_param('post_status');
    if (!in_array($post_status, array('draft', 'publish'))) {
        $post_status = 'draft';
    }
    $import_result = wsi_import_web_story($new_file_path, $post_status);
    
    // Clean up the uploaded file
    @unlink($new_file_path);
    
    // Clean up temporary file if needed and still exists
    if ($temp_file_path && file_exists($temp_file_path)) {
        @unlink($temp_file_path);
    }
    
    if (is_wp_error($import_result)) {
        // Log error
        $log_data = "Import error: " . date('Y-m-d H:i:s') . "\n";
        $log_data .= "Error: " . $import_result->get_error_message() . "\n";
        file_put_contents($log_file, $log_data, FILE_APPEND);
        
        return new WP_Error(
            'import_error',
            $import_result->get_error_message(),
            array('status' => 500)
        );
    }
    
    // Get the story post
    $story_post = get_post($import_result);
    
    if (!$story_post) {
        return new WP_Error(
            'post_error',
            __('Failed to retrieve the imported story.', 'web-story-importer'),
            array('status' => 500)
        );
    }
    
    // Log success
    $log_data = "Import successful: " . date('Y-m-d H:i:s') . "\n";
    $log_data .= "Post ID: " . $import_result . "\n";
    $log_data .= "Title: " . $story_post->post_title . "\n\n";
    file_put_contents($log_file, $log_data, FILE_APPEND);
    
    // Return success response
    return rest_ensure_response(array(
        'success'   => true,
        'post_id'   => $import_result,
        'title'     => $story_post->post_title,
        'edit_url'  => get_edit_post_link($import_result, 'raw'),
        'view_url'  => get_permalink($import_result),
        'message'   => __('Web Story successfully imported.', 'web-story-importer')
    ));
}
