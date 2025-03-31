<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Process the uploaded Web Story ZIP file.
 *
 * @param string $zip_file_path Path to the uploaded ZIP file.
 * @return array|WP_Error Array with 'html_file' and 'temp_dir' on success, WP_Error on failure.
 */
function wsi_process_story_zip( $zip_file_path ) {
    global $wp_filesystem;

    // Initialize the WP_Filesystem
    if ( ! $wp_filesystem ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    if ( ! $wp_filesystem ) {
        return new WP_Error( 'filesystem_error', __( 'Could not initialize WordPress Filesystem.', 'web-story-importer' ) );
    }

    // Create a unique temporary directory for extraction
    $upload_dir = wp_upload_dir();
    // Check if 'basedir' exists and is writable
    if ( ! isset($upload_dir['basedir']) || ! $wp_filesystem->is_writable( $upload_dir['basedir'] ) ) {
         return new WP_Error( 'dir_error', __( 'Upload directory is not writable.', 'web-story-importer' ) );
    }
    $temp_dir_path = trailingslashit( $upload_dir['basedir'] ) . 'web-story-importer-temp/' . uniqid( 'story_' );

    // Ensure the parent directory exists and is writable
    if ( ! $wp_filesystem->is_dir( dirname( $temp_dir_path ) ) ) {
        $wp_filesystem->mkdir( dirname( $temp_dir_path ), FS_CHMOD_DIR );
    }

    // Attempt to create the temporary directory
    if ( ! $wp_filesystem->mkdir( $temp_dir_path, FS_CHMOD_DIR ) ) {
        return new WP_Error( 'dir_error', sprintf( __( 'Could not create temporary directory: %s', 'web-story-importer' ), esc_html( $temp_dir_path ) ) );
    }

    // Unzip the file
    $unzip_result = unzip_file( $zip_file_path, $temp_dir_path );

    if ( is_wp_error( $unzip_result ) ) {
        wsi_cleanup_temp_dir( $temp_dir_path ); // Clean up on error
        return new WP_Error( 'unzip_error', __( 'Could not unzip the file.', 'web-story-importer' ) . ' ' . $unzip_result->get_error_message() );
    }

    // Scan the extracted contents
    $html_file = null;
    $assets_dir = null;
    $root_files = $wp_filesystem->dirlist( $temp_dir_path, false, false ); // Non-recursive

    if ( ! $root_files ) {
        wsi_cleanup_temp_dir( $temp_dir_path );
        return new WP_Error( 'scan_error', __( 'Could not read the contents of the extracted folder.', 'web-story-importer' ) );
    }

    // Check for potential nested folder (common in exports)
    $extracted_base_path = $temp_dir_path;
    if ( count($root_files) === 1 ) {
        $first_item = reset($root_files);
        if ( isset($first_item['type']) && $first_item['type'] === 'd' ){
            $potential_base_path = trailingslashit($temp_dir_path) . $first_item['name'];
            $nested_files = $wp_filesystem->dirlist( $potential_base_path, false, false );
            if ($nested_files) { // If the nested dir is not empty, assume it's the actual root
                $extracted_base_path = $potential_base_path;
                $root_files = $nested_files; // Scan inside the nested folder
            }
        }
    }


    $html_file_count = 0;
    foreach ( $root_files as $filename => $details ) {
        if ( isset( $details['type'] ) && $details['type'] === 'f' && strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) === 'html' ) {
            $html_file = trailingslashit( $extracted_base_path ) . $filename;
            $html_file_count++;
        }
        if ( isset( $details['type'] ) && $details['type'] === 'd' && strtolower( $filename ) === 'assets' ) {
            $assets_dir = trailingslashit( $extracted_base_path ) . $filename;
        }
    }

    // Validate findings
    if ( $html_file_count === 0 ) {
        wsi_cleanup_temp_dir( $temp_dir_path );
        return new WP_Error( 'no_html_file', __( 'No HTML file found in the root of the ZIP archive (or within a single nested folder).', 'web-story-importer' ) );
    }

    if ( $html_file_count > 1 ) {
        wsi_cleanup_temp_dir( $temp_dir_path );
        return new WP_Error( 'multiple_html_files', __( 'Multiple HTML files found in the root of the ZIP archive (or within a single nested folder). Please ensure only one is present.', 'web-story-importer' ) );
    }

    if ( ! $assets_dir ) {
        // This might be okay, maybe the story has no assets or they are external
        // For now, we'll allow it but could add a warning later.
        // Consider adding a message: __('Warning: No 'assets' folder found. Ensure all media is externally hosted or embedded.', 'web-story-importer')
    }

    return array(
        'html_file' => $html_file,
        'assets_dir' => $assets_dir, // Path to assets dir, or null if not found
        'temp_dir' => $temp_dir_path, // The original top-level temp dir
        'base_path' => $extracted_base_path // Path where HTML and assets dir reside
    );
}

/**
 * Recursively remove a directory and its contents.
 *
 * @param string $dir_path Path to the directory to remove.
 */
function wsi_cleanup_temp_dir( $dir_path ) {
    global $wp_filesystem;

    // Initialize the WP_Filesystem if not already done
    if ( ! $wp_filesystem ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    if ( $wp_filesystem && $wp_filesystem->is_dir( $dir_path ) ) {
        $wp_filesystem->delete( $dir_path, true );
    }
}

/**
 * Scans the assets directory, uploads media to the WP Media Library,
 * and returns a map of old relative paths to new WP URLs.
 *
 * @param string|null $assets_dir_path Absolute path to the extracted assets directory. Null if none found.
 * @param string $base_path Absolute path to the directory containing the HTML file and assets dir.
 * @return array|WP_Error An array ['path_map' => [old_rel_path => new_url], 'errors' => [file => error_msg]] on success, or WP_Error on critical failure.
 */
function wsi_process_assets( $assets_dir_path, $base_path ) {
    if ( ! $assets_dir_path ) {
        return ['path_map' => [], 'errors' => []]; // No assets directory found or provided.
    }

    global $wp_filesystem;
    if ( ! $wp_filesystem || ! $wp_filesystem->is_dir( $assets_dir_path ) ) {
        // Filesystem might not be initialized or dir doesn't exist (shouldn't happen if wsi_process_story_zip worked)
        return new WP_Error( 'assets_dir_error', __( 'Assets directory is not accessible.', 'web-story-importer' ) );
    }

    // Ensure required WordPress media functions are available
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $path_map = [];
    $upload_errors = [];
    $allowed_mime_types = get_allowed_mime_types();
    $allowed_extensions = array_keys( wp_get_mime_types() ); // Get extensions WP knows about

    // Base relative path (e.g., "assets/")
    $relative_assets_dir_name = basename($assets_dir_path);

    // Recursively get all files in the assets directory
    $all_files = $wp_filesystem->dirlist( $assets_dir_path, false, true ); // Recursive

    if ( $all_files === false ) {
         return new WP_Error( 'assets_scan_error', __( 'Could not scan the assets directory.', 'web-story-importer' ) );
    }

    // Helper function to process nested files from dirlist output
    function process_files_recursive( $files, $current_path_abs, $current_path_rel, $base_path_abs, &$path_map, &$upload_errors, $allowed_extensions ) {
        global $wp_filesystem;
        foreach ( $files as $name => $details ) {
            $file_abs_path = trailingslashit( $current_path_abs ) . $name;
            $file_rel_path = trailingslashit( $current_path_rel ) . $name;

            if ( isset( $details['type'] ) && $details['type'] === 'd' && ! empty( $details['files'] ) ) {
                // Recurse into subdirectory
                process_files_recursive( $details['files'], $file_abs_path, $file_rel_path, $base_path_abs, $path_map, $upload_errors, $allowed_extensions );
            } elseif ( isset( $details['type'] ) && $details['type'] === 'f' ) {
                // It's a file, check extension
                $file_info = pathinfo( $name );
                $extension = strtolower( $file_info['extension'] ?? '' );

                if ( in_array( $extension, $allowed_extensions, true ) ) {
                    // Attempt to upload the file
                    $filename_for_wp = wp_unique_filename( dirname($file_abs_path), basename($file_abs_path) ); // Ensure unique name in WP
                    $temp_copy_path = $file_abs_path; // Use directly since it's already in uploads dir

                    $file_data = [
                        'name'     => $filename_for_wp,
                        'tmp_name' => $temp_copy_path,
                        'error'    => 0,
                        'size'     => $wp_filesystem->size( $temp_copy_path )
                    ];

                    // Use wp_handle_sideload which takes care of moving the file
                    // to the correct year/month folder and creating the attachment post
                    $overrides = array( 'test_form' => false );
                    $upload_result = wp_handle_sideload( $file_data, $overrides );

                    if ( isset( $upload_result['error'] ) ) {
                        $upload_errors[ $file_rel_path ] = $upload_result['error'];
                    } else {
                        $attachment_url = $upload_result['url'];
                        $attachment_file = $upload_result['file'];
                        $attachment_type = $upload_result['type'];

                        // Create the attachment post
                        $attachment = array(
                            'guid'           => $attachment_url,
                            'post_mime_type' => $attachment_type,
                            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename_for_wp ) ),
                            'post_content'   => '',
                            'post_status'    => 'inherit'
                        );
                        $attachment_id = wp_insert_attachment( $attachment, $attachment_file );

                        if ( ! is_wp_error( $attachment_id ) ) {
                            // Generate attachment metadata (thumbnails, etc.)
                            $attachment_data = wp_generate_attachment_metadata( $attachment_id, $attachment_file );
                            wp_update_attachment_metadata( $attachment_id, $attachment_data );

                            // Store the mapping: relative path from zip -> new WP URL
                            $path_map[ $file_rel_path ] = wp_get_attachment_url( $attachment_id );

                        } else {
                            $upload_errors[ $file_rel_path ] = __( 'Could not create attachment post.', 'web-story-importer' ) . ' ' . $attachment_id->get_error_message();
                             // Clean up the file if attachment creation failed
                             $wp_filesystem->delete($attachment_file);
                        }
                    }
                } // end if allowed extension
            } // end if file
        }
    }

    // Start processing from the assets directory
    process_files_recursive( $all_files, $assets_dir_path, $relative_assets_dir_name, $base_path, $path_map, $upload_errors, $allowed_extensions );

    return [
        'path_map' => $path_map,
        'errors' => $upload_errors
    ];

}

