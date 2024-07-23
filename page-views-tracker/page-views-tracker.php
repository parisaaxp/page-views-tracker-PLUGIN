<?php
/*
Plugin Name: Page Views Tracker
Description: A plugin to track and display page views.
Version: 1.1
Author: ChatGPT & ParisaPARVIZI
*/
// جلوگیری از دسترسی مستقیم به فایل
if (!defined('ABSPATH')) {
    exit;
}
//add MobileDetect library
require_once plugin_dir_path(__FILE__) . 'Mobile-Detect/src/MobileDetect.php';
// تابعی برای ایجاد جدول در پایگاه داده هنگام نصب افزونه
function pvt_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page_views';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id bigint(20) UNSIGNED NOT NULL,
        views bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        user_ip varchar(100) DEFAULT '' NOT NULL,
        user_device varchar(50) DEFAULT '' NOT NULL,
        referer varchar(255) DEFAULT '' NOT NULL,
        keywords varchar(255) DEFAULT '' NOT NULL,
        visit_time datetime DEFAULT NULL,
        location varchar(255) DEFAULT '' NOT NULL,
        time_spent bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY unique_post_ip (post_id, user_ip)
    ) $charset_collate;";    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
// تابعی برای حذف جدول هنگام حذف افزونه
function pvt_delete_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page_views';
    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
}
// هوک برای حذف داده ها هنگام حذف افزونه
register_uninstall_hook(__FILE__, 'pvt_delete_table');
// هوک برای نصب و فعالسازی افزونه
register_activation_hook(__FILE__, 'pvt_create_table');
// تابع برای تشخیص IP بازدید کننده
function pvt_get_user_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}
// تابع برای تشخیص دستگاه بازدید کننده
function pvt_get_user_device() {
    global $wpdb;
    $detect = new \Detection\MobileDetect;
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    if (is_bot($user_agent)) {
        return 'Bot';
    }

    if ($detect->isMobile()) {
        return 'Mobile';
    } elseif ($detect->isTablet()) {
        return 'Tablet';
    } else {
        return 'Desktop';
    }
}
// تابع گرفتن کلمه کلیدی از موتور جستجو یا لینک ارجاع دهنده
function pvt_get_keywords_from_referer($referer) {
    // این روش ممکن است بسته به موتور جستجو تغییر کند
    $parsed_url = parse_url($referer);

    // بررسی وجود کلید 'query' در آرایه parsed_url
    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query_params);
        return isset($query_params['q']) ? sanitize_text_field($query_params['q']) : 'Unknown';
    } else {
        return 'Unknown';
    }
}
// تابع برای ثبت مدت زمان حضور کاربر در صفحه
function pvt_track_time_spent() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var startTime = Date.now();

            window.addEventListener('beforeunload', function() {
                var endTime = Date.now();
                var timeSpent = Math.round((endTime - startTime) / 1000); // زمان به ثانیه

                // ارسال داده با استفاده از XMLHttpRequest
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('action=pvt_track_time&time_spent=' + timeSpent + '&post_id=<?php echo get_the_ID(); ?>');            });
        });
    </script>
    <?php
}
// تابع برای شناسایی ربات
if (!function_exists('is_bot')) {
    function is_bot($user_agent) {
        $bots = ['Googlebot', 'Bingbot', 'Yahoo', 'Slurp', 'DuckDuckBot', 'Bot', 'Spider', 'crawler'];
        foreach ($bots as $bot) {
            if (stripos($user_agent, $bot) !== false) {
                return true;
            }
        }
        return false;
    }
}
// تابع تشخیص منبع ترافیک
function pvt_get_referer() {
    return isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : 'Direct';
}
// تابع برای افزایش تعداد بازدیدها و ثبت اطلاعات بازدید کننده
function pvt_track_page_views() {
    if (is_singular()) {
        global $post;
        global $wpdb;

        $table_name = $wpdb->prefix . 'page_views';
        $post_id = $post->ID;
        $user_ip = pvt_get_user_ip();
        $user_device = pvt_get_user_device();
        $referer = pvt_get_referer();
        $keywords = pvt_get_keywords_from_referer($referer);
        $current_time = current_time('mysql');
        $location = pvt_get_location_from_ip($user_ip);      
        // بررسی اینکه آیا کاربر یک ربات است یا خیر
        if (is_bot($_SERVER['HTTP_USER_AGENT'])) {
            return; // اگر کاربر ربات است، هیچ داده ای ثبت نمی شود
        }
        $views = $wpdb->get_var($wpdb->prepare(
            "SELECT views FROM $table_name WHERE post_id = %d AND user_ip = %s",
            $post_id, $user_ip
        ));
// بررسی وجود رکورد
        $existing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE post_id = %d AND user_ip = %s",
            $post_id, $user_ip
        ));
        if ($existing_record) {
            // اگر رکورد موجود است، تعداد بازدیدها را افزایش می‌دهیم و بقیه اطلاعات را به‌روزرسانی می‌کنیم
            $wpdb->update($table_name, [
                'views' => $existing_record->views + 1,
                'referer' => $referer,
                'keywords' => $keywords,
                'visit_time' => $current_time,
                'location' => $location
            ], [
                'post_id' => $post_id,
                'user_ip' => $user_ip
            ]);
        } else {
            // اگر رکورد موجود نیست، یک رکورد جدید ایجاد می‌کنیم
            $wpdb->insert($table_name, [
                'post_id' => $post_id,
                'views' => 1,
                'user_ip' => $user_ip,
                'user_device' => $user_device,
                'referer' => $referer,
                'keywords' => $keywords,
                'visit_time' => $current_time,
                'location' => $location
            ]);
        }
    }
}
add_action('wp_ajax_pvt_track_time', 'pvt_track_time');
add_action('wp_ajax_nopriv_pvt_track_time', 'pvt_track_time');
function pvt_track_time() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page_views';
    // دریافت زمان صرف شده از درخواست
    $time_spent = isset($_POST['time_spent']) ? intval($_POST['time_spent']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0; // اطمینان از دریافت صحیح شناسه پست
    $user_ip = pvt_get_user_ip();
    // به‌روزرسانی جدول با زمان صرف شده
    $wpdb->update($table_name, [
        'time_spent' => $time_spent
    ], [
        'post_id' => $post_id,
        'user_ip' => $user_ip
    ]);
    wp_die(); // خاتمه درخواست Ajax
}
// تابع افزودن موقعیت جغرافیایی به جدول
function pvt_get_location_from_ip($ip) {
    $api_key = 'YOUR_API_KEY'; // جایگزین با کلید API واقعی
    $response = wp_remote_get("http://api.ipapi.com/api/$ip?access_key=$api_key"); 
    if (is_wp_error($response)) {
        return 'Unknown';
    }  
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);    
    return isset($data['city']) ? $data['city'] . ', ' . $data['country_name'] : 'Unknown';
}
// هوک برای ثبت بازدیدها
add_action('wp_head', 'pvt_track_page_views');
add_action('wp_footer', 'pvt_track_time_spent');
function pvt_handle_ajax_time_track() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page_views';
    $post_id = get_the_ID();
    $time_spent = isset($_GET['time_spent']) ? intval($_GET['time_spent']) : 0;
    $wpdb->query($wpdb->prepare(
        "UPDATE $table_name SET time_spent = time_spent + %d WHERE post_id = %d",
        $time_spent,
        $post_id
    ));
}
add_action('wp_ajax_pvt_track_time', 'pvt_handle_ajax_time_track');

