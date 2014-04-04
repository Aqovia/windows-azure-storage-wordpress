<?php
/**
 * windows-azure-storage-cache-control.php
 * 
 * Plugin extension enabling users to modify cache-control header for files in Azure container
 * 
 * Version: 2.0.3
 * 
 * Author: aQovia UK Ltd
 * 
 * Author URI: http://www.aqovia.com/
 * 
 * License: New BSD License (BSD)
 * 
 * Copyright (c) Microsoft Open Technologies, Inc.
 * All rights reserved. 
 * Redistribution and use in source and binary forms, with or without modification, 
 * are permitted provided that the following conditions are met: 
 * Redistributions of source code must retain the above copyright notice, this list 
 * of conditions and the following disclaimer. 
 * Redistributions in binary form must reproduce the above copyright notice, this 
 * list of conditions  and the following disclaimer in the documentation and/or 
 * other materials provided with the distribution. 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND 
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED 
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A  PARTICULAR PURPOSE ARE 
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR 
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES 
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS 
 * OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)  HOWEVER CAUSED AND ON ANY 
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING 
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN 
 * IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 * 
 * PHP Version 5
 * 
 * @category  WordPress_Plugin
 * @package   Windows_Azure_Storage_For_WordPress
 * @author    Microsoft Open Technologies, Inc. <msopentech@microsoft.com>
 * @copyright Microsoft Open Technologies, Inc.
 * @license   New BSD license, (http://www.opensource.org/licenses/bsd-license.php)
 * @link      http://www.microsoft.com
 */

use WindowsAzure\Blob\Models\SetBlobPropertiesOptions;

define(WP_AZURE_STORAGE_CACHE_CONTROL, 'windows_azure_storage_cache_control');
define(WP_AZURE_STORAGE_CACHE_CONTROL_FIELD, 'azure_storage_cache_control');

// Adds additional column for cache control
add_filter( 'manage_media_columns', 'column_headings' );
// Displays cache control column
add_action( 'manage_media_custom_column', 'column_cache_control', 10, 2 );   
/* // sorting would be possible if cache_control would be its own meta record (not nested inside windows_azure_storage_info)    
// Filter that register additional column for cache control as sortable
add_filter( 'manage_upload_sortable_columns', 'column_headings_sortable' );
// Prepares cache control meta value for sorting
add_filter( 'request', 'cache_control_column_orderby' );*/

// Enables editing of cache control property value
add_filter( 'attachment_fields_to_edit', 'attachment_cache_control_display', 10, 2 );
// Saves cache control property value
add_action( 'edit_attachment', 'attachment_cache_control_update' );

// Hacked hook to add new bulk action for cache control bulk update
add_action( 'admin_head-upload.php', 'add_bulk_actions_via_javascript' );
// Handler for executing bulk update of cache control
add_action( 'admin_action_bulk_cache_control', 'cache_control_action_handler' );

/**
 * Add column header for cache control
 * 
 * @param array $defaults array of existing column headings
 * 
 * @return array updated column header array
 */
function column_headings( $defaults ) {
    $defaults[WP_AZURE_STORAGE_CACHE_CONTROL] = 'Cache-control header';
    return $defaults;
}

/**
 * Register cache control columns as sortable
 * 
 * @param array $columns array of existing sortable columns
 * 
 * @return array updated sortable columns array
 */
function column_headings_sortable( $columns ) {
    $columns[WP_AZURE_STORAGE_CACHE_CONTROL] = 'cache_control';
    return $columns;
}

/**
 * Maps cache control meta key value for sorting
 * 
 * @param array $vars request vars
 * 
 * @return array updated request vars array
 */
function cache_control_column_orderby( $vars ) {
    if ( isset( $vars['orderby'] ) && 'cache_control' == $vars['orderby'] ) {
        $vars = array_merge( $vars, array(
            'meta_key' => 'cache_control', //windows_azure_storage_info
            'orderby' => 'meta_value_num'
        ) );
    }
    return $vars;
}

