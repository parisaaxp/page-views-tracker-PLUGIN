<?php
// حذف جدول هنگام حذف افزونه
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

function pvt_delete_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page_views';
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
}

pvt_delete_table();