/**
 * Processes the HTML file content, replacing local asset paths with WP Media Library URLs.
 *
 * @param string $html_file_path Absolute path to the HTML file.
 * @param array $path_map Mapping of old relative asset paths to new WP URLs.
 * @return string|WP_Error The modified HTML content string on success, WP_Error on failure.
 */
function wsi_process_html( $html_file_path, $path_map ) {
    global $wp_filesystem;
    if ( ! $wp_filesystem ) {
        return new WP_Error( 'filesystem_error', __( 'WordPress Filesystem not initialized for HTML processing.', 'web-story-importer' ) );
    }

    $html_content = $wp_filesystem->get_contents( $html_file_path );
    if ( $html_content === false ) {
        return new WP_Error( 'html_read_error', sprintf( __( 'Could not read HTML file: %s', 'web-story-importer' ), esc_html( $html_file_path ) ) );
    }

    if ( empty( $path_map ) ) {
        return $html_content; // No paths to replace, return original content.
    }

    // Use DOMDocument to parse and modify the HTML
    $doc = new DOMDocument();
    // Suppress errors during loading of potentially imperfect HTML
    libxml_use_internal_errors( true );
    // Load the HTML. Need to handle encoding properly.
    // Prepending XML encoding declaration helps DOMDocument interpret UTF-8 correctly.
    if ( ! $doc->loadHTML( '<?xml encoding="UTF-8">' . $html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
         // LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD prevents adding <html><body> tags if missing
         // Check if loading failed even with basic handling
         $errors = libxml_get_errors();
         libxml_clear_errors();
         libxml_use_internal_errors( false );
         // Log errors if needed, but try to proceed if possible, otherwise return error
         // error_log("DOMDocument Load Errors: " . print_r($errors, true));
         return new WP_Error( 'html_parse_error', __( 'Could not parse the HTML structure. Please ensure it is valid.', 'web-story-importer' ) );
    }
    libxml_clear_errors();
    libxml_use_internal_errors( false );

    $xpath = new DOMXPath( $doc );

    // Selectors for common attributes needing updates
    // Note: Paths in HTML are expected to be relative like 'assets/image.jpg'
    $selectors = [
        '//img[@src]',
        '//amp-img[@src]',
        '//video[@src]',
        '//video[@poster]',
        '//amp-video[@src]',
        '//amp-video[@poster]',
        '//source[@src]', // For video/audio
        '//amp-story-page[@background-audio]',
        '//script[@src]', // Add scripts which may reference local files
        '//*[@style*="background-image"]' // Elements with inline background images
    ];

    $attributes_to_check = ['src', 'poster', 'background-audio'];

    foreach ( $selectors as $selector ) {
        $nodes = $xpath->query( $selector );
        if ( $nodes === false ) {
             // Handle potential XPath query error, though unlikely with these selectors
             continue;
        }

        foreach ( $nodes as $node ) {
            if ( $selector === '//*[@style*="background-image"]' ) {
                 // Handle inline style background images
                 $style = $node->getAttribute('style');
                 // Basic regex to find url(...) patterns
                 if ( preg_match_all( "/url\\((['\"]?)(.*?)\\1\\)/i", $style, $matches ) ) { // Use double quotes for regex string
                     $new_style = $style;
                     foreach ( $matches[2] as $index => $url ) {
                         $original_url_pattern = $matches[0][$index];
                         $trimmed_url = trim( $url );
                         
                         // Check if this URL is in our path map directly
                         if ( isset( $path_map[ $trimmed_url ] ) ) {
                             $new_url = $path_map[ $trimmed_url ];
                             // Replace the specific url(...) part
                             $new_style = str_replace( $original_url_pattern, 'url(\'' . esc_attr( $new_url ) . '\')', $new_style );
                         } 
                         // Check if this is an assets path reference
                         else if (strpos($trimmed_url, 'assets/') === 0) {
                             // Try to find a matching uploaded asset
                             foreach ($path_map as $old_path => $new_path) {
                                 if (basename($old_path) === basename($trimmed_url)) {
                                     $new_url = $new_path;
                                     $new_style = str_replace( $original_url_pattern, 'url(\'' . esc_attr( $new_url ) . '\')', $new_style );
                                     break;
                                 }
                             }
                         }
                     }
                     if ( $new_style !== $style ) {
                         $node->setAttribute( 'style', $new_style );
                     }
                 }
            } else {
                // Handle standard attributes like src, poster
                foreach ( $attributes_to_check as $attribute ) {
                    if ( $node->hasAttribute( $attribute ) ) {
                        $original_path = trim( $node->getAttribute( $attribute ) );
                        
                        // Check if this path is in our map directly
                        if ( isset( $path_map[ $original_path ] ) ) {
                            $new_url = $path_map[ $original_path ];
                            $node->setAttribute( $attribute, esc_url( $new_url ) );
                        }
                        // Check if this is an assets reference
                        else if (strpos($original_path, 'assets/') === 0) {
                            // Try to find the matching asset by basename
                            foreach ($path_map as $old_path => $new_path) {
                                if (basename($old_path) === basename($original_path)) {
                                    $node->setAttribute( $attribute, esc_url( $new_path ) );
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // Save the modified HTML back
    // Need to remove the prepended XML declaration
    $modified_html = $doc->saveHTML();
    $modified_html = preg_replace('/^<\?xml.*\?>\n?/i', '', $modified_html);
    // Also remove potential doctype/html/body tags added by saveHTML if loadHTML didn't prevent it fully
    // This is tricky; might need refinement based on typical input HTML structure.
    // A simpler approach might be needed if this causes issues, like string replacement,
    // but DOM parsing is generally safer.

    return $modified_html;
}

/**
 * Parses HTML content and attempts to convert it into the JSON structure
 * used by the Google Web Stories plugin (post_content_filtered).
 *
 * @param string $html_content The processed HTML content.
 * @return string JSON string representing the story data.
 */
function wsi_convert_html_to_story_json( $html_content ) {
    // Load the HTML content
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $success = $doc->loadHTML( '<html><head></head><body>' . $html_content . '</body></html>' );
    libxml_use_internal_errors( false );

    if ( ! $success ) {
        return '{}';
    }

    $xpath = new DOMXPath( $doc );

    // Extract story title
    $title = '';
    $title_element = $xpath->query( '//h1 | //h2 | //h3 | //h4' )->item( 0 );
    if ( $title_element ) {
        $title = $title_element->textContent;
    }

    // Basic story structure
    $story_data = [
        'version' => 47, // Update to latest version number used by Web Stories Plugin
        'autoAdvance' => true,
        'defaultPageDuration' => 7, 
        'currentStoryStyles' => [
            'colors' => []
        ],
        'backgroundAudio' => [
            'resource' => null
        ],
        'fonts' => [], // Required empty array - editor expects this
        'publisher' => [
            'name' => get_bloginfo('name'),
            'logo' => [
                'height' => null, 
                'width' => null, 
                'src' => null, 
                'id' => 0
            ]
        ],
        'title' => $title,
        'excerpt' => '',
        'featuredMedia' => [
            'id' => 0,
            'height' => 0,
            'width' => 0,
            'url' => '',
            'needsProxy' => false
        ],
        'pages' => [],
    ];

    // Find amp-story-page elements or similar containers
    $page_elements = $xpath->query( '//amp-story-page | //div[contains(@class, "page")]' );

    // DEBUG: Count the number of pages found
    $upload_dir = wp_upload_dir();
    $debug_dir = $upload_dir['basedir'] . '/wsi-debug';
    if (!is_dir($debug_dir)) {
        wp_mkdir_p($debug_dir);
    }
    $debug_info = "Found " . $page_elements->length . " pages\n\n";
    $debug_info .= "HTML Content:\n" . substr($html_content, 0, 1000) . "...\n\n";

    if ( $page_elements->length === 0 ) {
        // If no pages found, create a single page with all content
        $body = $xpath->query( '//body' )->item( 0 );
        $page_data = wsi_parse_page_from_body( $body, $xpath, 1 );
        $story_data['pages'][] = $page_data;
        
        $debug_info .= "No pages found, using body as page\n";
    } else {
        // Process each page element
        foreach ( $page_elements as $index => $page_element ) {
            $page_data = wsi_parse_page( $page_element, $xpath, $index + 1 );
            $story_data['pages'][] = $page_data;
            
            // DEBUG: Log page structure
            $debug_info .= "Page " . ($index + 1) . " has " . count($page_data['elements']) . " elements\n";
            $debug_info .= "Page ID: " . $page_data['id'] . "\n";
            foreach ($page_data['elements'] as $element) {
                $debug_info .= "  - Type: " . $element['type'] . ", ";
                if ($element['type'] === 'text') {
                    $debug_info .= "Content: " . substr($element['content'], 0, 30) . "..., ";
                    $debug_info .= "Font: " . json_encode($element['font']) . ", ";
                    $debug_info .= "Size: " . $element['fontSize'] . ", ";
                    $debug_info .= "Position: x=" . $element['x'] . ", y=" . $element['y'] . "\n";
                } else if ($element['type'] === 'image') {
                    $debug_info .= "Src: " . $element['resource']['src'] . ", ";
                    $debug_info .= "Position: x=" . $element['x'] . ", y=" . $element['y'] . "\n";
                } else if ($element['type'] === 'shape') {
                    $debug_info .= "Position: x=" . $element['x'] . ", y=" . $element['y'] . "\n";
                }
            }
            $debug_info .= "\n";
        }
    }

    // Convert to JSON
    $json = wp_json_encode( $story_data );
    
    // DEBUG: Save complete JSON and debug info
    file_put_contents($debug_dir . '/story-debug-' . time() . '.json', $json);
    file_put_contents($debug_dir . '/story-debug-' . time() . '.txt', $debug_info);
    
    return $json;
}

/**
 * Helper function to parse a single DOMElement into the Web Story element structure.
 *
 * @param DOMElement $element_node The DOM element.
 * @param DOMXPath $xpath The DOMXPath object.
 * @param int &$id_counter Reference to a counter for generating unique IDs.
 * @return array Parsed element data or empty array.
 */
function wsi_parse_element( $element_node, $xpath, &$id_counter ) {
    $tag_name = strtolower( $element_node->nodeName );
    $element_data = [];

    // --- Common Properties --- 
    $element_id = $element_node->getAttribute( 'id' );
    if ( ! $element_id ) {
        $element_id = 'element-' . $id_counter++;
    }
    $element_data['id'] = $element_id;

    // Attempt to parse style for position and size
    // NOTE: This is a simplified parser. Assumes inline styles like "position:absolute; left: X%; top: Y%; width: W%; height: H%; transform: rotate(Zdeg);"
    // More robust parsing might be needed for different CSS units or transform functions.
    $style = $element_node->getAttribute( 'style' );
    $styles = wsi_parse_inline_style( $style );

    // Default Web Stories units are often based on a 1000x1000 grid, but let's stick to % for now if available
    // The Google plugin might recalculate based on its own grid later.
    // We need to convert % to relative values (0 to 1)
    $element_data['x'] = isset( $styles['left'] ) ? ( floatval( rtrim( $styles['left'], '%' ) ) / 100 ) : 0;
    $element_data['y'] = isset( $styles['top'] ) ? ( floatval( rtrim( $styles['top'], '%' ) ) / 100 ) : 0;
    $element_data['width'] = isset( $styles['width'] ) ? ( floatval( rtrim( $styles['width'], '%' ) ) / 100 ) : 0.2; // Default width if not found
    $element_data['height'] = isset( $styles['height'] ) ? ( floatval( rtrim( $styles['height'], '%' ) ) / 100 ) : 0.2; // Default height
    $element_data['rotationAngle'] = isset( $styles['rotate'] ) ? floatval( $styles['rotate'] ) : 0;
    $element_data['mask'] = ['type' => 'rectangle']; // Default mask

    // --- Animation --- 
    // Example: animate-in="fly-in-left" animate-in-duration="1s" animate-in-delay="0.5s"
    $animation_type = $element_node->getAttribute('animate-in');
    if ( $animation_type ) {
        $element_data['animation'] = [
            'name' => $animation_type,
            'duration' => wsi_parse_css_time( $element_node->getAttribute('animate-in-duration') ), // Convert to ms
            'delay' => wsi_parse_css_time( $element_node->getAttribute('animate-in-delay') ), // Convert to ms
            // 'easing' => ?, // TODO: Parse easing if available
        ];
    }

    // --- Type-Specific Properties --- 
    switch ( $tag_name ) {
        case 'amp-img':
        case 'img':
            $element_data['type'] = 'image';
            $src_url = esc_url( $element_node->getAttribute('src') );
            $attachment_id = attachment_url_to_postid( $src_url );
            
            $element_data['resource'] = [
                'id' => $attachment_id ? $attachment_id : 0,
                'src' => $src_url,
                'alt' => esc_attr( $element_node->getAttribute('alt') ),
                'mimeType' => 'image/' . strtolower(pathinfo($element_node->getAttribute('src'), PATHINFO_EXTENSION)), // Basic guess
                'width' => intval($element_node->getAttribute('width')), // HTML width attr
                'height' => intval($element_node->getAttribute('height')) // HTML height attr
            ];
            // Override size from resource if available
            if ($element_data['resource']['width'] && $element_data['resource']['height']) {
                 // How to map resource width/height to relative x/y/width/height? Difficult.
                 // The editor likely handles this based on resource + layout properties.
                 // Let's keep the style-based % width/height for now.
            }
            break;

        case 'amp-video':
        case 'video':
            $element_data['type'] = 'video';
            $src_url = esc_url( $element_node->getAttribute('src') );
            $poster_url = $element_node->getAttribute('poster') ? esc_url($element_node->getAttribute('poster')) : null;
            $attachment_id = attachment_url_to_postid( $src_url );
            $poster_attachment_id = $poster_url ? attachment_url_to_postid( $poster_url ) : 0;
            $element_data['resource'] = [
                'id' => $attachment_id ? $attachment_id : 0,
                'src' => $src_url,
                'alt' => esc_attr( $element_node->getAttribute('alt') ),
                'mimeType' => 'video/' . strtolower(pathinfo($element_node->getAttribute('src'), PATHINFO_EXTENSION)), // Basic guess
                'width' => intval($element_node->getAttribute('width')),
                'height' => intval($element_node->getAttribute('height')),
                'poster' => $poster_url,
                'posterId' => $poster_attachment_id ? $poster_attachment_id : 0,
            ];
             break;

        case 'p':
        case 'h1':
        case 'h2':
        case 'h3':
        case 'h4':
        case 'h5':
        case 'h6':
            $element_data['type'] = 'text';
            $element_data['tagName'] = $tag_name;
            $content = '';
            foreach ($element_node->childNodes as $child) {
                $content .= $element_node->ownerDocument->saveHTML($child);
            }
            $content = trim($content);

            // If content is empty after trimming, skip this element
            if (empty($content)) {
                return [];
            }

            $element_data['content'] = $content;

            // --- Basic Style Mapping (Improved Placeholder) ---
            $styles = [];
            $inline_style_attr = $element_node->getAttribute('style');
            if ($inline_style_attr) {
                $styles = wsi_parse_inline_style($inline_style_attr); // Use helper function if available
            }

            // Default styles
            $font_size = 24;
            $line_height = 1.3;
            $font_weight = 400;
            $font_family = 'Roboto'; // Default
            $text_align = 'left';
            $color = '#FFFFFF'; // Default

            // Tag-based defaults
            if ($tag_name === 'h1' || $tag_name === 'h2') {
                 $font_size = ($tag_name === 'h1') ? 56 : 44;
                 $font_weight = 700;
            }

            // Class-based overrides (Example - needs more robust parsing)
            $class_attr = $element_node->getAttribute('class');
            if (strpos($class_attr, 'page-title') !== false) {
                 $font_size = ($tag_name === 'h1') ? 56 : 44; // Redundant based on tag default, but example
                 $font_weight = 700;
                 // TODO: Potentially parse specific font/color defined for .page-title in <style>
            }
             if (strpos($class_attr, 'page-description') !== false) {
                 $font_size = 24;
                 $font_weight = 400;
                 // TODO: Potentially parse specific font/color defined for .page-description in <style>
            }

            // Apply inline styles over defaults/class styles
            $font_family = $styles['font-family'] ?? $font_family;
            $font_size = intval($styles['font-size'] ?? $font_size);
            $line_height = floatval($styles['line-height'] ?? $line_height);
            $font_weight = $styles['font-weight'] ?? $font_weight;
            $text_align = $styles['text-align'] ?? $text_align;
            if (isset($styles['color'])) {
                $color = $styles['color'];
            }

            // TODO: Convert font weight (e.g., 'bold') to numeric if needed
            // TODO: Parse font size units (px, em) and convert to px
            // TODO: Extract font service (e.g., google fonts) if specified

            // --- Calculate Position & Dimensions (Simplified Stacking) ---
            // Estimate height based on font size (very rough)
            $estimated_height = $font_size * $line_height * 1.2; // Add some padding

            $element_data['x'] = 0.05 * 100; // 5% from left
            $element_data['y'] = 0.4 * 100; // 40% from top
            $element_data['width'] = 0.9 * 100; // 90% width
            $element_data['height'] = $estimated_height;
            $element_data['font'] = [
                'family' => $font_family,
                'service' => 'fonts.google.com', // Assume Google Fonts for now
                'fallbacks' => ['sans-serif'],
                'weight' => is_numeric($font_weight) ? intval($font_weight) : 400 // Ensure weight is numeric
            ];
            $element_data['fontSize'] = $font_size;
            $element_data['lineHeight'] = $line_height;
            $element_data['textAlign'] = $text_align;
            $element_data['color'] = ['color' => $color];
            break;

        case 'div':
            // Could be a shape or just a container. Assume shape for now if it has a background color.
            if ( isset( $styles['background-color'] ) || isset( $styles['background'] ) ) {
                $element_data['type'] = 'shape';
                 $element_data['shape'] = [
                     'backgroundColor' => ['color' => $styles['background-color'] ?? $styles['background'] ?? '#ffffff'] // Basic color
                 ];
            } else {
                 // If it's just a div without background, skip it? Or treat as container?
                 // For now, skip simple divs unless styled as shapes.
                 return [];
            }
            break;

        default:
             // Unknown element type
             return [];
    }

    return $element_data;
}

/**
 * Simple parser for inline style attribute.
 * Returns key-value pairs.
 * Handles basic transforms like rotate.
 *
 * @param string $style_string Inline style attribute string.
 * @return array Key-value pairs of styles.
 */
function wsi_parse_inline_style( $style_string ) {
    $styles = [];
    
    if ( empty( $style_string ) ) {
        return $styles;
    }
    
    // Split the style string into individual property declarations
    $declarations = explode( ';', $style_string );
    
    foreach ( $declarations as $declaration ) {
        $declaration = trim( $declaration );
        
        if ( empty( $declaration ) ) {
            continue;
        }
        
        // Split each declaration into property and value
        $parts = explode( ':', $declaration, 2 );
        
        if ( count( $parts ) === 2 ) {
            $property = trim( $parts[0] );
            $value = trim( $parts[1] );
            
            if ( ! empty( $property ) && ! empty( $value ) ) {
                $styles[ $property ] = $value;
            }
        }
    }
    
    return $styles;
}

/**
 * Creates a single page from body content when no page structure is found.
 *
 * @param DOMElement $body_element The body DOM element.
 * @param DOMXPath $xpath The DOMXPath object.
 * @param int $page_index The index of the page.
 * @return array Parsed page data.
 */
function wsi_parse_page_from_body( $body_element, $xpath, $page_index ) {
    $page_data = [
        'id' => 'page-' . $page_index,
        'elements' => [],
        'backgroundColor' => ['color' => '#FFFFFF'], // Default white
    ];
    
    // Find all direct children of body that could be elements
    $element_id_counter = 1;
    $body_elements = $xpath->query( './/*[self::img or self::video or self::p or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::div]', $body_element );
    
    foreach ( $body_elements as $element ) {
        $element_data = wsi_parse_element( $element, $xpath, $element_id_counter );
        if ( ! empty( $element_data ) ) {
            // Position elements nicely on the page
            // This is simplified - in a real scenario, you'd want smarter positioning
            // For now, just stack them vertically
            $element_data['y'] = 0.1 * count( $page_data['elements'] );
            $element_data['x'] = 0.1;
            $element_data['width'] = 0.8; // 80% width
            
            $page_data['elements'][] = $element_data;
            
            // Limit to reasonable number of elements
            if ( count( $page_data['elements'] ) >= 10 ) {
                break;
            }
        }
    }
    
    return $page_data;
}

/**
 * Converts CSS time string (e.g., "1s", "500ms") to milliseconds.
 *
 * @param string $time_string CSS time string.
 * @return int Time in milliseconds, or 0 if invalid.
 */
function wsi_parse_css_time( $time_string ) {
    if ( empty( $time_string ) ) {
        return 0;
    }
    if ( strtolower( substr( $time_string, -2 ) ) === 'ms' ) {
        return intval( $time_string );
    } elseif ( strtolower( substr( $time_string, -1 ) ) === 's' ) {
        return intval( floatval( $time_string ) * 1000 );
    }
    // Assume ms if no unit? Or return 0?
    return intval( $time_string ); // Fallback, might be incorrect
}

/**
 * Creates a new 'web-story' post using the processed content.
 *
 * @param string $html_content The processed HTML content (for post_content).
 * @param string $story_json   The JSON string for the story structure (for post_content_filtered).
 * @param string $original_html_path The path to the original uploaded HTML file (used for title fallback).
 * @return int|WP_Error The new post ID on success, or WP_Error on failure.
 */
function wsi_create_story_post( $html_content, $story_json, $original_html_path ) {
    if ( ! function_exists( 'wp_insert_post' ) ) {
        return new WP_Error( 'missing_wp_insert_post', __( 'WordPress function wp_insert_post is not available.', 'web-story-importer' ) );
    }

    // --- Determine Post Title --- 
    $post_title = __( 'Imported Web Story', 'web-story-importer' ); // Default title

    // Try to parse title from HTML content
    if ( ! empty( $html_content ) && preg_match( '/\<title\>(.*?)\<\/title\>/is', $html_content, $matches ) ) {
        $html_title = trim( strip_tags( $matches[1] ) );
        if ( ! empty( $html_title ) ) {
            $post_title = $html_title;
        }
    }
    // Fallback to filename if title couldn't be parsed or was empty
    if ( $post_title === __( 'Imported Web Story', 'web-story-importer' ) && ! empty( $original_html_path ) ) {
        $filename = basename( $original_html_path );
        // Remove extension
        $filename_without_ext = pathinfo( $filename, PATHINFO_FILENAME );
        if ( ! empty( $filename_without_ext ) ) {
            // Simple sanitization: replace underscores/hyphens with spaces, capitalize
            $post_title = ucwords( str_replace( ['_', '-'], ' ', $filename_without_ext ) );
        }
    }
    
    // Fix any remaining asset references in HTML content
    $html_content = wsi_fix_asset_references($html_content);
    
    // Fix JSON structure and references
    $story_data = json_decode($story_json, true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($story_data) && is_array($story_data)) {
        // Ensure version is updated
        $story_data['version'] = 57;
        
        // Ensure title is set
        if (empty($story_data['title'])) {
            $story_data['title'] = $post_title;
        }
        
        // Fix asset references in pages and elements
        if (!empty($story_data['pages'])) {
            foreach ($story_data['pages'] as &$page) {
                // First pass - standardize backgroundColor format for all pages
                if (isset($page['backgroundColor'])) {
                    // Case 1: If it's a simple string (like 'black' or '#000000')
                    if (is_string($page['backgroundColor'])) {
                        $page['backgroundColor'] = ['color' => $page['backgroundColor']];
                    }
                    // Case 2: If it has a 'color' key that's a string, leave it as is
                    else if (isset($page['backgroundColor']['color']) && is_string($page['backgroundColor']['color'])) {
                        // Already in the correct format
                    }
                    // Case 3: If it has a 'color' key that's not a string and not an array
                    else if (isset($page['backgroundColor']['color']) && !is_array($page['backgroundColor']['color']) && !is_string($page['backgroundColor']['color'])) {
                        $page['backgroundColor']['color'] = '#ffffff';
                    }
                    // Case 4: If it doesn't have a 'color' key at all
                    else if (!isset($page['backgroundColor']['color'])) {
                        $page['backgroundColor'] = ['color' => '#ffffff'];
                    }
                } else {
                    // If backgroundColor is not set at all, provide a default
                    $page['backgroundColor'] = ['color' => '#ffffff'];
                }
                
                // Process elements
                if (!empty($page['elements'])) {
                    foreach ($page['elements'] as &$element) {
                        // Fix image asset references
                        if ($element['type'] === 'image' && !empty($element['resource']['src'])) {
                            $src = $element['resource']['src'];
                            // Process any image source, not just those starting with assets/
                            $element['resource']['src'] = wsi_fix_asset_url($src);
                            
                            // Make sure poster images are also fixed
                            if (!empty($element['resource']['poster'])) {
                                $element['resource']['poster'] = wsi_fix_asset_url($element['resource']['poster']);
                            }
                        }

                        // Fix text element color format
if ($element['type'] === 'text' && isset($element['color']) && is_string($element['color'])) {
    $element['color'] = ['color' => $element['color']];
}
                        
                        // Fix text element color format
                        if ($element['type'] === 'video' && !empty($element['resource']['src'])) {
                            $src = $element['resource']['src'];
                            $element['resource']['src'] = wsi_fix_asset_url($src);
                            
                            // Fix poster image for videos
                            if (!empty($element['resource']['poster'])) {
                                $element['resource']['poster'] = wsi_fix_asset_url($element['resource']['poster']);
                            }
                        }
                        
                        // Fix backgroundColor format for elements
                        if (isset($element['backgroundColor'])) {
                            // If it's a string, convert to object format
                            if (is_string($element['backgroundColor'])) {
                                $element['backgroundColor'] = ['color' => $element['backgroundColor']];
                            }
                            // If it's not a string but has 'color' as a string, leave it
                            else if (isset($element['backgroundColor']['color']) && is_string($element['backgroundColor']['color'])) {
                                // Already correct format
                            }
                            // Complex case: gradient stops
                            else if (isset($element['backgroundColor']['type']) && $element['backgroundColor']['type'] === 'linear' && isset($element['backgroundColor']['stops'])) {
                                // Ensure each stop has properly formatted color
                                if (!empty($element['backgroundColor']['stops']) && is_array($element['backgroundColor']['stops'])) {
                                    foreach ($element['backgroundColor']['stops'] as &$stop) {
                                        if (isset($stop['color']) && is_string($stop['color'])) {
                                            // Already in the correct format
                                        } else if (isset($stop['color']) && !is_array($stop['color'])) {
                                            $stop['color'] = ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Re-encode the fixed JSON
        $story_json = wp_json_encode($story_data);
    }

    // --- Prepare Post Data --- 
    $post_data = [
        'post_title'    => sanitize_text_field( $post_title ),
        'post_content'  => $html_content, // wp_kses_post might be too strict here initially? Let GWS handle it.
        'post_content_filtered' => $story_json, // The crucial JSON data for the editor
        'post_status'   => 'draft',        // Start as draft for review
        'post_type'     => 'web-story',    // Google Web Stories post type
        'post_author'   => get_current_user_id(),
        // Add other meta or taxonomy if needed later
    ];

    // Disable KSES filters temporarily for post_content, as it might strip essential AMP tags
    // The Google Stories plugin likely handles sanitization appropriately for its content.
    kses_remove_filters();
    $post_id = wp_insert_post( $post_data, true ); // Second param true to return WP_Error on failure
    kses_init_filters(); // Re-enable KSES filters

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error(
            'post_creation_failed',
            __( 'Failed to create the web story post:', 'web-story-importer' ) . ' ' . $post_id->get_error_message(),
            $post_id->get_error_data()
        );
    }

    if ( $post_id === 0 ) {
         return new WP_Error( 'post_creation_failed_zero', __( 'Post creation returned ID 0, indicating failure.', 'web-story-importer' ) );
    }

    // Optional: Add a meta field to indicate this story was imported by this plugin
    update_post_meta($post_id, '_wsi_imported', true);

    return $post_id;
}

/**
 * Fix any remaining asset references in HTML content
 *
 * @param string $html_content The HTML content to process
 * @return string The processed HTML content
 */
function wsi_fix_asset_references($html_content) {
    // Find all assets/ references in the HTML
    preg_match_all('/[\'"]assets\/([^\'"]+)[\'"]/', $html_content, $matches);
    
    if (!empty($matches[0])) {
        $replacements = array();
        foreach ($matches[0] as $index => $match) {
            $asset_path = $matches[1][$index];
            $uploads_url = wsi_fix_asset_url('assets/' . $asset_path);
            $replacements[$match] = '"' . $uploads_url . '"';
        }
        
        $html_content = str_replace(array_keys($replacements), array_values($replacements), $html_content);
    }
    
    // Also replace script references
    $html_content = str_replace('src="assets/v0.js"', 'src="https://cdn.ampproject.org/v0.js"', $html_content);
    $html_content = str_replace('src="assets/amp-story-1.0.js"', 'src="https://cdn.ampproject.org/v0/amp-story-1.0.js"', $html_content);
    $html_content = str_replace('src="assets/amp-video-0.1.js"', 'src="https://cdn.ampproject.org/v0/amp-video-0.1.js"', $html_content);
    
    return $html_content;
}

/**
 * Fix an asset URL by looking up the corresponding media library URL
 *
 * @param string $asset_path The original asset path
 * @return string The media library URL
 */
function wsi_fix_asset_url($asset_path) {
    global $wpdb;
    
    // Clean up the asset path
    $asset_path = trim($asset_path);
    
    // If it's already a full URL to the uploads directory, return it
    $uploads_url = wp_get_upload_dir()['baseurl'];
    if (strpos($asset_path, $uploads_url) === 0) {
        return $asset_path;
    }
    
    // Get filename from path
    $filename = basename($asset_path);
    
    // Remove query strings if present
    if (strpos($filename, '?') !== false) {
        $filename = substr($filename, 0, strpos($filename, '?'));
    }
    
    // First try exact match on post_title which often contains the filename
    $attachment = $wpdb->get_row($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = %s LIMIT 1",
        $filename
    ));
    
    // If not found, try with guid which contains the full URL
    if (!$attachment) {
        $attachment = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
            '%/' . $wpdb->esc_like($filename)
        ));
    }
    
    // If still not found, try with post_name (slug)
    if (!$attachment) {
        // Remove extension for post_name comparison
        $filename_no_ext = pathinfo($filename, PATHINFO_FILENAME);
        $attachment = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_name = %s LIMIT 1",
            $filename_no_ext
        ));
    }
    
    // If still not found, try a broader search
    if (!$attachment) {
        $attachment = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND (post_name LIKE %s OR post_title LIKE %s) LIMIT 1",
            '%' . $wpdb->esc_like($filename_no_ext) . '%',
            '%' . $wpdb->esc_like($filename_no_ext) . '%'
        ));
    }
    
    if ($attachment) {
        return wp_get_attachment_url($attachment->ID);
    }
    
    // Fallback to the original path
    return $asset_path;
}

/**
 * Import assets from a URL based on the HTML content.
 *
 * @param string $html_content The HTML content of the story.
 * @param string $base_url The base URL of the story.
 * @return array An array of asset paths and their WordPress media IDs.
 */

/**
 * Import a remote asset into the WordPress media library.
 *
 * @param string $url The URL of the asset to import.
 * @return int|false The attachment ID if successful, false otherwise.
 */
function wsi_import_remote_asset($url) {
    // Check if this URL has already been imported
    $existing_attachment = wsi_get_attachment_by_url($url);
    if ($existing_attachment) {
        return $existing_attachment;
    }
    
    // Get file info
    $file_info = wp_check_filetype(basename($url));
    if (empty($file_info['ext'])) {
        // Try to determine extension from URL
        $parsed_url = parse_url($url);
        $path_parts = pathinfo($parsed_url['path']);
        if (!empty($path_parts['extension'])) {
            $file_info = wp_check_filetype('file.' . $path_parts['extension']);
        }
    }
    
    // Download the file
    $temp_file = download_url($url);
    
    if (is_wp_error($temp_file)) {
        return false;
    }
    
    // Prepare the file array for wp_handle_sideload
    $file_array = array(
        'name'     => basename($url),
        'tmp_name' => $temp_file,
        'error'    => 0,
        'size'     => filesize($temp_file),
    );
    
    // Move the temporary file into the uploads directory
    $file = wp_handle_sideload(
        $file_array,
        array('test_form' => false, 'test_size' => true)
    );
    
    if (isset($file['error'])) {
        @unlink($temp_file);
        return false;
    }
    
    // Insert the attachment
    $attachment = array(
        'guid'           => $file['url'],
        'post_mime_type' => $file['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($url)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    
    $attachment_id = wp_insert_attachment($attachment, $file['file']);
    
    if (!is_wp_error($attachment_id)) {
        // Generate metadata for the attachment
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        return $attachment_id;
    }
    
    return false;
}

/**
 * Get an attachment ID by URL.
 *
 * @param string $url The URL to check.
 * @return int|false The attachment ID if found, false otherwise.
 */
function wsi_get_attachment_by_url($url) {
    global $wpdb;
    
    // Try to find attachment by guid first
    $attachment = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE guid = %s AND post_type = 'attachment'",
        $url
    ));
    
    if (!empty($attachment[0])) {
        return (int) $attachment[0];
    }
    
    // Try to find by URL in post meta
    $attachment_id = attachment_url_to_postid($url);
    if ($attachment_id) {
        return $attachment_id;
    }
    
    return false;
}

/**
 * Delete a directory and all its contents.
 *
 * @param string $dir_path The path to the directory.
 * @return bool True if successful, false otherwise.
 */
function wsi_delete_directory($dir_path) {
    if (!is_dir($dir_path)) {
        return false;
    }
    
    $files = array_diff(scandir($dir_path), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir_path . '/' . $file;
        
        if (is_dir($path)) {
            wsi_delete_directory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir_path);
}

/**
 * Get all files with specific extensions from a directory.
 *
 * @param string $dir_path The path to the directory.
 * @param array $extensions An array of file extensions to look for.
 * @return array An array of file paths.
 */
function wsi_get_directory_files($dir_path, $extensions = array()) {
    $files = array();
    
    if (!is_dir($dir_path)) {
        return $files;
    }
    
    $dir_iterator = new RecursiveDirectoryIterator($dir_path);
    $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $extension = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
            
            if (empty($extensions) || in_array($extension, $extensions)) {
                $files[] = $file->getPathname();
            }
        }
    }
    
    return $files;
}

/**
 * Creates a new 'web-story' post using the processed content.
 *
 * @param string $html_content The processed HTML content (for post_content).
 * @param string $story_json   The JSON string for the story structure (for post_content_filtered).
 * @param string $original_filename The path to the original uploaded HTML file (used for title fallback).
 * @return int|WP_Error The new post ID on success, or WP_Error on failure.
 */
function wsi_create_web_story_post( $html_content, $story_json, $original_filename ) {
    if ( ! function_exists( 'wp_insert_post' ) ) {
        return new WP_Error( 'missing_wp_insert_post', __( 'WordPress function wp_insert_post is not available.', 'web-story-importer' ) );
    }

    // --- Determine Post Title --- 
    $post_title = __( 'Imported Web Story', 'web-story-importer' ); // Default title

    // Try to parse title from HTML content
    if ( ! empty( $html_content ) && preg_match( '/\<title\>(.*?)\<\/title\>/is', $html_content, $matches ) ) {
        $html_title = trim( strip_tags( $matches[1] ) );
        if ( ! empty( $html_title ) ) {
            $post_title = $html_title;
        }
    }
    // Fallback to filename if title couldn't be parsed or was empty
    if ( $post_title === __( 'Imported Web Story', 'web-story-importer' ) && ! empty( $original_filename ) ) {
        $filename = basename( $original_filename );
        // Remove extension
        $filename_without_ext = pathinfo( $filename, PATHINFO_FILENAME );
        if ( ! empty( $filename_without_ext ) ) {
            // Simple sanitization: replace underscores/hyphens with spaces, capitalize
            $post_title = ucwords( str_replace( ['_', '-'], ' ', $filename_without_ext ) );
        }
    }
    
    // Fix any remaining asset references in HTML content
    $html_content = wsi_fix_asset_references($html_content);
    
    // Fix JSON structure and references
    $story_data = json_decode($story_json, true);
    if (json_last_error() === JSON_ERROR_NONE && !empty($story_data) && is_array($story_data)) {
        // Ensure version is updated
        $story_data['version'] = 47;
        
        // Ensure title is set
        if (empty($story_data['title'])) {
            $story_data['title'] = $post_title;
        }
        
        // Fix asset references in pages and elements
        if (!empty($story_data['pages'])) {
            foreach ($story_data['pages'] as &$page) {
                // First pass - standardize backgroundColor format for all pages
                if (isset($page['backgroundColor'])) {
                    // Case 1: If it's a simple string (like 'black' or '#000000')
                    if (is_string($page['backgroundColor'])) {
                        $page['backgroundColor'] = ['color' => $page['backgroundColor']];
                    }
                    // Case 2: If it has a 'color' key that's a string, leave it as is
                    else if (isset($page['backgroundColor']['color']) && is_string($page['backgroundColor']['color'])) {
                        // Nothing to do, format is already correct
                    }
                    // Case 3: If it has a 'color' key that's not a string and not an array
                    else if (isset($page['backgroundColor']['color']) && !is_array($page['backgroundColor']['color']) && !is_string($page['backgroundColor']['color'])) {
                        $page['backgroundColor']['color'] = '#ffffff';
                    }
                    // Case 4: If it doesn't have a 'color' key at all
                    else if (!isset($page['backgroundColor']['color'])) {
                        $page['backgroundColor'] = ['color' => '#ffffff'];
                    }
                } else {
                    // If backgroundColor is not set at all, provide a default
                    $page['backgroundColor'] = ['color' => '#ffffff'];
                }
                
                // Process elements
                if (!empty($page['elements'])) {
                    foreach ($page['elements'] as &$element) {
                        // Fix image asset references
                        if ($element['type'] === 'image' && !empty($element['resource']['src'])) {
                            $src = $element['resource']['src'];
                            if (strpos($src, 'assets/') === 0) {
                                // Try to find a matching uploaded asset
                                $element['resource']['src'] = wsi_fix_asset_url($src);
                            }
                        }
                        
                        // Fix text element color format
                        if ($element['type'] === 'text' && isset($element['color']) && is_string($element['color'])) {
                            $element['color'] = ['color' => $element['color']];
                        }
                        
                        // Fix backgroundColor format for elements
                        if (isset($element['backgroundColor'])) {
                            // If it's a string, convert to object format
                            if (is_string($element['backgroundColor'])) {
                                $element['backgroundColor'] = ['color' => $element['backgroundColor']];
                            }
                            // If it's not a string but has 'color' as a string, leave it
                            else if (isset($element['backgroundColor']['color']) && is_string($element['backgroundColor']['color'])) {
                                // Already correct format
                            }
                            // Complex case: gradient stops
                            else if (isset($element['backgroundColor']['type']) && $element['backgroundColor']['type'] === 'linear' && isset($element['backgroundColor']['stops'])) {
                                // Ensure each stop has properly formatted color
                                if (!empty($element['backgroundColor']['stops']) && is_array($element['backgroundColor']['stops'])) {
                                    foreach ($element['backgroundColor']['stops'] as &$stop) {
                                        if (isset($stop['color']) && is_string($stop['color'])) {
                                            // Already in the correct format
                                        } else if (isset($stop['color']) && !is_array($stop['color'])) {
                                            $stop['color'] = ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Re-encode the fixed JSON
        $story_json = wp_json_encode($story_data);
    }

    // --- Prepare Post Data --- 
    $post_data = [
        'post_title'    => sanitize_text_field( $post_title ),
        'post_content'  => $html_content, // wp_kses_post might be too strict here initially? Let GWS handle it.
        'post_content_filtered' => $story_json, // The crucial JSON data for the editor
        'post_status'   => 'draft',        // Start as draft for review
        'post_type'     => 'web-story',    // Google Web Stories post type
        'post_author'   => get_current_user_id(),
        // Add other meta or taxonomy if needed later
    ];

    // Disable KSES filters temporarily for post_content, as it might strip essential AMP tags
    // The Google Stories plugin likely handles sanitization appropriately for its content.
    kses_remove_filters();
    $post_id = wp_insert_post( $post_data, true ); // Second param true to return WP_Error on failure
    kses_init_filters(); // Re-enable KSES filters

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error(
            'post_creation_failed',
            __( 'Failed to create the web story post:', 'web-story-importer' ) . ' ' . $post_id->get_error_message(),
            $post_id->get_error_data()
        );
    }

    if ( $post_id === 0 ) {
         return new WP_Error( 'post_creation_failed_zero', __( 'Post creation returned ID 0, indicating failure.', 'web-story-importer' ) );
    }

    // Optional: Add a meta field to indicate this story was imported by this plugin
    update_post_meta($post_id, '_wsi_imported', true);

    return $post_id;
}

/**
 * Log a new imported story to the database.
 *
 * @param string $story_title The title of the imported story.
 * @param string $original_file The original file path or URL.
 * @param int $post_id The WordPress post ID.
 * @param string $status The status of the import (completed, error, processing).
 * @param string $messages Any error or info messages.
 * @return int|false The ID of the inserted record, or false on failure.
 */
function wsi_log_imported_story($story_title, $original_file, $post_id, $status = 'completed', $messages = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'story_title'   => $story_title,
            'original_file' => $original_file,
            'post_id'       => $post_id,
            'import_date'   => current_time('mysql'),
            'status'        => $status,
            'messages'      => $messages
        ),
        array('%s', '%s', '%d', '%s', '%s', '%s')
    );
    
    if ($result) {
        return $wpdb->insert_id;
    }
    
    return false;
}

/**
 * Update the status of an imported story.
 *
 * @param int $id The record ID.
 * @param string $status The new status.
 * @param string $messages Any additional messages.
 * @return bool Whether the update was successful.
 */
function wsi_update_story_status($id, $status, $messages = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    
    $data = array('status' => $status);
    $where = array('id' => $id);
    $format = array('%s');
    $where_format = array('%d');
    
    if (!empty($messages)) {
        $data['messages'] = $messages;
        $format[] = '%s';
    }
    
    $result = $wpdb->update($table_name, $data, $where, $format, $where_format);
    
    return $result !== false;
}

/**
 * Get all imported stories with pagination.
 *
 * @param int $page The page number.
 * @param int $per_page Number of items per page.
 * @return array An array of imported stories and pagination info.
 */
function wsi_get_imported_stories($page = 1, $per_page = 10) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    
    // Calculate offset
    $offset = ($page - 1) * $per_page;
    
    // Get total count
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    // Get stories for current page
    $stories = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY import_date DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );
    
    // Calculate pagination info
    $total_pages = ceil($total / $per_page);
    
    return array(
        'stories'      => $stories,
        'total'        => (int) $total,
        'total_pages'  => $total_pages,
        'current_page' => $page,
        'per_page'     => $per_page
    );
}

/**
 * Get a single imported story by ID.
 *
 * @param int $id The record ID.
 * @return object|null The story record, or null if not found.
 */
function wsi_get_imported_story($id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        )
    );
}

/**
 * Delete an imported story record.
 *
 * @param int $id The record ID.
 * @return bool Whether the deletion was successful.
 */
function wsi_delete_imported_story($id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    
    return $wpdb->delete(
        $table_name,
        array('id' => $id),
        array('%d')
    ) !== false;
}

/**
 * Import a Web Story from a ZIP file.
 *
 * @param string $file_path Path to the ZIP file.
 * @return int|WP_Error Post ID on success, WP_Error on failure.
 */
function wsi_import_web_story( $file_path ) {
    // Check if the file exists.
    if ( ! file_exists( $file_path ) ) {
        return new WP_Error( 'file_not_found', __( 'ZIP file not found.', 'web-story-importer' ) );
    }

    // Create a temporary directory.
    $upload_dir = wp_upload_dir();
    $temp_dir   = $upload_dir['basedir'] . '/web-story-importer-temp-' . time();

    // Create the directory if it doesn't exist.
    if ( ! is_dir( $temp_dir ) ) {
        wp_mkdir_p( $temp_dir );
    }

    // Extract ZIP file to temporary directory.
    $zip = new ZipArchive();
    if ( $zip->open( $file_path ) !== TRUE ) {
        return new WP_Error( 'zip_open_failed', __( 'Failed to open ZIP file.', 'web-story-importer' ) );
    }
    $zip->extractTo( $temp_dir );
    $zip->close();

    // Find the main HTML file.
    $html_file = wsi_find_main_html_file( $temp_dir );
    if ( ! $html_file ) {
        return new WP_Error( 'no_html_file', __( 'No HTML file found in ZIP file.', 'web-story-importer' ) );
    }

    // Read the HTML content.
    $html_content = file_get_contents( $html_file );
    if ( ! $html_content ) {
        return new WP_Error( 'html_read_failed', __( 'Failed to read HTML file.', 'web-story-importer' ) );
    }

    // Extract and prepare assets from HTML.
    $uploads_url = $upload_dir['baseurl'];
    $uploads_dir = $upload_dir['basedir'];

    // Process and upload assets.
    $html_content = wsi_process_assets( $html_content, $temp_dir, $uploads_dir, $uploads_url );

    // Create a new Web Story post.
    $post_id = wsi_create_web_story_post( $html_content );

    // Create the story content and metadata from HTML.
    $story_data = wsi_create_story_content( $html_content, $post_id );
    
    // DEBUG: Log the story data to a file
    $debug_log_file = $upload_dir['basedir'] . '/wsi-debug-log-' . time() . '.json';
    file_put_contents($debug_log_file, json_encode($story_data, JSON_PRETTY_PRINT));
    
    // Clean up temporary directory.
    wsi_remove_directory( $temp_dir );

    return $post_id;
}

/**
 * Import assets from a directory into the WordPress media library.
 *
 * @param string $assets_dir The path to the assets directory.
 * @return array An array of asset paths and their WordPress media IDs.
 */
function wsi_import_assets($assets_dir) {
    $imported_assets = array();
    
    if (!is_dir($assets_dir)) {
        return $imported_assets;
    }
    
    // Get all files from the assets directory
    $files = wsi_get_directory_files($assets_dir);
    
    foreach ($files as $file_path) {
        // Skip non-media files
        $file_info = wp_check_filetype(basename($file_path));
        if (empty($file_info['type'])) {
            continue;
        }
        
        // Prepare the file array for wp_handle_sideload
        $file_array = array(
            'name'     => basename($file_path),
            'tmp_name' => $file_path,
            'error'    => 0,
            'size'     => filesize($file_path),
        );
        
        // Create a copy for sideloading
        $temp_file = wp_tempnam(basename($file_path));
        copy($file_path, $temp_file);
        $file_array['tmp_name'] = $temp_file;
        
        // Move the temporary file into the uploads directory
        $file = wp_handle_sideload(
            $file_array,
            array('test_form' => false, 'test_size' => true)
        );
        
        if (isset($file['error'])) {
            @unlink($temp_file);
            continue;
        }
        
        // Insert the attachment
        $attachment = array(
            'guid'           => $file['url'],
            'post_mime_type' => $file['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        
        $attachment_id = wp_insert_attachment($attachment, $file['file']);
        
        if (!is_wp_error($attachment_id)) {
            // Generate metadata for the attachment
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $file['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
            
            // Store the relative path and attachment ID
            $relative_path = 'assets/' . basename($file_path);
            $imported_assets[$relative_path] = $attachment_id;
        }
        
        @unlink($temp_file);
    }
    
    return $imported_assets;
}

/**
 * Parses a single page element into a Web Story page structure.
 *
 * @param DOMElement $page_element The page DOM element.
 * @param DOMXPath $xpath The DOMXPath object.
 * @param int $page_index The index of the page.
 * @return array Parsed page data.
 */
function wsi_parse_page( $page_element, $xpath, $page_index ) {
    // Define standard Web Story page dimensions
    define('WSI_PAGE_WIDTH', 412);
    define('WSI_PAGE_HEIGHT', 732);

    $page_id = $page_element->getAttribute( 'id' );
    if ( ! $page_id ) {
        $page_id = 'page-' . $page_index;
    }
    
    // Basic page structure
    $page_data = [
        'id' => $page_id,
        'elements' => [],
        'backgroundColor' => ['color' => '#000000'], // Default black for AMP stories
        'animations' => [],
        'backgroundAudio' => [
            'loop' => false,
            'resource' => null
        ]
    ];
    
    // Check for background color
    $bg_color = $page_element->getAttribute( 'background-color' );
    if ( $bg_color ) {
        $page_data['backgroundColor']['color'] = $bg_color;
    }
    
    $element_id_counter = 1;
    
    // Process the fill layer first (background)
    $fill_layers = $xpath->query( './/amp-story-grid-layer[@template="fill"]', $page_element );

    // Check if the query returned a valid NodeList
    if ( $fill_layers && $fill_layers->length > 0 ) {
        foreach ( $fill_layers as $layer ) {
            // Add check: Ensure $layer is a DOMElement
            if ( !$layer instanceof DOMElement ) {
                continue;
            }

            // Look for background images
            $bg_images = $xpath->query( './/amp-img', $layer );
            // Check if the query returned a valid NodeList
            if ( $bg_images && $bg_images->length > 0 ) {
                foreach ( $bg_images as $bg_image ) {
                    // Add check: Ensure $bg_image is a DOMElement before calling methods on it
                    if ( !$bg_image instanceof DOMElement ) {
                        continue;
                    }

                    $src = $bg_image->getAttribute( 'src' );
                    if ( $src ) {
                        $src_url = esc_url( $src );
                        $attachment_id = attachment_url_to_postid( $src_url );
                        
                        $width = intval( $bg_image->getAttribute( 'width' ) ) ?: 1080;
                        $height = intval( $bg_image->getAttribute( 'height' ) ) ?: 1920;
                        
                        // Add as background image element
                        $page_data['elements'][] = [
                            'id' => $page_id . '-bg-' . $element_id_counter++,
                            'type' => 'image',
                            'x' => 0,
                            'y' => 0,
                            'width' => WSI_PAGE_WIDTH,  // Use absolute width
                            'height' => WSI_PAGE_HEIGHT, // Use absolute height
                            'resource' => [
                                'id' => $attachment_id ? $attachment_id : 0,
                                'src' => $src_url,
                                'alt' => esc_attr( $bg_image->getAttribute('alt') ),
                                'mimeType' => 'image/' . strtolower(pathinfo($src, PATHINFO_EXTENSION)), // Basic guess
                                'width' => $width,
                                'height' => $height,
                            ],
                            'scale' => 100,
                            'focalX' => 50,
                            'focalY' => 50,
                            'isBackground' => true
                        ];
                    }
                }
            }
        }
    }
    
    // Look for gradient overlay divs
    $gradients = $xpath->query( './/div[contains(@class, "overlay-gradient")]', $page_element );
    if ( $gradients->length > 0 ) {
        // Add a gradient overlay element using absolute coordinates
        $gradient_height_ratio = 0.7; // Covers bottom 70%
        $gradient_y_ratio = 1.0 - $gradient_height_ratio; // Starts at 30% from top
        $page_data['elements'][] = [
            'id' => $page_id . '-gradient-' . $element_id_counter++,
            'type' => 'shape',
            'x' => 0,
            'y' => round(WSI_PAGE_HEIGHT * $gradient_y_ratio), // Absolute Y
            'width' => WSI_PAGE_WIDTH,                       // Absolute width
            'height' => round(WSI_PAGE_HEIGHT * $gradient_height_ratio), // Absolute height
            'mask' => [
                'type' => 'rectangle'
            ],
            'opacity' => 100,
            'backgroundColor' => [
                'type' => 'linear',
                'stops' => [
                    [
                        'color' => [
                            'r' => 0, 
                            'g' => 0, 
                            'b' => 0, 
                            'a' => 0
                        ],
                        'position' => 0
                    ],
                    [
                        'color' => [
                            'r' => 0, 
                            'g' => 0, 
                            'b' => 0, 
                            'a' => 0.5
                        ],
                        'position' => 0.5
                    ],
                    [
                        'color' => [
                            'r' => 0, 
                            'g' => 0, 
                            'b' => 0, 
                            'a' => 0.8
                        ],
                        'position' => 1
                    ]
                ],
                'rotation' => 180
            ]
        ];
    }
    
    // Process any other images that aren't in the fill layer
    $content_images = $xpath->query( './/amp-story-grid-layer[not(@template="fill")]//amp-img', $page_element );
    
    if ( $content_images && $content_images->length > 0 ) {
        foreach ( $content_images as $index => $content_image ) {
            if ( !$content_image instanceof DOMElement ) {
                continue; // Skip invalid nodes
            }

            $src = $content_image->getAttribute( 'src' );
            if ( $src ) {
                $src_url = esc_url( $src );
                $attachment_id = attachment_url_to_postid( $src_url );
                
                // Get image dimensions
                $width = intval( $content_image->getAttribute( 'width' ) );
                $height = intval( $content_image->getAttribute( 'height' ) );
                
                // Only add if it's not already used as background
                $is_duplicate = false;
                foreach ( $page_data['elements'] as $element ) {
                    if ( $element['type'] === 'image' && isset($element['resource']['src']) && $element['resource']['src'] === $src_url ) {
                        $is_duplicate = true;
                        break;
                    }
                }
                
                if ( !$is_duplicate ) {
                    // Calculate aspect ratio
                    $aspect = ($width && $height) ? $width / $height : 1.5;
                    $img_width = 0.8 * WSI_PAGE_WIDTH; // 80% of screen width
                    $img_height = $img_width / $aspect;
                    
                    // Add as content image
                    $page_data['elements'][] = [
                        'id' => $page_id . '-img-' . $element_id_counter++,
                        'type' => 'image',
                        'x' => 0.1 * WSI_PAGE_WIDTH, // 10% from left
                        'y' => 0.4 * WSI_PAGE_HEIGHT, // 40% from top
                        'width' => round($img_width),
                        'height' => round($img_height),
                        'resource' => [
                            'id' => $attachment_id ? $attachment_id : 0,
                            'src' => $src_url,
                            'alt' => esc_attr( $content_image->getAttribute('alt') ),
                            'mimeType' => 'image/' . strtolower(pathinfo($src, PATHINFO_EXTENSION)), // Basic guess
                            'width' => $width ?: 400,
                            'height' => $height ?: 300,
                        ],
                        'scale' => 100,
                        'focalX' => 50,
                        'focalY' => 50,
                    ];
                }
            }
        }
    }
    
    // Process each text element directly based on their element type (h1, h2, p) 
    // and maintain exact heading structure
    
    // First check for h1 elements - usually on cover page
    $h1_elements = $xpath->query( './/h1', $page_element );
    foreach ( $h1_elements as $index => $text_element ) {
        if ( trim( $text_element->textContent ) === '' ) {
            continue;
        }
        
        // Get class for styling
        $class_name = $text_element->getAttribute('class');
        $font_size = 56; // Default h1 size
        $font_weight = 700;
        
        // If it has page-title class, apply specific styling
        if ( $class_name && strpos( $class_name, 'page-title' ) !== false ) {
            $font_size = 56;
        }
        
        // Position for h1 elements - usually at bottom of page on cover
        $y_position = 0.8 * WSI_PAGE_HEIGHT + (0.03 * $index * WSI_PAGE_HEIGHT);
            
        // Always use h1 for the original h1 elements
        $page_data['elements'][] = [
            'id' => $page_id . '-h1-' . $element_id_counter++,
            'type' => 'text',
            'tagName' => 'h1',
            'content' => $text_element->textContent,
            'x' => 0.05 * WSI_PAGE_WIDTH, // 5% from left
            'y' => $y_position,
            'width' => 0.9 * WSI_PAGE_WIDTH, // 90% width 
            'height' => 0.1 * WSI_PAGE_HEIGHT,
            'font' => [
                'family' => 'Roboto',
                'service' => 'fonts.google.com',
                'fallbacks' => ['sans-serif'],
                'weight' => $font_weight
            ],
            'fontSize' => $font_size,
            'lineHeight' => 1.3,
            'textAlign' => 'left',
            'color' => ['color' => '#FFFFFF']
        ];
    }
    
    // Then check for h2 elements - typically used for page titles
    $h2_elements = $xpath->query( './/h2', $page_element );
    foreach ( $h2_elements as $index => $text_element ) {
        if ( trim( $text_element->textContent ) === '' ) {
            continue;
        }
        
        // Get class for styling
        $class_name = $text_element->getAttribute('class');
        $font_size = 44; // Default h2 size 
        $font_weight = 700;
        
        // If it has page-title class, apply specific styling
        if ( $class_name && strpos( $class_name, 'page-title' ) !== false ) {
            $font_size = 44;
        }
        
        // Position for h2 elements - usually at bottom of page
        $y_position = 0.8 * WSI_PAGE_HEIGHT + (0.03 * $index * WSI_PAGE_HEIGHT);
            
        // Always use h2 for the original h2 elements
        $page_data['elements'][] = [
            'id' => $page_id . '-h2-' . $element_id_counter++,
            'type' => 'text',
            'tagName' => 'h2', 
            'content' => $text_element->textContent,
            'x' => 0.05 * WSI_PAGE_WIDTH, // 5% from left
            'y' => $y_position,
            'width' => 0.9 * WSI_PAGE_WIDTH, // 90% width
            'height' => 0.1 * WSI_PAGE_HEIGHT,
            'font' => [
                'family' => 'Roboto',
                'service' => 'fonts.google.com',
                'fallbacks' => ['sans-serif'],
                'weight' => $font_weight
            ],
            'fontSize' => $font_size,
            'lineHeight' => 1.3,
            'textAlign' => 'left',
            'color' => ['color' => '#FFFFFF']
        ];
    }
    
    // Finally check for p elements - usually descriptive text 
    $p_elements = $xpath->query( './/p', $page_element );
    foreach ( $p_elements as $index => $text_element ) {
        if ( trim( $text_element->textContent ) === '' ) {
            continue;
        }
        
        // Get class for styling
        $class_name = $text_element->getAttribute('class');
        $font_size = 24; // Default paragraph size
        $font_weight = 400;
        
        // If it has page-description class, apply specific styling
        if ( $class_name && strpos( $class_name, 'page-description' ) !== false ) {
            $font_size = 24;
        }
        
        // Position text below any headings with appropriate spacing
        // For paragraphs, position them below any headings
        $h_elements_count = $h1_elements->length + $h2_elements->length;
        $y_position = 0.88 * WSI_PAGE_HEIGHT + (0.03 * $index * WSI_PAGE_HEIGHT);
        
        // Always use p for the original p elements
        $page_data['elements'][] = [
            'id' => $page_id . '-p-' . $element_id_counter++,
            'type' => 'text',
            'tagName' => 'p',
            'content' => $text_element->textContent,
            'x' => 0.05 * WSI_PAGE_WIDTH, // 5% from left
            'y' => $y_position,
            'width' => 0.9 * WSI_PAGE_WIDTH, // 90% width
            'height' => 0.1 * WSI_PAGE_HEIGHT,
            'font' => [
                'family' => 'Roboto',
                'service' => 'fonts.google.com', // Assume Google Fonts for now
                'fallbacks' => ['sans-serif'],
                'weight' => $font_weight
            ],
            'fontSize' => $font_size,
            'lineHeight' => 1.3,
            'textAlign' => 'left',
            'color' => ['color' => '#FFFFFF']
        ];
    }
    
    return $page_data;
}

/**
 * Import assets from a URL based on the HTML content.
 *
 * @param string $html_content The HTML content of the story.
 * @param string $base_url The base URL of the story.
 * @return array An array of asset paths and their WordPress media IDs.
 */
function wsi_import_assets_from_url($html_content, $base_url) {
    // Create a DOMDocument to parse the HTML
    $dom = new DOMDocument();
    @$dom->loadHTML($html_content);
    
    $xpath = new DOMXPath($dom);
    
    // Find all images and videos
    $assets = array();
    $media_elements = $xpath->query('//img | //amp-img | //video | //amp-video');
    
    foreach ($media_elements as $media) {
        $src = $media->getAttribute('src') ?: $media->getAttribute('poster');
        
        if (empty($src)) {
            continue;
        }
        
        // Convert to absolute URL if needed
        if (strpos($src, 'http') !== 0) {
            $src = rtrim($base_url, '/') . '/' . ltrim($src, '/');
        }
        
        $assets[] = $src;
    }
    
    // Find background images in styles
    $style_elements = $xpath->query('//*[@style]');
    foreach ($style_elements as $element) {
        $style = $element->getAttribute('style');
        if (preg_match('/background-image\s*:\s*url\([\'"]?([^\'"]*)[\'"]?\)/i', $style, $matches)) {
            $bg_src = $matches[1];
            
            // Convert to absolute URL if needed
            if (strpos($bg_src, 'http') !== 0) {
                $bg_src = rtrim($base_url, '/') . '/' . ltrim($bg_src, '/');
            }
            
            $assets[] = $bg_src;
        }
    }
    
    // Import each asset
    $imported_assets = array();
    foreach (array_unique($assets) as $asset_url) {
        $attachment_id = wsi_import_remote_asset($asset_url);
        if ($attachment_id) {
            $imported_assets[$asset_url] = $attachment_id;
        }
    }
    
    return $imported_assets;
}