/**
 * Extracts blob name for given attachment
 * 
 * @param integer $id attachment ID
 * 
 * @return string blob name
 */
function getBlobName($id) {
    $att_url = wp_get_attachment_url( $id );
    $blobName = str_replace(WindowsAzureStorageUtil::getStorageUrlPrefix() . "/", '', $att_url);    
    return $blobName;
}

/**
 * Retrieves cache control property for given attachment from Azure container
 * 
 * @param integer $id attachment ID
 * 
 * @return string cache control property value
 */
function getCacheControlFromAzureByID($id) {
    $blobName = getBlobName($id);
    if (!empty($blobName)) {
        $container = WindowsAzureStorageUtil::getDefaultContainer();
        $blobRestProxy = WindowsAzureStorageUtil::getStorageClient();

        if (WindowsAzureStorageUtil::blobExists($container, $blobName)) {
            $prop_result = $blobRestProxy->getBlobProperties($container, $blobName);
            return $prop_result->getProperties()->getCacheControl();
        }
    }
    
    return null;
}

/**
 * Sets cache control property for given attachment on Azure container
 * 
 * @param integer $id attachment ID
 * @param string $cache_control new value for cache control
 * 
 * @return void
 */
function setCacheControlByID($id, $cache_control) {
    if (!is_null($cache_control)) {
        $blobName = getBlobName($id);
        if (!empty($blobName)) {
            $container = WindowsAzureStorageUtil::getDefaultContainer();
            $blobRestProxy = WindowsAzureStorageUtil::getStorageClient();

            if (WindowsAzureStorageUtil::blobExists($container, $blobName)) {
                $prop_result = $blobRestProxy->getBlobProperties($container, $blobName);
                $prop = $prop_result->getProperties();

                // needs SDK version with fix from: https://github.com/WindowsAzure/azure-sdk-for-php/issues/366
                // otherwise all other options have to be set manually from $prop
                $options = new SetBlobPropertiesOptions($prop);
                $options->setBlobCacheControl($cache_control);

                // avoids "(400) Bad Request: HTTP headers is not in the correct format" 
                // by clearing content length so it doesn't get added to sent headers (doesn't affect anything)
                // see: http://msdn.microsoft.com/library/azure/ee691966.aspx
                $options->setBlobContentLength(null);

                $blobRestProxy->setBlobProperties($container, $blobName, $options);
            }
        }
    }
}

/**
 * Retrieves cache-control header from local metadata if available or otherwise from the Azure container
 * 
 * @param integer $id attachment ID
 * 
 * @return string cache-control header
 */
function getCacheControlByID($id ) {
    $mediaInfo = get_post_meta($id, 'windows_azure_storage_info', true);
    
    if (!empty($mediaInfo)) {
        return isset($mediaInfo['cache_control']) ? $mediaInfo['cache_control'] : getCacheControlFromAzureByID($id);
    }
    
    return null;
}

/**
 * Column data for cache control
 * 
 * @param string $column_name column name
 * @param integer $id attachment ID
 * 
 * @return void
 */
function column_cache_control( $column_name, $id ) {    
    if( $column_name == WP_AZURE_STORAGE_CACHE_CONTROL) {
        $cache_control = getCacheControlByID($id);
        if (!is_null($cache_control)) {
            print($cache_control);
        }
    }
}

/**
 * Display file cache control property from Azure container
 * 
 * @param array $form_fields array of form fields
 * @param object $post post object
 * 
 * @return array updated form fields array
 */
function attachment_cache_control_display( $form_fields, $post ) {
    $mediaInfo = get_post_meta($post->ID, 'windows_azure_storage_info', true);    
    
    if (!empty($mediaInfo)) {
        $cache_control = getCacheControlByID($post->ID);
        $form_fields[WP_AZURE_STORAGE_CACHE_CONTROL_FIELD] = array(
            'value' => $cache_control,
            'label' => __( 'Cache-control header' ),
            'helps' => __( 'Cache control property value in Azure container' )
        );
    }
    
    return $form_fields;
}

