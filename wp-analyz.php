<?php
/**
 * Plugin Name: WP Analyz – Private Analytics
 * Description: Self-hosted privacy-first analytics for WordPress and WooCommerce. No Google tags or third-party analytics requests.
 * Version: 1.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: WP Analyz
 * License: GPL-2.0-or-later
 * Text Domain: wp-analyz
 */
defined('ABSPATH') || exit;
define('WPA_VERSION','1.0.0');
define('WPA_TABLE','wpa_events');
function wpa_table(){ global $wpdb; return $wpdb->prefix . WPA_TABLE; }
function wpa_defaults(){ return array('enabled'=>1,'exclude_admins'=>1,'require_consent'=>1,'persistent_visitor'=>0,'woocommerce'=>1,'retention_days'=>90,'per_minute_limit'=>60); }
function wpa_settings(){ return wp_parse_args(get_option('wpa_settings',array()),wpa_defaults()); }
function wpa_activate(){
 global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=wpa_table(); $charset=$wpdb->get_charset_collate();
 $sql="CREATE TABLE {$table} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 event_id char(36) NOT NULL,
 visitor_id char(36) NOT NULL,
 session_id char(36) NOT NULL,
 event_name varchar(40) NOT NULL,
 url_path varchar(512) NOT NULL DEFAULT '/',
 referrer_host varchar(190) NOT NULL DEFAULT '',
 page_title varchar(255) NOT NULL DEFAULT '',
 device_type varchar(20) NOT NULL DEFAULT 'unknown',
 browser_family varchar(40) NOT NULL DEFAULT 'unknown',
 user_id bigint(20) unsigned NOT NULL DEFAULT 0,
 object_id bigint(20) unsigned NOT NULL DEFAULT 0,
 value_decimal decimal(12,2) NOT NULL DEFAULT 0.00,
 currency char(3) NOT NULL DEFAULT '',
 event_data longtext NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY event_id (event_id),
 KEY created_at (created_at),
 KEY event_name_created (event_name,created_at),
 KEY visitor_created (visitor_id,created_at),
 KEY session_created (session_id,created_at),
 KEY object_created (object_id,created_at)
 ) {$charset};";
 dbDelta($sql); if(!get_option('wpa_settings')) add_option('wpa_settings',wpa_defaults(),'','no'); update_option('wpa_db_version','1',false);
 if(!wp_next_scheduled('wpa_daily_cleanup')) wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','wpa_daily_cleanup');
}
register_activation_hook(__FILE__,'wpa_activate');
register_deactivation_hook(__FILE__,function(){wp_clear_scheduled_hook('wpa_daily_cleanup');});
add_action('plugins_loaded',function(){ if(get_option('wpa_db_version')!=='1') wpa_activate(); });
add_action('wpa_daily_cleanup',function(){ global $wpdb; $days=max(7,min(730,(int)wpa_settings()['retention_days'])); $wpdb->query($wpdb->prepare('DELETE FROM '.wpa_table().' WHERE created_at < %s LIMIT 10000',gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days))); });
function wpa_uuid($v){$v=strtolower((string)$v);return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',$v)?$v:false;}
function wpa_stable_uuid($seed){$h=md5(wp_salt('auth').'|'.$seed);$h[12]='5';$h[16]=dechex((hexdec($h[16])&3)|8);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);}
function wpa_clean_path($url){$url=esc_url_raw((string)$url,array('http','https'));$p=$url?wp_parse_url($url):false;$home=wp_parse_url(home_url('/'));if(empty($p['host'])||empty($home['host'])||strtolower($p['host'])!==strtolower($home['host']))return false;$path=isset($p['path'])?$p['path']:'/';$path=preg_replace('/[\x00-\x1F\x7F]/u','',rawurldecode($path));return substr(sanitize_text_field($path),0,512);}
function wpa_insert_event($e){global $wpdb;$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.wpa_table().' (event_id,visitor_id,session_id,event_name,url_path,referrer_host,page_title,device_type,browser_family,user_id,object_id,value_decimal,currency,event_data,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%f,%s,%s,%s)',$e['event_id'],$e['visitor_id'],$e['session_id'],$e['event_name'],$e['url_path'],$e['referrer_host'],$e['page_title'],$e['device_type'],$e['browser_family'],$e['user_id'],$e['object_id'],$e['value_decimal'],$e['currency'],$e['event_data'],$e['created_at']));}
function wpa_public_event(WP_REST_Request $r){
 $s=wpa_settings();if(empty($s['enabled']))return new WP_Error('wpa_disabled','Analytics collection is disabled.',array('status'=>403));
 $ip=isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):'';$rk='wpa_rate_'.hash('sha256',wp_salt('auth').$ip);$n=(int)get_transient($rk);$limit=max(10,min(300,(int)$s['per_minute_limit']));if($n >= $limit)return new WP_Error('wpa_rate_limited','Too many events.',array('status'=>429));set_transient($rk,$n+1,MINUTE_IN_SECONDS);
 $d=$r->get_json_params();if(!is_array($d))return new WP_Error('wpa_invalid_payload','Invalid payload.',array('status'=>400));$allowed=array('page_view','product_view','category_view','search','add_to_cart','remove_from_cart','begin_checkout','form_submit','outbound_click','purchase','login','sign_up','scroll_depth','web_vital','js_error');$name=isset($d['event'])?sanitize_key($d['event']):'';if(!in_array($name,$allowed,true))return new WP_Error('wpa_invalid_event','Unsupported event.',array('status'=>400));
 $eid=wpa_uuid($d['event_id']??'');$vid=wpa_uuid($d['visitor_id']??'');$sid=wpa_uuid($d['session_id']??'');if(!$eid||!$vid||!$sid)return new WP_Error('wpa_invalid_id','Invalid event identifiers.',array('status'=>400));$path=wpa_clean_path($d['url']??'');if(false===$path)return new WP_Error('wpa_invalid_url','Invalid page URL.',array('status'=>400));
 $ref='';$refurl=esc_url_raw((string)($d['referrer']??''),array('http','https'));$rp=$refurl?wp_parse_url($refurl):false;$hp=wp_parse_url(home_url('/'));if(!empty($rp['host'])&&!empty($hp['host'])&&strtolower($rp['host'])!==strtolower($hp['host']))$ref=substr(sanitize_text_field(strtolower($rp['host'])),0,190);
 $device=in_array(($d['device']??''),array('desktop','mobile','tablet','unknown'),true)?$d['device']:'unknown';$browser=in_array(($d['browser']??''),array('chrome','firefox','safari','edge','other','unknown'),true)?$d['browser']:'unknown';$title=substr(sanitize_text_field($d['title']??''),0,255);$object=absint($d['object_id']??0);$value=is_numeric($d['value']??null)?max(0,min(999999999,(float)$d['value'])):0;$currency=substr(preg_replace('/[^A-Z]/','',strtoupper(sanitize_text_field($d['currency']??''))),0,3);
 $allowed_data=array('term','depth','metric','metric_value','link_host','form_type','result_count','items_count','source');$ed=array();if(isset($d['data'])&&is_array($d['data']))foreach($allowed_data as $k)if(isset($d['data'][$k])&&is_scalar($d['data'][$k]))$ed[$k]=substr(sanitize_text_field((string)$d['data'][$k]),0,120);
 $uid=is_user_logged_in()?get_current_user_id():0;if(!empty($s['exclude_admins'])&&$uid&&user_can($uid,'manage_options'))return rest_ensure_response(array('ok'=>true,'ignored'=>true));
 wpa_insert_event(array('event_id'=>$eid,'visitor_id'=>$vid,'session_id'=>$sid,'event_name'=>$name,'url_path'=>$path,'referrer_host'=>$ref,'page_title'=>$title,'device_type'=>$device,'browser_family'=>$browser,'user_id'=>$uid,'object_id'=>$object,'value_decimal'=>$value,'currency'=>$currency,'event_data'=>wp_json_encode($ed),'created_at'=>current_time('mysql',true)));
 return rest_ensure_response(array('ok'=>true));
}
add_action('rest_api_init',function(){register_rest_route('wp-analyz/v1','/event',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>'wpa_public_event','permission_callback'=>'__return_true'));});
add_action('wp_enqueue_scripts',function(){
 $s=wpa_settings();if(empty($s['enabled'])||is_admin()||(!empty($s['exclude_admins'])&&current_user_can('manage_options')))return;
 wp_enqueue_script('wp-analyz-tracker',plugins_url('assets/js/tracker.js',__FILE__),array(),WPA_VERSION,array('in_footer'=>true,'strategy'=>'defer'));
 $cfg=array('endpoint'=>esc_url_raw(rest_url('wp-analyz/v1/event')),'requireConsent'=>!empty($s['require_consent']),'persistentVisitor'=>!empty($s['persistent_visitor']),'productId'=>function_exists('is_product')&&is_product()?(int)get_queried_object_id():0,'categoryId'=>function_exists('is_product_category')&&is_product_category()?(int)get_queried_object_id():0,'searchTerm'=>is_search()?sanitize_text_field(get_search_query(false)):'');wp_add_inline_script('wp-analyz-tracker','window.WPAnalyzConfig='.wp_json_encode($cfg).';','before');
},30);
function wpa_record_purchase($order_id){if(empty(wpa_settings()['woocommerce'])||!function_exists('wc_get_order'))return;$o=wc_get_order($order_id);if(!$o||!$o->is_paid())return;$seed=(int)$order_id;wpa_insert_event(array('event_id'=>wpa_stable_uuid('purchase:'.$seed),'visitor_id'=>wpa_stable_uuid('purchase-visitor:'.$seed),'session_id'=>wpa_stable_uuid('purchase-session:'.$seed),'event_name'=>'purchase','url_path'=>'/checkout/order-received/','referrer_host'=>'','page_title'=>'Purchase','device_type'=>'unknown','browser_family'=>'unknown','user_id'=>(int)$o->get_customer_id(),'object_id'=>$seed,'value_decimal'=>(float)$o->get_total(),'currency'=>substr(sanitize_text_field($o->get_currency()),0,3),'event_data'=>wp_json_encode(array('order_id'=>(string)$seed,'items_count'=>(string)$o->get_item_count(),'source'=>'woocommerce_server')),'created_at'=>current_time('mysql',true)));}
add_action('woocommerce_payment_complete','wpa_record_purchase',20,1);add_action('woocommerce_order_status_processing','wpa_record_purchase',20,1);add_action('woocommerce_order_status_completed','wpa_record_purchase',20,1);
add_action('admin_menu',function(){add_menu_page('WP Analyz','WP Analyz','manage_options','wp-analyz','wpa_dashboard','dashicons-chart-area',58);add_submenu_page('wp-analyz','Settings','Settings','manage_options','wp-analyz-settings','wpa_settings_page');});
add_action('admin_enqueue_scripts',function($hook){if(strpos((string)$hook,'wp-analyz')===false)return;wp_enqueue_style('wpa-admin',plugins_url('assets/css/admin.css',__FILE__),array(),WPA_VERSION);});
function wpa_dashboard(){if(!current_user_can('manage_options'))wp_die('Forbidden');global $wpdb;$days=isset($_GET['days'])&&in_array(absint($_GET['days']),array(7,30,90),true)?absint($_GET['days']):7;$since=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days);$t=wpa_table();$tot=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) events,COUNT(DISTINCT CASE WHEN event_name<>'purchase' THEN visitor_id END) visitors,COUNT(DISTINCT CASE WHEN event_name<>'purchase' THEN session_id END) sessions,SUM(event_name='page_view') pageviews,SUM(event_name='purchase') purchases,COALESCE(SUM(CASE WHEN event_name='purchase' THEN value_decimal ELSE 0 END),0) revenue FROM {$t} WHERE created_at >= %s",$since),ARRAY_A);$pages=$wpdb->get_results($wpdb->prepare("SELECT url_path label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND event_name='page_view' GROUP BY url_path ORDER BY total DESC LIMIT 10",$since),ARRAY_A);$sources=$wpdb->get_results($wpdb->prepare("SELECT CASE WHEN referrer_host='' THEN '(direct / unknown)' ELSE referrer_host END label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND event_name='page_view' GROUP BY label ORDER BY total DESC LIMIT 10",$since),ARRAY_A);$events=$wpdb->get_results($wpdb->prepare("SELECT event_name label,COUNT(*) total FROM {$t} WHERE created_at >= %s GROUP BY event_name ORDER BY total DESC LIMIT 15",$since),ARRAY_A);$devices=$wpdb->get_results($wpdb->prepare("SELECT device_type label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND event_name='page_view' GROUP BY device_type ORDER BY total DESC",$since),ARRAY_A);$daily=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) day,COUNT(DISTINCT CASE WHEN event_name<>'purchase' THEN session_id END) sessions,SUM(event_name='page_view') pageviews FROM {$t} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day",$since),ARRAY_A);
 echo '<div class="wrap wpa-wrap" dir="rtl"><header class="wpa-header"><div><h1>WP Analyz</h1><p>آنالیتیکس خصوصی؛ داده‌ها در پایگاه داده همین وردپرس نگهداری می‌شوند.</p></div><div><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpa_export_csv&days='.$days),'wpa_export_csv')).'">خروجی CSV</a> <a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=wp-analyz-settings')).'">تنظیمات</a></div></header><nav class="wpa-toolbar">بازه: ';foreach(array(7=>'۷ روز',30=>'۳۰ روز',90=>'۹۰ روز') as $d=>$label)echo '<a class="button '.($days===$d?'button-primary':'').'" href="'.esc_url(add_query_arg('days',$d,admin_url('admin.php?page=wp-analyz'))).'">'.esc_html($label).'</a> ';echo '</nav><div class="wpa-cards">';$cards=array('بازدیدکنندگان یکتا'=>(int)$tot['visitors'],'نشست‌ها'=>(int)$tot['sessions'],'بازدید صفحات'=>(int)$tot['pageviews'],'رویدادها'=>(int)$tot['events'],'خریدها'=>(int)$tot['purchases'],'درآمد'=>(float)$tot['revenue']);foreach($cards as $label=>$v)echo '<div class="wpa-card"><span>'.esc_html($label).'</span><strong>'.esc_html(number_format_i18n($v,is_float($v)?2:0)).'</strong></div>';echo '</div><div class="wpa-grid">';wpa_render_table('صفحات پربازدید',$pages);wpa_render_table('منابع ورودی',$sources);wpa_render_table('دستگاه‌ها',$devices,array('desktop'=>'رومیزی','mobile'=>'موبایل','tablet'=>'تبلت','unknown'=>'نامشخص'));wpa_render_table('رویدادها',$events);echo '<section class="wpa-panel"><h2>روند روزانه</h2>';if(!$daily)echo '<p>هنوز داده‌ای ثبت نشده است. اگر رضایت کاربر فعال است، بنر رضایت را به تابع WP Analyz متصل کنید.</p>';else foreach($daily as $r)echo '<p>'.esc_html($r['day']).' — بازدید: '.esc_html((int)$r['pageviews']).'، نشست: '.esc_html((int)$r['sessions']).'</p>';echo '</section></div><p class="wpa-footnote">IP کامل در جدول رویدادها ذخیره نمی‌شود. شناسه‌های مرورگر در حالت پیش‌فرض فقط برای نشست فعلی هستند. این افزونه به‌تنهایی تضمین‌کنندهٔ انطباق قانونی نیست.</p></div>';
}
function wpa_render_table($title,$rows,$map=array()){echo '<section class="wpa-panel"><h2>'.esc_html($title).'</h2>';if(!$rows){echo '<p class="wpa-empty">هنوز داده‌ای برای نمایش نیست.</p></section>';return;}echo '<table class="widefat striped"><thead><tr><th>عنوان</th><th>تعداد</th></tr></thead><tbody>';foreach($rows as $r){$label=$r['label'];if(isset($map[$label]))$label=$map[$label];echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html(number_format_i18n((int)$r['total'])).'</td></tr>';}echo '</tbody></table></section>';}
function wpa_settings_page(){if(!current_user_can('manage_options'))wp_die('Forbidden');$s=wpa_settings();if(isset($_GET['updated']))echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';echo '<div class="wrap wpa-wrap" dir="rtl"><h1>تنظیمات WP Analyz</h1><form class="wpa-settings-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wpa_save_settings">';wp_nonce_field('wpa_save_settings');$checks=array('enabled'=>'فعال بودن آمارگیری','exclude_admins'=>'حذف مدیران از آمار','require_consent'=>'نیاز به رضایت صریح کاربر (پیشنهاد می‌شود)','persistent_visitor'=>'شناسهٔ بازدیدکنندهٔ پایدار (localStorage)','woocommerce'=>'ثبت رویداد خرید WooCommerce');foreach($checks as $key=>$label)echo '<label class="wpa-setting"><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked($s[$key],1,false).'><span><strong>'.esc_html($label).'</strong></span></label>';echo '<p><label>مدت نگهداری (روز) <input type="number" min="7" max="730" name="retention_days" value="'.esc_attr($s['retention_days']).'"></label></p><p><label>حداکثر رویداد در دقیقه از هر IP <input type="number" min="10" max="300" name="per_minute_limit" value="'.esc_attr($s['per_minute_limit']).'"></label></p>';submit_button('ذخیره تنظیمات');echo '</form><section class="wpa-panel"><h2>اتصال به بنر رضایت</h2><p>پس از رضایت کاربر اجرا کنید: <code>window.wpAnalyzConsent(\'granted\');</code></p><p>در صورت رد یا پس‌گرفتن رضایت: <code>window.wpAnalyzConsent(\'denied\');</code></p><p>این افزونه بنر رضایت نمایش نمی‌دهد؛ اتصال به ابزار رضایت سایت بر عهده مدیر سایت است.</p></section></div>';}
add_action('admin_post_wpa_save_settings',function(){if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('wpa_save_settings');$out=array();foreach(array('enabled','exclude_admins','require_consent','persistent_visitor','woocommerce') as $k)$out[$k]=isset($_POST[$k])?1:0;$out['retention_days']=max(7,min(730,absint($_POST['retention_days']??90)));$out['per_minute_limit']=max(10,min(300,absint($_POST['per_minute_limit']??60)));update_option('wpa_settings',$out,false);wp_safe_redirect(add_query_arg('updated','1',admin_url('admin.php?page=wp-analyz-settings')));exit;});
add_action('admin_post_wpa_export_csv',function(){if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('wpa_export_csv');global $wpdb;$days=isset($_GET['days'])&&in_array(absint($_GET['days']),array(7,30,90),true)?absint($_GET['days']):7;$since=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days);$rows=$wpdb->get_results($wpdb->prepare('SELECT created_at,event_name,url_path,referrer_host,page_title,device_type,browser_family,object_id,value_decimal,currency FROM '.wpa_table().' WHERE created_at >= %s ORDER BY created_at DESC LIMIT 50000',$since),ARRAY_A);nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=wp-analyz-'.gmdate('Y-m-d').'.csv');$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,array('created_at_utc','event','path','referrer_host','page_title','device','browser','object_id','value','currency'));foreach($rows as $r)fputcsv($f,array_values($r));fclose($f);exit;});
add_action('admin_init',function(){if(function_exists('wp_add_privacy_policy_content'))wp_add_privacy_policy_content('WP Analyz','<p>WP Analyz stores analytics events such as page views, sessions, device type and some WooCommerce events in this site database. It does not store full IP addresses in its events table. By default, browser IDs last for the current browser session. If enabled, a persistent browser ID is stored in localStorage. The plugin requires consent by default but does not display a consent banner; connect your consent-management tool. Search terms can contain personal information. Configure retention and your privacy notice appropriately.</p>');});
add_filter('wp_privacy_personal_data_exporters', function($exporters) {
 $exporters['wp-analyz'] = array('exporter_friendly_name'=>'WP Analyz Analytics','callback'=>function($email) {
  $u=get_user_by('email',$email);if(!$u)return array('data'=>array(),'done'=>true);global $wpdb;
  $rows=$wpdb->get_results($wpdb->prepare('SELECT id,created_at,event_name,url_path,referrer_host,device_type FROM '.wpa_table().' WHERE user_id=%d ORDER BY created_at DESC LIMIT 5000',$u->ID),ARRAY_A);$data=array();
  foreach($rows as $r)$data[]=array('group_id'=>'wp-analyz','group_label'=>'WP Analyz Analytics','item_id'=>'wpa-'.$r['id'],'data'=>array(array('name'=>'Date UTC','value'=>$r['created_at']),array('name'=>'Event','value'=>$r['event_name']),array('name'=>'Page path','value'=>$r['url_path']),array('name'=>'Referrer host','value'=>$r['referrer_host']),array('name'=>'Device','value'=>$r['device_type'])));
  return array('data'=>$data,'done'=>true);
 });return $exporters;
});
add_filter('wp_privacy_personal_data_erasers', function($erasers) {
 $erasers['wp-analyz'] = array('eraser_friendly_name'=>'WP Analyz Analytics','callback'=>function($email) {
  $u=get_user_by('email',$email);if(!$u)return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true);global $wpdb;
  $n=$wpdb->delete(wpa_table(),array('user_id'=>(int)$u->ID),array('%d'));return array('items_removed'=>(bool)$n,'items_retained'=>false,'messages'=>array(),'done'=>true);
 });return $erasers;
});
