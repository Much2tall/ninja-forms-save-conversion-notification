<?php

/*
Plugin Name: Ninja Forms - Save Conversion Notification Tool
Plugin URI: http://ninjaforms.com/
Description: The Save Conversion Notification Tool is provided to inform users that their Saved records in Ninja Forms 2.9 will become uneditable when the installation is upgraded.
Version: 1.0
Author: The WP Ninjas
Author URI: http://ninjaforms.com

Copyright 2017 WP Ninjas.
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
    exit;

add_action( 'plugins_loaded', 'nfsc_do_or_die' );

/**
 * If Ninja Forms exists, enable our tool.
 * 
 * @return void
 */
function nfsc_do_or_die() {
    
    if ( class_exists( 'Ninja_Forms', false ) ) {
        // Listen for AJAX calls.
        add_action( 'wp_ajax_nfsc_bulk_email', 'nfsc_bulk_email' );
        // Enqueue our scripts.
        add_action( 'admin_enqueue_scripts', 'nfsc_admin_js' );
    }
}

/**
 * Register our scripts.
 * 
 * @return false/void
 */
function nfsc_admin_js() {
    global $pagenow, $typenow;
    if( $pagenow == 'edit.php' && $typenow == 'nf_sub' ) {
        if (! is_admin() )
            return false;
        
        wp_enqueue_script( 'nfsc-jbox', plugin_dir_url( __FILE__ ) . 'assets/js/jBox.min.js', array( 'jquery' ) );
        add_action( 'admin_footer-edit.php', 'nfsc_email_js' );
    }
}

/**
 * Output our Bulk Email Handler to the page.
 * 
 * @return false/void
 */
function nfsc_email_js() {
    if( ! isset($_REQUEST['form_id']) || ! $_REQUEST['form_id'] )
        return false;
    global $wpdb;
    $post_sql = "SELECT p.id FROM `" . $wpdb->prefix ."posts` AS p LEFT JOIN `" . $wpdb->prefix ."postmeta` as m ON p.id = m.post_id WHERE m.meta_key = '_form_id' AND m.meta_value = " . $_REQUEST['form_id'];
    $save_sql = "SELECT p.id FROM `" . $wpdb->prefix ."posts` AS p LEFT JOIN `" . $wpdb->prefix ."postmeta` as m ON p.id = m.post_id WHERE m.meta_key = '_action' AND m.meta_value = 'save' AND p.id IN(" . $post_sql . ")";
    $sql = "SELECT COUNT(DISTINCT(u.user_email)) FROM `" . $wpdb->prefix ."users` as u  LEFT JOIN `" . $wpdb->prefix ."posts` as p ON u.id = p.post_author WHERE p.id IN(" . $save_sql . ") AND u.user_email IS NOT NULL";
    $result = $wpdb->get_results( $sql, 'ARRAY_N' );
    if( intval( $result[0][0] ) < 1 )
        return false;
    
    ?>
        <script type="text/javascript">
            jQuery(document).ready(function() {
                // Create our Email Button.
                jQuery('<div>').text('<?php _e('Notify Users'); ?>').addClass('nfsc-bulk-email').appendTo("#wpbody-content");
                // Setup our modal.
                var title = '<?php _e('Composing Email to Users', 'ninja-forms'); ?>';
                var content = '<div><label for="nf-bulk-from"><?php _e('From Address:', 'ninja-forms'); ?></label><input type="text" name="from" id="nf-bulk-from" value="<?php echo( (get_option('admin_email')) ? get_option('admin_email') : '' ); ?>"/></div>' +
                    '<div><label for="nf-bulk-subject"><?php _e('Subject:', 'ninja-forms'); ?></label><input type="text" name="subject" id="nf-bulk-subject" /></div>' +
                    '<div><label for="nf-bulk-message"><?php _e('Message:', 'ninja-forms'); ?></label><textarea id="nf-bulk-message"><?php echo __('Hello,\n\nWe just wanted to let you know that we are upgrading our forms, and your saved information at [[PASTE FORM PAGE URL HERE]] could be lost if you do not return and complete it soon.\n\nSincerely,\n\nSupport', 'ninja-forms'); ?></textarea></div>' +
                    '<div id="nf-bulk-error"></div>'+
                    '<div id="nf-modal-send" class="modal-button"><?php _e('Send', 'ninja-forms'); ?></div><div id="nf-modal-cancel" class="modal-button"><?php _e('Cancel', 'ninja-forms'); ?></div>';
                var bulkBox = new jBox('Modal', {
                    width: 440,
                    height: 500,
                    attach: '.nfsc-bulk-email',
                    title: title,
                    content: content,
                    addClass: 'nfsc-composer',
                    closeButton: false,
                    closeOnClick: false,
                    closeOnEsc: false,
                    overlay: true,
                    onCreated: function(){
                        // Listen for clicks on our Send button.
                        document.getElementById('nf-modal-send').addEventListener('click', function(){
                            var emailFrom = document.getElementById('nf-bulk-from');
                            var errorBox = document.getElementById('nf-bulk-error');
                            // Check to make sure the from address is valid.
                            if( nfscCheckEmail( emailFrom ) ) {
                                var data = {
                                    form_id: '<?php echo($_REQUEST['form_id']) ?>',
                                    from: emailFrom.value,
                                    subject: document.getElementById('nf-bulk-subject').value,
                                    message: document.getElementById('nf-bulk-message').value
                                };
                                bulkBox.setTitle('<?php _e('Sending Emails...', 'ninja-forms'); ?>');
                                bulkBox.content[0].classList.add('loading');
                                data = JSON.stringify( data );
                                var payload = {
                                    action: 'nfsc_bulk_email',
                                    data: data
                                };
                                jQuery.ajax({
                                    type: "POST",
                                    url: ajaxurl + '?action=nfsc_bulk_email',
                                    data: payload,
                                    success: function( response ){
                                        bulkBox.setTitle(title);
                                        bulkBox.content[0].classList.remove('loading');
                                        bulkBox.close();
                                    },
                                    error: function( response ){
                                        errorBox.innerHTML('<?php _e('Oops. Something went wrong.', 'ninja-forms'); ?>');
                                    }
                                });
                            }
                            else {
                                errorBox.innerHTML = '<?php _e('Please enter a valid email address.', 'ninja-forms') ?>';
                                emailFrom.classList.add('nf-field-error');
                                // Listen for changes to the from address, so we can reset our error.
                                emailFrom.addEventListener('change', function() {
                                    emailFrom.classList.remove('nf-field-error');
                                    errorBox.innerHTML = '';
                                });
                            }
                        });
                        // Listen for clicks on our Cancel button
                        document.getElementById('nf-modal-cancel').addEventListener('click', function(){
                           bulkBox.close(); 
                        });
                    }
                });
            });
            function nfscCheckEmail( el ){
                var emailReg = /^.+@.+\..+/i;
                var val = el.value;
                if( 'undefined' === typeof( val ) )
                    return false;
                if( '' == val )
                    return false;
                if( ! emailReg.test( val ) )
                    return false;
                return true;
            }
        </script>
        <style type="text/css">
            .nfsc-composer {
                background-color: #fff;
                border-radius: 7px;
            }
            #jBox-overlay {
                background-color: #000;
                height: 100%;
                width: 100%;
                position: absolute;
                top: 0;
                left: 0;
                opacity: 0.6 !important;
            }
            .nfsc-composer label {
                display: block;
                font-size: 120%;
            }
            .nfsc-composer input, .nfsc-composer textarea {
                width: 100%;
                margin: 10px 0px;
            }
            .nfsc-composer textarea {
                min-height: 200px;
            }
            .nfsc-composer .jBox-title {
                text-align: center;
                font-size: 150%;
                padding: 30px 0px;
            }
            .nfsc-composer .jBox-content {
                padding: 10px 40px;
            }
            .nfsc-composer .modal-button {
                cursor: pointer;
                display: inline-block;
                width: 70px;
                height: 20px;
                padding: 5px 0px;
                text-align: center;
                border-radius: 3px;
            }
            .nfsc-composer #nf-modal-send {
                margin-left: 120px;
                background-color: #00a0d2;
                color: #fff;
            }
            .nfsc-composer #nf-modal-cancel {
                margin-left: 60px;
                background-color: #ccc;
            }
            .nfsc-composer #nf-bulk-error {
                color: #f00;
                font-weight: bold;
                padding: 10px;
                margin-bottom: 20px;
            }
            .nfsc-composer input.nf-field-error {
                border: 1px solid #f00;
            }
            .nfsc-composer .loading div {
                display: none !important;
            }
            .nfsc-composer .loading {
                background-color: rgba(208, 208, 208, 0.5);
                border-radius: 100%;
                animation: nf-scaleout 1.0s infinite ease-in-out; 
            }
            .nfsc-bulk-email {
                border: 1px solid #ccc;
                color: #0073aa;
                background-color: #f7f7f7;
                min-width: 100px;
                padding: 4px 8px;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
                text-align: center;
                border-radius: 2px;
                margin: 4px 2px;
                display: inline-block;
            }
            .nfsc-bulk-email:hover {
                background-color: #00a0d2;
                border-color: #008EC2;
                color: #fff;
            }
            @keyframes nf-scaleout {
                0% {
                transform: scale(0); }
                100% {
                transform: scale(1);
                opacity: 0; }
            }
        </style> 
    <?php
}