/**
 * Update cache control property to local db and Azure container
 * 
 * @param integer $id attachment ID
 * 
 * @return void
 */
function cache_control_update( $id, $cache_control ) {
    // update local db
    $mediaInfo = get_post_meta($id, 'windows_azure_storage_info', true);
    // check if it was actually uploaded to Azure
    if (!empty($mediaInfo)) {
        $mediaInfo['cache_control'] = $cache_control;
        update_post_meta($id, 'windows_azure_storage_info', $mediaInfo);
        // update Azure container
        setCacheControlByID($id, $cache_control);
    }
}

/**
 * Update cache control property when editing file details
 * 
 * @param integer $id attachment ID
 * 
 * @return void
 */
function attachment_cache_control_update( $id ) {
    if ( isset( $_REQUEST['attachments'][$id][WP_AZURE_STORAGE_CACHE_CONTROL_FIELD] ) ) {
        $cache_control = $_REQUEST['attachments'][$id][WP_AZURE_STORAGE_CACHE_CONTROL_FIELD];
        cache_control_update($id, $cache_control);
    }
}

/**
 * Adds new bulk action for bulk cache control update
 * Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/
 * Also see http://www.skyverge.com/blog/add-custom-bulk-action
 * 
 * @return void
 */
function add_bulk_actions_via_javascript() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($){
            $('select[name^="action"] option:last-child').before('<option value="bulk_cache_control">Bulk update cache-control header</option>');
        });
    </script>
    <?php
}

/**
 * Handles the bulk actions POST
 * Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/
 * 
 * @return void
 */
function cache_control_action_handler() {
    check_admin_referer( 'bulk-media' );

    if (!empty( $_REQUEST['media'] ) && is_array( $_REQUEST['media'] ) ) {        
        $ids = implode( ',', array_map( 'intval', $_REQUEST['media'] ) );
        // Can't use wp_nonce_url() as it escapes HTML entities
        wp_redirect( add_query_arg( '_wpnonce', wp_create_nonce( 'wp-bulk-cache-control' ), admin_url( 'upload.php?page=wp-bulk-cache-control&goback=1&ids=' . $ids ) ) );
        exit();
    }    
}

/**
 * Displays custom page for entering new value for cache control and executes bulk update
 * Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/
 * 
 * @return void
 */
function bulk_cache_control_page() {
    if ( isset($_REQUEST['ids'] ) ) {
        $ids = $_REQUEST['ids'];
        
        if ( isset($_REQUEST['bulkAction'] ) && $_REQUEST['bulkAction'] == 'doUpdate') {
            $ids = explode(',', $ids);
            $cache_control = isset($_REQUEST['cache_control']) ? $_REQUEST['cache_control'] : '';
            foreach($ids as $id) {
                cache_control_update($id, $cache_control);
            }
            
            echo "<p>Bulk update of cache-control headers executed.</p>";
            echo '<a href="' . admin_url( 'upload.php' ) . '">Return to media library</a>';
        } else
        {
            ?>
            <form action="<?php echo admin_url( 'upload.php' ); ?>">
                <label for="cache_control">Enter new value for cache-control header:</label><br/>
                <input name="page" type="hidden" value="wp-bulk-cache-control" />
                <input name="bulkAction" type="hidden" value="doUpdate" />
                <input name="ids" type="hidden" value="<?php echo $ids; ?>" />
                <input name="cache_control" value="<?php echo WindowsAzureStorageUtil::getDefaultCacheControl(); ?>" size="30" /><br/>                
                <input class="button-primary button-large" type="submit" value="Bulk update" />
                <a class="button-primary button-large" href="<?php echo admin_url( 'upload.php' ); ?>">Go back</a>
            </form>
            <?php
        }
    }
}
?>