add_action('wp_ajax_nopriv_pvt_track_time', 'pvt_handle_ajax_time_track');

//افزودن منو به پنل مدیریت وردپرس
//افزودن منو به پنل مدیریت وردپرس
function pvt_add_admin_menu() {
    add_menu_page(
        'Page Views Tracker',
        'Page Views',
        'manage_options',
        'page-views-tracker',
        'pvt_display_stats', // استفاده از نام تابع به روز شده
        'dashicons-analytics'
    );
}
add_action('admin_menu', 'pvt_add_admin_menu');


// تابع برای نمایش آمار در پنل مدیریت
// تابع برای نمایش آمار در پنل مدیریت
function pvt_display_stats() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page_views';
    
    $per_page = 20; // تعداد رکوردها در هر صفحه
    $page = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
    $offset = ($page - 1) * $per_page;
    
    // گرفتن تعداد کل رکوردها
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    // گرفتن داده‌ها برای صفحه جاری
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, views, user_ip, user_device, time_spent, referer, keywords, visit_time, location FROM $table_name ORDER BY views DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));
    
    // نمایش جدول
    echo '<div class="wrap"><h1>Page Views Statistics</h1>';
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th>Page/Post Title</th><th>Views</th><th>IP</th><th>Device</th><th>Time Spent (seconds)</th><th>Referer</th><th>Keywords</th><th>Location</th></tr></thead>';
    echo '<tbody>';    

    if ($results) {
        foreach ($results as $row) {
            $post_title = get_the_title($row->post_id);
            echo '<tr>';
            echo '<td>' . esc_html($post_title) . '</td>';
            echo '<td>' . esc_html($row->views) . '</td>';
            echo '<td>' . esc_html($row->user_ip) . '</td>';
            echo '<td>' . esc_html($row->user_device) . '</td>';
            echo '<td>' . esc_html($row->time_spent) . '</td>';
            echo '<td>' . esc_url($row->referer) . '</td>';
            echo '<td>' . esc_html($row->keywords) . '</td>';
            echo '<td>' . esc_html($row->location) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="8">No data available</td></tr>';
    }
    echo '</tbody></table>';
    
    // نمایش صفحه بندی
    $total_pages = ceil($total_count / $per_page);
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="pagination">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $class = ($i == $page) ? 'current' : '';
            echo '<a class="' . $class . '" href="?page=pvt-page-views&paged=' . $i . '">' . $i . '</a> ';
        }
        echo '</div></div>';
    }
    
    echo '</div>';
}

?>