/**
 * Send a bulk email to users specified by the AJAX call.
 * 
 * @return bool
 */
function nfsc_bulk_email() {
    if( !isset($_POST['data'] ) )
        return false;
    $data = json_decode( stripslashes( $_POST['data'] ), TRUE );
    $form_id = intval( $data['form_id'] );
    $subject = empty( $data['subject'] ) ? __('(No subject)', 'ninja-forms') : $data['subject'];
    $headers = array();
    $headers[] = 'Content-Type: text/plain';
    $headers[] = 'charset=UTF-8';
    $headers[] = 'From: ' . $data['from'];
    global $wpdb;
    $post_sql = "SELECT p.id FROM `" . $wpdb->prefix ."posts` AS p LEFT JOIN `" . $wpdb->prefix ."postmeta` as m ON p.id = m.post_id WHERE m.meta_key = '_form_id' AND m.meta_value = " . $form_id;
    $save_sql = "SELECT p.id FROM `" . $wpdb->prefix ."posts` AS p LEFT JOIN `" . $wpdb->prefix ."postmeta` as m ON p.id = m.post_id WHERE m.meta_key = '_action' AND m.meta_value = 'save' AND p.id IN(" . $post_sql . ")";
    $sql = "SELECT DISTINCT(u.user_email) FROM `" . $wpdb->prefix ."users` as u  LEFT JOIN `" . $wpdb->prefix ."posts` as p ON u.id = p.post_author WHERE p.id IN(" . $save_sql . ") AND u.user_email IS NOT NULL";
    $result = $wpdb->get_results( $sql, 'ARRAY_A' );
    $to = array();
    foreach( $result as $row ) {
        array_push( $to, $row['user_email'] );
    }
    try {
        $sent = wp_mail( $to, $subject, $data['message'], $headers );
    } catch ( Exception $e ){
        $sent = false;
        $errors[ 'email_sent' ] = $e->getMessage();
    }
    echo( $sent );
    die();
}