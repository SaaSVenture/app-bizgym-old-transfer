<?php 
    /*
    Plugin Name: BizGym 2.0 Transfer Plugin
    Plugin URI: http://bizgym.com
    Description: Plugin for migrate all user data from Old 
    Author: BizGym @Devs
    Version: 1.0
    Author URI: http://www.bizgym.com
    */

    define( 'TRANSFER_ENDPOINT', 'http://bizgym.dev/transfer-old-user' );

    function transfer_actions() {
    	add_options_page("BizGym 2.0 Transfer", "BizGym 2.0 Transfer", 1, "BizGym_Transfer", "transfer_admin");
    }

    function transfer_admin() {
    	include('bizgym_transfer_admin.php');
    }

    // create table
    function transfer_create_table(){
        global $wpdb;
        global $charset_collate;

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $sql_create_table = "CREATE TABLE {$wpdb->prefix}transfers (
            id bigint(20) unsigned NOT NULL auto_increment,
            user_id bigint(20) unsigned NOT NULL default '0',
            email varchar(30) NOT NULL default '',
            reference_link text NOT NULL default '',
            transfer_date datetime NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (id)
        ) $charset_collate;";

        dbDelta($sql_create_table);
    }

    // drop table
    function transfer_drop_table() {
        global $wpdb;

        $sql = "DROP TABLE IF EXISTS {$wpdb->prefix}transfers";
        $wpdb->query($sql);
    }

    // endpoint
    function transfer_route()
    {
        add_rewrite_endpoint('sup3rs3cr3t', EP_PAGES);
    }
    add_action( 'init', 'transfer_route' );

    function transfer_action()
    {
        global $wpdb;

        $path = trim(rtrim($_SERVER['PATH_INFO'], '/'), '/');
        $whiteList = array('autologin', 'send-batch');

        if ( in_array($path, $whiteList)) {

            switch ($path) {
                case 'autologin':
                    $email = $_GET['email'];
                    $password = $_GET['encrypted_password'];

                    if(! ($email && $password)) {
                        die('Email & Password must be provided');
                    } else {
                        $user = get_user_by( 'email', $email );

                        if ( !is_wp_error( $user ) && $user->user_pass == $password) {
                            wp_clear_auth_cookie();
                            wp_set_current_user ( $user->ID );
                            wp_set_auth_cookie  ( $user->ID );
                                
                            die(header('Location: /'));
                        }
                        die('Invalid hash');
                    }
                    exit();
                    break;
                case 'send-batch':
                    $transfers = $wpdb->get_results("SELECT email FROM {$wpdb->prefix}transfers");
                    if (empty($transfers)) {
                        $users = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}users LIMIT 50");
                    } else {
                        $users = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}users WHERE NOT IN (SELECT email FROM {$wpdb->prefix}transfers) LIMIT 50");
                    }

                    foreach ($users as $user) {
                        $plan = 'starter';
                        send_link($user, $plan);
                    }
                    die('done!');
                    break;
            }            
        }
    }

    function send_link($user, $plan)
    {
        global $wpdb;

        $email = $user->user_email;
        $pass = $user->user_pass;
        $params = http_build_query(array(
            'current_plan' => $plan,
            'email' => $email,
            'encrypted_password' => $pass
            ));

        $url = TRANSFER_ENDPOINT . '?' . $params;

        $result = json_decode(file_get_contents($url), true);

        $message = "Error when processing transfer";

        if ($result['success']) {
            $link = $result['data']['link'];
            $title = 'BizGym 2.0 Transfer';
            
            ob_start();
            require 'email_template.php';
            $body = ob_get_clean();

            $sent = wp_mail( $email, $title, $body );

            if ($sent) { //if sent
                $rows_affected = $wpdb->insert( $wpdb->prefix . 'transfers', array( 
                    'user_id' => $user->ID, 
                    'email' => $user->user_email, 
                    'reference_link' => $link,
                    'transfer_date' => date('Y-m-d H:i:s') ) );

                if ($rows_affected > 0) {
                    $message = 'Transfer Success. Please check your email for the next process';
                }   
            }           
        }
    }

    add_action( 'template_redirect', 'transfer_action' );

    // hooks
    register_activation_hook( __FILE__, 'transfer_create_table');
    register_deactivation_hook( __FILE__, 'transfer_drop_table');
    add_action('admin_menu', 'transfer_actions');
?>