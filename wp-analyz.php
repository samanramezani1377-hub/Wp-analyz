<?php
/**
 * Plugin Name: WP Analyz – Private Analytics
 * Description: Self-hosted privacy-first analytics for WordPress and WooCommerce. No Google tags or third-party analytics requests.
 * Version: 1.4.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: WP Analyz
 * License: GPL-2.0-or-later
 * Text Domain: wp-analyz
 */
defined('ABSPATH') || exit;
define('WPA_VERSION','1.4.0');
define('WPA_TABLE','wpa_events');
function wpa_table(){ global $wpdb; return $wpdb->prefix . WPA_TABLE; }
function wpa_aggregate_table(){ global $wpdb; return $wpdb->prefix . 'wpa_aggregates'; }
function wpa_defaults(){ return array('enabled'=>1,'exclude_admins'=>1,'require_consent'=>1,'baseline_analytics'=>1,'persistent_visitor'=>0,'woocommerce'=>1,'retention_days'=>90,'per_minute_limit'=>60); }
function wpa_settings(){ return wp_parse_args(get_option('wpa_settings',array()),wpa_defaults()); }
function wpa_activate(){
 global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table=wpa_table(); $charset=$wpdb->get_charset_collate();
 $sql="CREATE TABLE {$table} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 event_id char(36) NOT NULL,
 visitor_id char(36) NOT NULL DEFAULT '',
 session_id char(36) NOT NULL DEFAULT '',
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
 KEY event_name_created_id (event_name,created_at,id),
 KEY visitor_created (visitor_id,created_at),
 KEY session_created (session_id,created_at),
 KEY object_created (object_id,created_at)
 ) {$charset};";
 dbDelta($sql);
 $aggregate_table=wpa_aggregate_table();
 $aggregate_sql="CREATE TABLE {$aggregate_table} (
 dimension_hash char(64) NOT NULL,
 day date NOT NULL,
 event_name varchar(40) NOT NULL,
 url_path varchar(512) NOT NULL DEFAULT '/',
 device_type varchar(20) NOT NULL DEFAULT 'unknown',
 browser_family varchar(40) NOT NULL DEFAULT 'unknown',
 object_id bigint(20) unsigned NOT NULL DEFAULT 0,
 metric_name varchar(80) NOT NULL DEFAULT '',
 currency char(3) NOT NULL DEFAULT '',
 event_count bigint(20) unsigned NOT NULL DEFAULT 0,
 value_sum decimal(14,2) NOT NULL DEFAULT 0.00,
 metric_sum decimal(14,4) NOT NULL DEFAULT 0.0000,
 metric_count bigint(20) unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (dimension_hash),
 KEY day_event (day,event_name),
 KEY event_day_path (event_name,day),
 KEY metric_day (metric_name,day)
 ) {$charset};";
 dbDelta($aggregate_sql);
 if(!get_option('wpa_settings')) add_option('wpa_settings',wpa_defaults(),'','no'); update_option('wpa_db_version','4',false);
 if(!wp_next_scheduled('wpa_daily_cleanup')) wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','wpa_daily_cleanup');
}
register_activation_hook(__FILE__,'wpa_activate');
register_deactivation_hook(__FILE__,function(){wp_clear_scheduled_hook('wpa_daily_cleanup');});
add_action('plugins_loaded',function(){ if(get_option('wpa_db_version')!=='4') wpa_activate(); });
add_action('wpa_daily_cleanup',function(){
 global $wpdb;
 $days=max(7,min(730,(int)wpa_settings()['retention_days']));
 $cutoff=time()-DAY_IN_SECONDS*$days;
 $cutoff_datetime=gmdate('Y-m-d H:i:s',$cutoff);
 $cutoff_day=gmdate('Y-m-d',$cutoff);
 $wpdb->query($wpdb->prepare('DELETE FROM '.wpa_table().' WHERE created_at < %s LIMIT 10000',$cutoff_datetime));
 $wpdb->query($wpdb->prepare('DELETE FROM '.wpa_aggregate_table().' WHERE day < %s LIMIT 10000',$cutoff_day));
 // Clean WooCommerce analytics context through the Woo CRUD API so classic storage and HPOS are both supported.
 if(function_exists('wc_get_orders')){
  $orders=wc_get_orders(array(
   'limit'=>100,'return'=>'objects','orderby'=>'date','order'=>'ASC',
   'date_created'=>'<'.$cutoff_datetime,
   'meta_query'=>array('relation'=>'OR',
    array('key'=>'_wpa_analytics_consent','compare'=>'EXISTS'),
    array('key'=>'_wpa_visitor_id','compare'=>'EXISTS'),
    array('key'=>'_wpa_session_id','compare'=>'EXISTS'),
    array('key'=>'_wpa_analytics_purchase_recorded','compare'=>'EXISTS'),
    array('key'=>'_wpa_analytics_refund_ids','compare'=>'EXISTS')
   )
  ));
  foreach((array)$orders as $order){
   foreach(array('_wpa_analytics_consent','_wpa_visitor_id','_wpa_session_id','_wpa_analytics_purchase_recorded','_wpa_analytics_refund_ids') as $key)$order->delete_meta_data($key);
   $order->save();
  }
 }
});
function wpa_uuid($v){$v=strtolower((string)$v);return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',$v)?$v:false;}
function wpa_stable_uuid($seed){$h=md5(wp_salt('auth').'|'.$seed);$h[12]='5';$h[16]=dechex((hexdec($h[16])&3)|8);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);}
function wpa_clean_path($url){$url=esc_url_raw((string)$url,array('http','https'));$p=$url?wp_parse_url($url):false;$home=wp_parse_url(home_url('/'));if(empty($p['host'])||empty($home['host'])||strtolower($p['host'])!==strtolower($home['host']))return false;$path=isset($p['path'])?$p['path']:'/';$path=preg_replace('/[\x00-\x1F\x7F]/u','',rawurldecode($path));return substr(sanitize_text_field($path),0,512);}
function wpa_insert_event($e){global $wpdb;$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.wpa_table().' (event_id,visitor_id,session_id,event_name,url_path,referrer_host,page_title,device_type,browser_family,user_id,object_id,value_decimal,currency,event_data,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%f,%s,%s,%s)',$e['event_id'],$e['visitor_id'],$e['session_id'],$e['event_name'],$e['url_path'],$e['referrer_host'],$e['page_title'],$e['device_type'],$e['browser_family'],$e['user_id'],$e['object_id'],$e['value_decimal'],$e['currency'],$e['event_data'],$e['created_at']));}
function wpa_increment_aggregate($event_name,$url_path,$device='unknown',$browser='unknown',$object_id=0,$metric_name='',$currency='',$event_count=1,$value_sum=0,$metric_sum=0,$metric_count=0){
 global $wpdb; $day=gmdate('Y-m-d');
 $dims=array($day,(string)$event_name,(string)$url_path,(string)$device,(string)$browser,(int)$object_id,(string)$metric_name,(string)$currency);
 $hash=hash('sha256',wp_json_encode($dims));
 $sql='INSERT INTO '.wpa_aggregate_table().' (dimension_hash,day,event_name,url_path,device_type,browser_family,object_id,metric_name,currency,event_count,value_sum,metric_sum,metric_count) VALUES (%s,%s,%s,%s,%s,%s,%d,%s,%s,%d,%f,%f,%d) ON DUPLICATE KEY UPDATE event_count=event_count+VALUES(event_count),value_sum=value_sum+VALUES(value_sum),metric_sum=metric_sum+VALUES(metric_sum),metric_count=metric_count+VALUES(metric_count)';
 $wpdb->query($wpdb->prepare($sql,$hash,$day,$event_name,$url_path,$device,$browser,(int)$object_id,$metric_name,$currency,max(1,(int)$event_count),(float)$value_sum,(float)$metric_sum,max(0,(int)$metric_count)));
}
function wpa_merge_count_rows($rows,$more,$limit=10){$counts=array();foreach(array_merge((array)$rows,(array)$more) as $row){$label=(string)$row['label'];if(!isset($counts[$label]))$counts[$label]=0;$counts[$label]+=(float)$row['total'];}arsort($counts);$out=array();foreach(array_slice($counts,0,max(1,(int)$limit),true) as $label=>$total){$value=abs($total-round($total))<0.000001?(int)round($total):$total;$out[]=array('label'=>$label,'total'=>$value);}return $out;}
function wpa_public_event(WP_REST_Request $r){
 $s=wpa_settings();if(empty($s['enabled']))return new WP_Error('wpa_disabled','Analytics collection is disabled.',array('status'=>403));
 $ip=isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):'';$rk='wpa_rate_'.hash('sha256',wp_salt('auth').$ip);$n=(int)get_transient($rk);$limit=max(10,min(300,(int)$s['per_minute_limit']));if($n >= $limit)return new WP_Error('wpa_rate_limited','Too many events.',array('status'=>429));set_transient($rk,$n+1,MINUTE_IN_SECONDS);
 $body=$r->get_body();if(strlen((string)$body)>8192)return new WP_Error('wpa_payload_too_large','Event payload is too large.',array('status'=>413));$d=$r->get_json_params();if(!is_array($d))return new WP_Error('wpa_invalid_payload','Invalid payload.',array('status'=>400));$allowed=array('page_view','product_view','category_view','search','add_to_cart','remove_from_cart','begin_checkout','form_submit','outbound_click','login','sign_up','scroll_depth','web_vital','js_error');$name=isset($d['event'])?sanitize_key($d['event']):'';if(!in_array($name,$allowed,true))return new WP_Error('wpa_invalid_event','Unsupported event.',array('status'=>400));
 $aggregate=!empty($s['baseline_analytics'])&&isset($d['mode'])&&'aggregate'===$d['mode'];$baseline_allowed=!empty($s['require_consent'])?array('page_view','product_view','category_view','web_vital'):array('page_view','product_view','category_view','web_vital','search','scroll_depth','outbound_click','add_to_cart','remove_from_cart','begin_checkout','form_submit','js_error');if(empty($s['require_consent'])&&!$aggregate)return new WP_Error('wpa_advanced_tracking_disabled','Advanced tracking is disabled while consent mode is off.',array('status'=>403));if($aggregate&&!in_array($name,$baseline_allowed,true))return new WP_Error('wpa_baseline_event_forbidden','Event is not available in aggregate mode.',array('status'=>403));
 $eid=wpa_uuid($d['event_id']??'');if(!$eid)return new WP_Error('wpa_invalid_id','Invalid event identifier.',array('status'=>400));$vid=$aggregate?'':wpa_uuid($d['visitor_id']??'');$sid=$aggregate?'':wpa_uuid($d['session_id']??'');if(!$aggregate&&(!$vid||!$sid))return new WP_Error('wpa_invalid_id','Invalid event identifiers.',array('status'=>400));$path=wpa_clean_path($d['url']??'');if(false===$path)return new WP_Error('wpa_invalid_url','Invalid page URL.',array('status'=>400));
 $ref='';if(!$aggregate){$refurl=esc_url_raw((string)($d['referrer']??''),array('http','https'));$rp=$refurl?wp_parse_url($refurl):false;$hp=wp_parse_url(home_url('/'));if(!empty($rp['host'])&&!empty($hp['host'])&&strtolower($rp['host'])!==strtolower($hp['host']))$ref=substr(sanitize_text_field(strtolower($rp['host'])),0,190);}
 $device=in_array(($d['device']??''),array('desktop','mobile','tablet','unknown'),true)?$d['device']:'unknown';$browser=in_array(($d['browser']??''),array('chrome','firefox','safari','edge','other','unknown'),true)?$d['browser']:'unknown';$title=$aggregate?'':substr(sanitize_text_field($d['title']??''),0,255);$object=in_array($name,array('product_view','category_view','add_to_cart','remove_from_cart'),true)?absint($d['object_id']??0):0;$value=(!$aggregate&&is_numeric($d['value']??null))?max(0,min(999999999,(float)$d['value'])):0;$currency=(!$aggregate)?substr(preg_replace('/[^A-Z]/','',strtoupper(sanitize_text_field($d['currency']??''))),0,3):'';
 $allowed_data=$aggregate?array('metric','metric_value'):array('term','depth','metric','metric_value','link_host','form_type','result_count','items_count','source','utm_source','utm_medium','utm_campaign');$ed=array();if(isset($d['data'])&&is_array($d['data']))foreach($allowed_data as $k)if(isset($d['data'][$k])&&is_scalar($d['data'][$k]))$ed[$k]=substr(sanitize_text_field((string)$d['data'][$k]),0,120);
 $uid=(!$aggregate&&is_user_logged_in())?get_current_user_id():0;if(!$aggregate&&!empty($s['exclude_admins'])&&$uid&&user_can($uid,'manage_options'))return rest_ensure_response(array('ok'=>true,'ignored'=>true));
 if($aggregate){$metric='';$metric_value=0;$metric_count=0;if('web_vital'===$name){$metric=isset($ed['metric'])?sanitize_key($ed['metric']):'';if(!in_array($metric,array('ttfb_ms','lcp_ms','cls','interaction_latency_estimate_ms'),true)||!isset($ed['metric_value'])||!is_numeric($ed['metric_value']))return new WP_Error('wpa_invalid_metric','Invalid aggregate performance metric.',array('status'=>400));$metric_value=max(0,min(1000000,(float)$ed['metric_value']));$metric_count=1;}wpa_increment_aggregate($name,$path,$device,$browser,$object,$metric,'',1,0,$metric_value,$metric_count);return rest_ensure_response(array('ok'=>true,'aggregate'=>true));}
 wpa_insert_event(array('event_id'=>$eid,'visitor_id'=>$vid,'session_id'=>$sid,'event_name'=>$name,'url_path'=>$path,'referrer_host'=>$ref,'page_title'=>$title,'device_type'=>$device,'browser_family'=>$browser,'user_id'=>$uid,'object_id'=>$object,'value_decimal'=>$value,'currency'=>$currency,'event_data'=>wp_json_encode($ed),'created_at'=>current_time('mysql',true)));
 return rest_ensure_response(array('ok'=>true));
}
add_action('rest_api_init',function(){register_rest_route('wp-analyz/v1','/event',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>'wpa_public_event','permission_callback'=>'__return_true'));});
add_action('wp_enqueue_scripts',function(){
 $s=wpa_settings();
 if(empty($s['enabled'])||is_admin()||(!empty($s['exclude_admins'])&&current_user_can('manage_options')))return;
 $advanced=!empty($s['require_consent']);
 if($advanced){
  wp_enqueue_script('wp-analyz-tracker',plugins_url('assets/js/tracker.js',__FILE__),array(),WPA_VERSION,array('in_footer'=>true,'strategy'=>'defer'));
  $cfg=array('endpoint'=>esc_url_raw(rest_url('wp-analyz/v1/event')),'requireConsent'=>true,'baselineAnalytics'=>!empty($s['baseline_analytics']),'persistentVisitor'=>!empty($s['persistent_visitor']),'productId'=>function_exists('is_product')&&is_product()?(int)get_queried_object_id():0,'categoryId'=>function_exists('is_product_category')&&is_product_category()?(int)get_queried_object_id():0,'searchTerm'=>is_search()?sanitize_text_field(get_search_query(false)):'');
 }else{
  // Consent mode OFF never loads the advanced tracker or its consent API. Only the aggregate-only tracker is eligible.
  if(empty($s['baseline_analytics']))return;
  wp_enqueue_script('wp-analyz-basic-tracker',plugins_url('assets/js/tracker-basic.js',__FILE__),array(),WPA_VERSION,array('in_footer'=>true,'strategy'=>'defer'));
  $cfg=array('endpoint'=>esc_url_raw(rest_url('wp-analyz/v1/event')),'productId'=>function_exists('is_product')&&is_product()?(int)get_queried_object_id():0,'categoryId'=>function_exists('is_product_category')&&is_product_category()?(int)get_queried_object_id():0,'isSearch'=>is_search());
 }
 wp_add_inline_script($advanced?'wp-analyz-tracker':'wp-analyz-basic-tracker','window.WPAnalyzConfig='.wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';','before');
},30);
function wpa_capture_order_analytics_context($order, $save = false) {
 if (!is_object($order) || !method_exists($order, 'update_meta_data')) return;
 $settings = wpa_settings();
 $status = isset($_COOKIE['wpa_consent']) ? sanitize_key(wp_unslash($_COOKIE['wpa_consent'])) : '';
 if ('denied' === $status) {
  $order->update_meta_data('_wpa_analytics_consent', 'denied');
  if ($save && method_exists($order, 'save')) $order->save();
  return;
 }
 if (empty($settings['require_consent']) || 'granted' !== $status) return;
 $visitor = wpa_uuid(isset($_COOKIE['wpa_visitor_id']) ? wp_unslash($_COOKIE['wpa_visitor_id']) : '');
 $session = wpa_uuid(isset($_COOKIE['wpa_session_id']) ? wp_unslash($_COOKIE['wpa_session_id']) : '');
 if (!$visitor || !$session) return;
 $order->update_meta_data('_wpa_analytics_consent', $status);
 $order->update_meta_data('_wpa_visitor_id', $visitor);
 $order->update_meta_data('_wpa_session_id', $session);
 if ($save && method_exists($order, 'save')) $order->save();
}
add_action('woocommerce_checkout_create_order', 'wpa_capture_order_analytics_context', 20, 1);
add_action('woocommerce_store_api_checkout_order_processed', function($order) { wpa_capture_order_analytics_context($order, true); }, 20, 1);
function wpa_record_purchase($order_id) {
 $settings = wpa_settings();
 if (empty($settings['enabled']) || empty($settings['woocommerce']) || !function_exists('wc_get_order')) return;
 $order = wc_get_order($order_id);
 if (!$order || !$order->is_paid()) return;
 $consent_state = (string) $order->get_meta('_wpa_analytics_consent', true);
 if ('denied' === $consent_state) return;
 if ($order->get_meta('_wpa_analytics_purchase_recorded', true)) return;
 $consented = !empty($settings['require_consent']) && 'granted' === $consent_state;
 $seed = (int) $order_id;
 $visitor = $consented ? wpa_uuid($order->get_meta('_wpa_visitor_id', true)) : false;
 $session = $consented ? wpa_uuid($order->get_meta('_wpa_session_id', true)) : false;
 // Without consent, retain only order totals/counts for aggregate sales reporting; never attach customer/order IDs or a visitor/session ID.
 if (!$consented) {
  if (empty($settings['baseline_analytics'])) return;
  $currency = substr(sanitize_text_field($order->get_currency()), 0, 3);
  wpa_increment_aggregate('purchase', '/checkout/order-received/', 'unknown', 'unknown', 0, '', $currency, 1, (float) $order->get_total(), 0, 0);
  $order->update_meta_data('_wpa_analytics_purchase_recorded', gmdate('c'));
  $order->save();
  return;
 }
 wpa_insert_event(array(
  'event_id' => wpa_stable_uuid('purchase:' . $seed),
  'visitor_id' => $visitor ?: '',
  'session_id' => $session ?: '',
  'event_name' => 'purchase',
  'url_path' => '/checkout/order-received/',
  'referrer_host' => '',
  'page_title' => 'Purchase',
  'device_type' => 'unknown',
  'browser_family' => 'unknown',
  'user_id' => $consented ? (int) $order->get_customer_id() : 0,
  'object_id' => $consented ? $seed : 0,
  'value_decimal' => (float) $order->get_total(),
  'currency' => substr(sanitize_text_field($order->get_currency()), 0, 3),
  'event_data' => wp_json_encode($consented ? array('order_id' => (string) $seed, 'items_count' => (string) $order->get_item_count(), 'source' => 'woocommerce_server') : array('items_count' => (string) $order->get_item_count(), 'source' => 'woocommerce_aggregate')),
  'created_at' => current_time('mysql', true)
 ));
 $order->update_meta_data('_wpa_analytics_purchase_recorded', gmdate('c'));
 $order->save();
}

function wpa_record_refund($order_id,$refund_id) {
 $settings=wpa_settings();
 if(empty($settings['enabled'])||empty($settings['woocommerce'])||!function_exists('wc_get_order'))return;
 $order=wc_get_order($order_id);$refund=wc_get_order($refund_id);
 if(!$order||!$refund||!method_exists($refund,'get_amount'))return;
 $consent_state=(string)$order->get_meta('_wpa_analytics_consent',true);
 if('denied'===$consent_state)return;
 $recorded=$order->get_meta('_wpa_analytics_refund_ids',true);
 $recorded=is_array($recorded)?array_map('absint',$recorded):array();
 $refund_id=absint($refund_id);if(!$refund_id||in_array($refund_id,$recorded,true))return;
 $amount=abs((float)$refund->get_amount());if($amount<=0)return;
 $currency=substr(sanitize_text_field($order->get_currency()),0,3);
 $consented=!empty($settings['require_consent'])&&'granted'===$consent_state;
 if(!$consented){
  if(empty($settings['baseline_analytics']))return;
  wpa_increment_aggregate('refund','/checkout/order-received/','unknown','unknown',0,'',$currency,1,-$amount,0,0);
 }else{
  wpa_insert_event(array(
   'event_id'=>wpa_stable_uuid('refund:'.$refund_id),
   'visitor_id'=>wpa_uuid($order->get_meta('_wpa_visitor_id',true))?:'',
   'session_id'=>wpa_uuid($order->get_meta('_wpa_session_id',true))?:'',
   'event_name'=>'refund','url_path'=>'/checkout/order-received/','referrer_host'=>'',
   'page_title'=>'Refund','device_type'=>'unknown','browser_family'=>'unknown',
   'user_id'=>(int)$order->get_customer_id(),'object_id'=>(int)$order_id,
   'value_decimal'=>-$amount,'currency'=>$currency,
   'event_data'=>wp_json_encode(array('refund_id'=>(string)$refund_id,'source'=>'woocommerce_server')),
   'created_at'=>current_time('mysql',true)
  ));
 }
 $recorded[]=$refund_id;$order->update_meta_data('_wpa_analytics_refund_ids',array_values(array_unique($recorded)));$order->save();
}
add_action('woocommerce_order_fully_refunded','wpa_record_refund',20,2);
add_action('woocommerce_order_partially_refunded','wpa_record_refund',20,2);

add_action('woocommerce_payment_complete','wpa_record_purchase',20,1);add_action('woocommerce_order_status_processing','wpa_record_purchase',20,1);add_action('woocommerce_order_status_completed','wpa_record_purchase',20,1);
add_action('admin_menu',function(){add_menu_page('WP Analyz','WP Analyz','manage_options','wp-analyz','wpa_dashboard','dashicons-chart-area',58);add_submenu_page('wp-analyz','Settings','Settings','manage_options','wp-analyz-settings','wpa_settings_page');});
add_action('admin_enqueue_scripts',function($hook){if(strpos((string)$hook,'wp-analyz')===false)return;wp_enqueue_style('wpa-admin',plugins_url('assets/css/admin.css',__FILE__),array(),WPA_VERSION);});
function wpa_each_event_data_row($since, $event_name, $callback, $batch_size = 1000, $until = null) {
 global $wpdb;
 $batch_size = max(100, min(2000, (int) $batch_size));
 $last_id = 0;
 do {
  $rows = $wpdb->get_results($wpdb->prepare(
   'SELECT id,event_data FROM '.wpa_table().' WHERE id > %d AND created_at >= %s AND '.($until ? 'created_at < %s AND ' : '').'event_name = %s AND event_data IS NOT NULL ORDER BY id ASC LIMIT '.$batch_size,
   $until ? array($last_id, $since, $until, $event_name) : array($last_id, $since, $event_name)
  ), ARRAY_A);
  foreach ((array) $rows as $row) {
   $last_id = (int) $row['id'];
   $callback($row);
  }
 } while (count((array) $rows) === $batch_size);
}
function wpa_campaign_report($since, $until = null) {
 $counts = array();
 wpa_each_event_data_row($since, 'page_view', function($row) use (&$counts) {
  $data = json_decode((string) $row['event_data'], true);
  if (!is_array($data)) return;
  $source = isset($data['utm_source']) ? sanitize_text_field($data['utm_source']) : '';
  $medium = isset($data['utm_medium']) ? sanitize_text_field($data['utm_medium']) : '';
  $campaign = isset($data['utm_campaign']) ? sanitize_text_field($data['utm_campaign']) : '';
  if ('' === $source && '' === $medium && '' === $campaign) return;
  $label = implode(' / ', array_filter(array($source, $medium, $campaign), 'strlen'));
  if (!isset($counts[$label])) $counts[$label] = 0;
  $counts[$label]++;
 }, 1000, $until);
 arsort($counts);
 $rows = array(); foreach (array_slice($counts, 0, 10, true) as $label => $total) $rows[] = array('label' => $label, 'total' => $total);
 return $rows;
}
function wpa_search_report($since, $until = null) {
 $counts = array();
 wpa_each_event_data_row($since, 'search', function($row) use (&$counts) {
  $data = json_decode((string) $row['event_data'], true);
  if (!is_array($data) || empty($data['term'])) return;
  $term = trim(sanitize_text_field($data['term']));
  if ('' === $term) return;
  if (!isset($counts[$term])) $counts[$term] = 0;
  $counts[$term]++;
 }, 1000, $until);
 arsort($counts);
 $rows = array(); foreach (array_slice($counts, 0, 10, true) as $label => $total) $rows[] = array('label' => $label, 'total' => $total);
 return $rows;
}
function wpa_performance_report($since, $until = null, $aggregate_since = null, $aggregate_until = null) {
 $stats = array();
 wpa_each_event_data_row($since, 'web_vital', function($row) use (&$stats) {
  $data = json_decode((string) $row['event_data'], true);
  if (!is_array($data) || empty($data['metric']) || !isset($data['metric_value']) || !is_numeric($data['metric_value'])) return;
  $metric = sanitize_key($data['metric']);
  if (!isset($stats[$metric])) $stats[$metric] = array('sum' => 0, 'count' => 0);
  $stats[$metric]['sum'] += (float) $data['metric_value']; $stats[$metric]['count']++;
 }, 1000, $until);
 global $wpdb; $aggregate_since=$aggregate_since?:substr((string)$since,0,10); $aggregate_until=$aggregate_until?:gmdate('Y-m-d',time()+DAY_IN_SECONDS); $aggregate_metrics=$wpdb->get_results($wpdb->prepare('SELECT metric_name,SUM(metric_sum) metric_sum,SUM(metric_count) metric_count FROM '.wpa_aggregate_table().' WHERE day >= %s AND day < %s AND event_name = %s AND metric_name <> %s GROUP BY metric_name',$aggregate_since,$aggregate_until,'web_vital',''),ARRAY_A);foreach($aggregate_metrics as $row){$metric=sanitize_key($row['metric_name']);if(!isset($stats[$metric]))$stats[$metric]=array('sum'=>0,'count'=>0);$stats[$metric]['sum']+=(float)$row['metric_sum'];$stats[$metric]['count']+=(int)$row['metric_count'];}
 $rows = array(); foreach ($stats as $metric => $stat) $rows[] = array('label' => $metric . ' (میانگین)', 'total' => round($stat['sum'] / max(1, $stat['count']), 2));
 usort($rows, function($a, $b) { return strcmp($a['label'], $b['label']); });
 return $rows;
}
function wpa_csv_safe($value) {
 $value = (string) $value;
 if (preg_match('/^[=+\-@\t\r]/', $value)) return "'" . $value;
 return $value;
}
function wpa_currency_revenue_report($since,$until,$aggregate_since,$aggregate_until) {
 global $wpdb;$rows=array();
 $events=$wpdb->get_results($wpdb->prepare('SELECT currency,SUM(CASE WHEN event_name = %s THEN 1 ELSE 0 END) purchases,COALESCE(SUM(value_decimal),0) revenue FROM '.wpa_table().' WHERE created_at >= %s AND created_at < %s AND event_name IN (%s,%s) GROUP BY currency','purchase',$since,$until,'purchase','refund'),ARRAY_A);
 foreach((array)$events as $r){$c=$r['currency']!==''?$r['currency']:'نامشخص';if(!isset($rows[$c]))$rows[$c]=array('label'=>$c,'total'=>0,'purchases'=>0);$rows[$c]['total']+=(float)$r['revenue'];$rows[$c]['purchases']+=(int)$r['purchases'];}
 $agg=$wpdb->get_results($wpdb->prepare('SELECT currency,SUM(CASE WHEN event_name = %s THEN event_count ELSE 0 END) purchases,COALESCE(SUM(value_sum),0) revenue FROM '.wpa_aggregate_table().' WHERE day >= %s AND day < %s AND event_name IN (%s,%s) GROUP BY currency','purchase',$aggregate_since,$aggregate_until,'purchase','refund'),ARRAY_A);
 foreach((array)$agg as $r){$c=$r['currency']!==''?$r['currency']:'نامشخص';if(!isset($rows[$c]))$rows[$c]=array('label'=>$c,'total'=>0,'purchases'=>0);$rows[$c]['total']+=(float)$r['revenue'];$rows[$c]['purchases']+=(int)$r['purchases'];}
 usort($rows,function($a,$b){return strcmp($a['label'],$b['label']);});return $rows;
}
function wpa_product_activity_report($since,$until,$aggregate_since,$aggregate_until,$limit=10) {
 global $wpdb;$counts=array();
 $events=$wpdb->get_results($wpdb->prepare('SELECT object_id,event_name,COUNT(*) total FROM '.wpa_table().' WHERE created_at >= %s AND created_at < %s AND object_id > 0 AND event_name IN (%s,%s) GROUP BY object_id,event_name',$since,$until,'product_view','add_to_cart'),ARRAY_A);
 foreach((array)$events as $r){$id=(int)$r['object_id'];$k=$r['event_name']==='product_view'?'views':'carts';if(!isset($counts[$id]))$counts[$id]=array('views'=>0,'carts'=>0);$counts[$id][$k]+=(int)$r['total'];}
 $agg=$wpdb->get_results($wpdb->prepare('SELECT object_id,event_name,SUM(event_count) total FROM '.wpa_aggregate_table().' WHERE day >= %s AND day < %s AND object_id > 0 AND event_name IN (%s,%s) GROUP BY object_id,event_name',$aggregate_since,$aggregate_until,'product_view','add_to_cart'),ARRAY_A);
 foreach((array)$agg as $r){$id=(int)$r['object_id'];$k=$r['event_name']==='product_view'?'views':'carts';if(!isset($counts[$id]))$counts[$id]=array('views'=>0,'carts'=>0);$counts[$id][$k]+=(int)$r['total'];}
 uasort($counts,function($a,$b){return ($b['views']+$b['carts']*2)<=>($a['views']+$a['carts']*2);});$out=array();
 foreach(array_slice($counts,0,max(1,(int)$limit),true) as $id=>$c){$title=function_exists('get_the_title')?get_the_title((int)$id):'';$out[]=array('label'=>($title?$title:'محصول #'.(int)$id).' — بازدید: '.$c['views'].' / افزودن به سبد: '.$c['carts'],'total'=>(int)$c['views']);}return $out;
}
function wpa_funnel_event_counts($since,$until,$aggregate_since,$aggregate_until) {
 global $wpdb;$names=array('product_view'=>'بازدید محصول','add_to_cart'=>'افزودن به سبد','begin_checkout'=>'شروع پرداخت','purchase'=>'خرید');$counts=array_fill_keys(array_keys($names),0);
 $events=$wpdb->get_results($wpdb->prepare('SELECT event_name,COUNT(*) total FROM '.wpa_table().' WHERE created_at >= %s AND created_at < %s AND event_name IN (%s,%s,%s,%s) GROUP BY event_name',$since,$until,'product_view','add_to_cart','begin_checkout','purchase'),ARRAY_A);
 foreach((array)$events as $r)$counts[$r['event_name']]+=(int)$r['total'];
 $agg=$wpdb->get_results($wpdb->prepare('SELECT event_name,SUM(event_count) total FROM '.wpa_aggregate_table().' WHERE day >= %s AND day < %s AND event_name IN (%s,%s,%s,%s) GROUP BY event_name',$aggregate_since,$aggregate_until,'product_view','add_to_cart','begin_checkout','purchase'),ARRAY_A);
 foreach((array)$agg as $r)$counts[$r['event_name']]+=(int)$r['total'];
 $out=array();foreach($names as $k=>$label)$out[]=array('label'=>$label,'total'=>$counts[$k]);return $out;
}
function wpa_period_summary($since,$until,$aggregate_since,$aggregate_until) {
 global $wpdb;$t=wpa_table();$events=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) events,SUM(event_name='page_view') pageviews,SUM(event_name='purchase') purchases FROM {$t} WHERE created_at >= %s AND created_at < %s",$since,$until),ARRAY_A);
 $agg=$wpdb->get_row($wpdb->prepare('SELECT COALESCE(SUM(event_count),0) events,COALESCE(SUM(CASE WHEN event_name=%s THEN event_count ELSE 0 END),0) pageviews,COALESCE(SUM(CASE WHEN event_name=%s THEN event_count ELSE 0 END),0) purchases FROM '.wpa_aggregate_table().' WHERE day >= %s AND day < %s','page_view','purchase',$aggregate_since,$aggregate_until),ARRAY_A);
 foreach(array('events','pageviews','purchases') as $k)$events[$k]=(int)$events[$k]+(int)$agg[$k];return $events;
}
function wpa_woocommerce_status_report($from,$to) {
 if(!function_exists('wc_get_orders'))return array();
 $key='wpa_wc_status_'.md5($from.'|'.$to);
 $cached=get_transient($key);if(is_array($cached))return $cached;
 $statuses=array('wc-processing'=>'در حال پردازش','wc-completed'=>'تکمیل‌شده','wc-on-hold'=>'در انتظار','wc-pending'=>'در انتظار پرداخت','wc-failed'=>'ناموفق','wc-cancelled'=>'لغوشده','wc-refunded'=>'مرجوع‌شده');
 $rows=array();$range=$from.'...'.$to;
 foreach($statuses as $status=>$label){
  $result=wc_get_orders(array('status'=>array($status),'limit'=>1,'paginate'=>true,'return'=>'ids','date_created'=>$range,'orderby'=>'date','order'=>'DESC'));
  $total=is_object($result)&&isset($result->total)?(int)$result->total:0;
  $rows[]=array('label'=>$label,'total'=>$total);
 }
 set_transient($key,$rows,5*MINUTE_IN_SECONDS);
 return $rows;
}
function wpa_dashboard(){
 if(!current_user_can('manage_options'))wp_die('Forbidden');global $wpdb;
 $days=isset($_GET['days'])&&in_array(absint($_GET['days']),array(7,30,90),true)?absint($_GET['days']):7;
 $from=isset($_GET['from'])?sanitize_text_field(wp_unslash($_GET['from'])):'';$to=isset($_GET['to'])?sanitize_text_field(wp_unslash($_GET['to'])):'';
 $valid_date=function($v){$d=DateTime::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v;};
 $custom=$valid_date($from)&&$valid_date($to)&&$from<=$to&&((strtotime($to)-strtotime($from))/DAY_IN_SECONDS)<=364;
 if($custom){$since=$from.' 00:00:00';$until=gmdate('Y-m-d H:i:s',strtotime($to.' 00:00:00')+DAY_IN_SECONDS);$days=max(1,(int)((strtotime($to)-strtotime($from))/DAY_IN_SECONDS)+1);$agg_since=$from;$agg_until=gmdate('Y-m-d',strtotime($to)+DAY_IN_SECONDS);}
 else{$since=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days);$until=gmdate('Y-m-d H:i:s');$from=gmdate('Y-m-d',strtotime($since));$to=gmdate('Y-m-d');$agg_since=$from;$agg_until=gmdate('Y-m-d',time()+DAY_IN_SECONDS);}
 $previous_since=gmdate('Y-m-d H:i:s',strtotime($since)-DAY_IN_SECONDS*$days);$previous_until=$since;
 $previous_agg_since=gmdate('Y-m-d',strtotime($agg_since)-DAY_IN_SECONDS*$days);$previous_agg_until=$agg_since;
$t=wpa_table();$tot=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) events,COUNT(DISTINCT CASE WHEN event_name<>'purchase' AND visitor_id<>'' THEN visitor_id END) visitors,COUNT(DISTINCT CASE WHEN event_name<>'purchase' AND session_id<>'' THEN session_id END) sessions,SUM(event_name='page_view') pageviews,SUM(event_name='purchase') purchases,COALESCE(SUM(CASE WHEN event_name='purchase' THEN value_decimal ELSE 0 END),0) revenue FROM {$t} WHERE created_at >= %s AND created_at < %s",$since,$until),ARRAY_A);$agg_since=$agg_since;$agg_tot=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(event_count),0) events,COALESCE(SUM(CASE WHEN event_name='page_view' THEN event_count ELSE 0 END),0) pageviews,COALESCE(SUM(CASE WHEN event_name='purchase' THEN event_count ELSE 0 END),0) purchases,COALESCE(SUM(CASE WHEN event_name='purchase' THEN value_sum ELSE 0 END),0) revenue FROM ".wpa_aggregate_table()." WHERE day >= %s AND day < %s",$agg_since,$agg_until),ARRAY_A);foreach(array('events','pageviews','purchases','revenue') as $metric)$tot[$metric]+=$agg_tot[$metric];$pages=$wpdb->get_results($wpdb->prepare("SELECT url_path label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND created_at < %s AND event_name='page_view' GROUP BY url_path ORDER BY total DESC LIMIT 10",$since,$until),ARRAY_A);$agg_pages=$wpdb->get_results($wpdb->prepare("SELECT url_path label,SUM(event_count) total FROM ".wpa_aggregate_table()." WHERE day >= %s AND day < %s AND event_name='page_view' GROUP BY url_path",$agg_since,$agg_until),ARRAY_A);$pages=wpa_merge_count_rows($pages,$agg_pages,10);$sources=$wpdb->get_results($wpdb->prepare("SELECT CASE WHEN referrer_host='' THEN '(direct / unknown)' ELSE referrer_host END label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND created_at < %s AND event_name='page_view' GROUP BY label ORDER BY total DESC LIMIT 10",$since,$until),ARRAY_A);$events=$wpdb->get_results($wpdb->prepare("SELECT event_name label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND created_at < %s GROUP BY event_name ORDER BY total DESC LIMIT 15",$since,$until),ARRAY_A);$agg_events=$wpdb->get_results($wpdb->prepare("SELECT event_name label,SUM(event_count) total FROM ".wpa_aggregate_table()." WHERE day >= %s AND day < %s GROUP BY event_name",$agg_since,$agg_until),ARRAY_A);$events=wpa_merge_count_rows($events,$agg_events,15);$devices=$wpdb->get_results($wpdb->prepare("SELECT device_type label,COUNT(*) total FROM {$t} WHERE created_at >= %s AND created_at < %s AND event_name='page_view' GROUP BY device_type ORDER BY total DESC",$since,$until),ARRAY_A);$agg_devices=$wpdb->get_results($wpdb->prepare("SELECT device_type label,SUM(event_count) total FROM ".wpa_aggregate_table()." WHERE day >= %s AND day < %s AND event_name='page_view' GROUP BY device_type",$agg_since,$agg_until),ARRAY_A);$devices=wpa_merge_count_rows($devices,$agg_devices,10);$daily=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) day,COUNT(DISTINCT CASE WHEN event_name<>'purchase' AND session_id<>'' THEN session_id END) sessions,SUM(event_name='page_view') pageviews FROM {$t} WHERE created_at >= %s AND created_at < %s GROUP BY DATE(created_at) ORDER BY day",$since,$until),ARRAY_A);$daily_map=array();foreach($daily as $row)$daily_map[$row['day']]=array('day'=>$row['day'],'sessions'=>(int)$row['sessions'],'pageviews'=>(int)$row['pageviews']);$agg_daily=$wpdb->get_results($wpdb->prepare("SELECT day,COALESCE(SUM(CASE WHEN event_name='page_view' THEN event_count ELSE 0 END),0) pageviews FROM ".wpa_aggregate_table()." WHERE day >= %s AND day < %s GROUP BY day ORDER BY day",$agg_since,$agg_until),ARRAY_A);foreach($agg_daily as $row){if(!isset($daily_map[$row['day']]))$daily_map[$row['day']]=array('day'=>$row['day'],'sessions'=>0,'pageviews'=>0);$daily_map[$row['day']]['pageviews']+=(int)$row['pageviews'];}ksort($daily_map);$daily=array_values($daily_map);
 echo '<div class="wrap wpa-wrap" dir="rtl"><header class="wpa-header"><div><h1>WP Analyz</h1><p>آنالیتیکس خصوصی؛ داده‌ها در پایگاه داده همین وردپرس نگهداری می‌شوند.</p></div><div><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpa_export_csv&days='.$days.'&from='.rawurlencode($from).'&to='.rawurlencode($to)),'wpa_export_csv')).'">خروجی CSV</a> <a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=wp-analyz-settings')).'">تنظیمات</a></div></header><nav class="wpa-toolbar">بازه: ';foreach(array(7=>'۷ روز',30=>'۳۰ روز',90=>'۹۰ روز') as $d=>$label)echo '<a class="button '.($days===$d?'button-primary':'').'" href="'.esc_url(add_query_arg('days',$d,admin_url('admin.php?page=wp-analyz'))).'">'.esc_html($label).'</a> ';echo '</nav><form class="wpa-toolbar" method="get"><input type="hidden" name="page" value="wp-analyz"><label>از <input type="date" name="from" value="'.esc_attr($from).'"></label> <label>تا <input type="date" name="to" value="'.esc_attr($to).'"></label> <button class="button" type="submit">اعمال بازه</button></form><div class="wpa-cards">';$cards=array('بازدیدکنندگان قابل‌ردیابی (با رضایت)'=>(int)$tot['visitors'],'نشست‌های قابل‌ردیابی (با رضایت)'=>(int)$tot['sessions'],'بازدید صفحات'=>(int)$tot['pageviews'],'رویدادها'=>(int)$tot['events'],'خریدها'=>(int)$tot['purchases']);foreach($cards as $label=>$v)echo '<div class="wpa-card"><span>'.esc_html($label).'</span><strong>'.esc_html(number_format_i18n($v,is_float($v)?2:0)).'</strong></div>';echo '</div><div class="wpa-grid">';wpa_render_table('صفحات پربازدید',$pages);wpa_render_table('منابع ورودی',$sources);wpa_render_table('دستگاه‌ها',$devices,array('desktop'=>'رومیزی','mobile'=>'موبایل','tablet'=>'تبلت','unknown'=>'نامشخص'));wpa_render_table('رویدادها',$events);wpa_render_table('کمپین‌های ورودی (UTM)',wpa_campaign_report($since,$until));wpa_render_table('عبارت‌های جست‌وجو',wpa_search_report($since,$until));wpa_render_table('عملکرد صفحات',wpa_performance_report($since,$until,$agg_since,$agg_until));
 $currency_rows=array();foreach(wpa_currency_revenue_report($since,$until,$agg_since,$agg_until) as $r)$currency_rows[]=array('label'=>$r['label'].' — '.$r['purchases'].' خرید','total'=>(float)$r['total']);
 wpa_render_table('درآمد به تفکیک ارز (ارزها با هم جمع نشده‌اند)',$currency_rows);
 wpa_render_table('عملکرد محصولات (بازدید و افزودن به سبد)',wpa_product_activity_report($since,$until,$agg_since,$agg_until,10));
 wpa_render_table('مراحل خرید — تعداد رویدادها، نه کاربران یکتا',wpa_funnel_event_counts($since,$until,$agg_since,$agg_until));
 wpa_render_table('وضعیت سفارش‌های WooCommerce (بر اساس تاریخ ایجاد سفارش)',wpa_woocommerce_status_report($from,$to));
 $current_summary=wpa_period_summary($since,$until,$agg_since,$agg_until);$previous_summary=wpa_period_summary($previous_since,$previous_until,$previous_agg_since,$previous_agg_until);
 echo '<section class="wpa-panel"><h2>مقایسه با بازهٔ قبلی هم‌طول</h2><p>بازدید صفحه: '.esc_html(number_format_i18n($current_summary['pageviews'])).' در برابر '.esc_html(number_format_i18n($previous_summary['pageviews'])).'؛ خرید: '.esc_html(number_format_i18n($current_summary['purchases'])).' در برابر '.esc_html(number_format_i18n($previous_summary['purchases'])).'؛ رویدادها: '.esc_html(number_format_i18n($current_summary['events'])).' در برابر '.esc_html(number_format_i18n($previous_summary['events'])).'.</p></section>';
 echo '<section class="wpa-panel"><h2>روند روزانه</h2>';if(!$daily)echo '<p>هنوز داده‌ای ثبت نشده است. اگر رضایت کاربر فعال است، بنر رضایت را به تابع WP Analyz متصل کنید.</p>';else foreach($daily as $r)echo '<p>'.esc_html($r['day']).' — بازدید: '.esc_html((int)$r['pageviews']).'، نشست: '.esc_html((int)$r['sessions']).'</p>';echo '</section></div><p class="wpa-footnote">IP کامل در جدول رویدادها ذخیره نمی‌شود. شناسه‌های مرورگر در حالت پیش‌فرض فقط برای نشست فعلی هستند. این افزونه به‌تنهایی تضمین‌کنندهٔ انطباق قانونی نیست.</p></div>';
}
function wpa_render_table($title,$rows,$map=array()){echo '<section class="wpa-panel"><h2>'.esc_html($title).'</h2>';if(!$rows){echo '<p class="wpa-empty">هنوز داده‌ای برای نمایش نیست.</p></section>';return;}echo '<table class="widefat striped"><thead><tr><th>عنوان</th><th>تعداد</th></tr></thead><tbody>';foreach($rows as $r){$label=$r['label'];if(isset($map[$label]))$label=$map[$label];echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html(is_float($r['total']) ? number_format_i18n($r['total'], 2) : number_format_i18n((int)$r['total'])).'</td></tr>';}echo '</tbody></table></section>';}
function wpa_settings_page(){
 if(!current_user_can('manage_options'))wp_die('Forbidden');
 $s=wpa_settings();
 if(isset($_GET['updated']))echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
 echo '<div class="wrap wpa-wrap" dir="rtl"><h1>تنظیمات WP Analyz</h1><form class="wpa-settings-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wpa_save_settings">';
 wp_nonce_field('wpa_save_settings');
 $checks=array(
  'enabled'=>'فعال بودن آمارگیری',
  'exclude_admins'=>'حذف مدیران از آمار',
  'baseline_analytics'=>'آمار تجمیعی بدون شناسه و کوکی (در حالت خاموش بودن رضایت، تنها حالت جمع‌آوری)',
  'require_consent'=>'حالت رضایت: روشن = ردیابی پیشرفته فقط پس از رضایت؛ خاموش = فقط آمار تجمیعی بدون شناسه/کوکی',
  'persistent_visitor'=>'شناسهٔ بازدیدکنندهٔ پایدار (فقط در حالت رضایت)',
  'woocommerce'=>'ثبت آمار خرید WooCommerce'
 );
 foreach($checks as $key=>$label){
  $disabled=('persistent_visitor'===$key && empty($s['require_consent']))?' disabled="disabled"':'';
  echo '<label class="wpa-setting"><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked($s[$key],1,false).$disabled.'><span><strong>'.esc_html($label).'</strong></span></label>';
 }
 echo '<p><label>مدت نگهداری (روز) <input type="number" min="7" max="730" name="retention_days" value="'.esc_attr($s['retention_days']).'"></label></p><p><label>حداکثر رویداد در دقیقه از هر IP <input type="number" min="10" max="300" name="per_minute_limit" value="'.esc_attr($s['per_minute_limit']).'"></label></p>';
 submit_button('ذخیره تنظیمات');
 echo '</form>';
 if(!empty($s['require_consent'])){
  echo '<section class="wpa-panel"><h2>اتصال به ابزار رضایت سایت</h2><p>پس از رضایت کاربر اجرا کنید: <code>window.wpAnalyzConsent(\'granted\');</code></p><p>در صورت رد یا پس‌گرفتن رضایت: <code>window.wpAnalyzConsent(\'denied\');</code></p><p>خود افزونه بنر رضایت نمایش نمی‌دهد؛ این اتصال برای هماهنگی با ابزار رضایت موجود در سایت است.</p></section>';
 }else{
  echo '<section class="wpa-panel"><h2>حالت آمار تجمیعی</h2><p>در این حالت، فایل ردیاب پیشرفته و API رضایت WP Analyz به فرانت‌اند اضافه نمی‌شوند. فقط فایل کوچک آمار تجمیعی بارگذاری می‌شود؛ بدون شناسهٔ بازدیدکننده/نشست، کوکی تحلیلی، localStorage یا sessionStorage. عبارت‌های جست‌وجو، مقادیر UTM و تاریخچهٔ فردی ذخیره نمی‌شوند.</p><p>این تنظیم، بنرِ خود WP Analyz را فعال یا غیرفعال نمی‌کند؛ افزونه اصولاً بنر نمایش نمی‌دهد و بنرِ افزونه‌های دیگر را کنترل نمی‌کند.</p></section>';
 }
 echo '</div>';
}
add_action('admin_post_wpa_save_settings',function(){if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('wpa_save_settings');$out=array();foreach(array('enabled','exclude_admins','baseline_analytics','require_consent','persistent_visitor','woocommerce') as $k)$out[$k]=isset($_POST[$k])?1:0;$out['retention_days']=max(7,min(730,absint($_POST['retention_days']??90)));$out['per_minute_limit']=max(10,min(300,absint($_POST['per_minute_limit']??60)));update_option('wpa_settings',$out,false);wp_safe_redirect(add_query_arg('updated','1',admin_url('admin.php?page=wp-analyz-settings')));exit;});
add_action('admin_post_wpa_export_csv',function(){if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('wpa_export_csv');global $wpdb;$days=isset($_GET['days'])&&in_array(absint($_GET['days']),array(7,30,90),true)?absint($_GET['days']):7;$from=isset($_GET['from'])?sanitize_text_field(wp_unslash($_GET['from'])):'';$to=isset($_GET['to'])?sanitize_text_field(wp_unslash($_GET['to'])):'';$valid_date=function($v){$d=DateTime::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v;};if($valid_date($from)&&$valid_date($to)&&$from<=$to&&((strtotime($to)-strtotime($from))/DAY_IN_SECONDS)<=364){$since=$from.' 00:00:00';$until=gmdate('Y-m-d H:i:s',strtotime($to.' 00:00:00')+DAY_IN_SECONDS);$agg_since=$from;$agg_until=gmdate('Y-m-d',strtotime($to)+DAY_IN_SECONDS);}else{$since=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days);$until=gmdate('Y-m-d H:i:s');$agg_since=gmdate('Y-m-d',strtotime($since));$agg_until=gmdate('Y-m-d',time()+DAY_IN_SECONDS);}nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=wp-analyz-'.gmdate('Y-m-d').'.csv');$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,array('created_at_utc','event','path','referrer_host','page_title','device','browser','object_id','value','currency','aggregate_count'));$last_id=0;do{$rows=$wpdb->get_results($wpdb->prepare('SELECT id,created_at,event_name,url_path,referrer_host,page_title,device_type,browser_family,object_id,value_decimal,currency FROM '.wpa_table().' WHERE id > %d AND created_at >= %s AND created_at < %s ORDER BY id ASC LIMIT 1000',$last_id,$since,$until),ARRAY_A);foreach((array)$rows as $r){$last_id=(int)$r['id'];unset($r['id']);fputcsv($f,array_map('wpa_csv_safe',array_merge(array_values($r),array(''))));}}while(count((array)$rows)===1000);$last_hash='';do{$aggregate_rows=$wpdb->get_results($wpdb->prepare('SELECT dimension_hash,day,event_name,url_path,device_type,browser_family,object_id,value_sum,currency,event_count FROM '.wpa_aggregate_table().' WHERE day >= %s AND day < %s AND dimension_hash > %s ORDER BY dimension_hash ASC LIMIT 1000',$agg_since,$agg_until,$last_hash),ARRAY_A);foreach((array)$aggregate_rows as $r){$last_hash=(string)$r['dimension_hash'];fputcsv($f,array_map('wpa_csv_safe',array(gmdate('Y-m-d 00:00:00',strtotime($r['day'])),$r['event_name'],$r['url_path'],'','(daily aggregate)',$r['device_type'],$r['browser_family'],$r['object_id'],$r['value_sum'],$r['currency'],$r['event_count'])));}}while(count((array)$aggregate_rows)===1000);fclose($f);exit;});
add_action('admin_init',function(){if(function_exists('wp_add_privacy_policy_content'))wp_add_privacy_policy_content('WP Analyz','<p>Depending on configuration, WP Analyz collects aggregate page/product/category views, coarse device/browser classes, performance metrics, limited aggregate interaction counts, and aggregate WooCommerce sales without visitor/session IDs or analytics cookies; or it may load advanced tracking only after the site consent integration signals consent. Aggregate records can still constitute personal data depending on context and require an appropriate legal basis and disclosure. Advanced mode may use consent-preference and attribution cookies. The plugin does not store full IP addresses in event rows and does not provide a consent banner. Configure retention and your privacy notice appropriately.</p>');});
add_filter('wp_privacy_personal_data_exporters', function($exporters) {
 $exporters['wp-analyz'] = array(
  'exporter_friendly_name' => 'WP Analyz Analytics',
  'callback' => function($email, $page = 1) {
   $user = get_user_by('email', $email);
   global $wpdb;
   $page = max(1, (int) $page); $limit = 500; $offset = ($page - 1) * $limit;
   $rows = array();
   if ($user) {
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id,created_at,event_name,url_path,referrer_host,device_type FROM '.wpa_table().' WHERE user_id=%d AND event_name<>%s ORDER BY created_at DESC LIMIT %d OFFSET %d', $user->ID, 'purchase', $limit, $offset), ARRAY_A);
   }
   $data = array();
   foreach ($rows as $row) {
    $data[] = array('group_id'=>'wp-analyz','group_label'=>'WP Analyz Analytics','item_id'=>'wpa-'.$row['id'],'data'=>array(
     array('name'=>'Date UTC','value'=>$row['created_at']), array('name'=>'Event','value'=>$row['event_name']),
     array('name'=>'Page path','value'=>$row['url_path']), array('name'=>'Referrer host','value'=>$row['referrer_host']),
     array('name'=>'Device','value'=>$row['device_type'])
    ));
   }
   $events_done = !$user || count($rows) < $limit;
   $orders_done = true;
   if (function_exists('wc_get_orders')) {
    // Billing-email lookup includes guest orders that have no WordPress customer account.
    $orders_page = wc_get_orders(array('billing_email'=>sanitize_email($email),'limit'=>$limit,'page'=>$page,'paginate'=>true,'return'=>'objects'));
    $orders = is_object($orders_page) && isset($orders_page->orders) ? $orders_page->orders : array();
    $max_pages = is_object($orders_page) && isset($orders_page->max_num_pages) ? max(1,(int)$orders_page->max_num_pages) : 1;
    $orders_done = $page >= $max_pages;
    foreach ($orders as $order) {
     $consent = (string) $order->get_meta('_wpa_analytics_consent', true);
     if ('' !== $consent) {
      $data[] = array('group_id'=>'wp-analyz','group_label'=>'WP Analyz Analytics','item_id'=>'wpa-order-'.$order->get_id(),'data'=>array(
       array('name'=>'WooCommerce order ID','value'=>(string)$order->get_id()),
       array('name'=>'Analytics consent state','value'=>$consent),
       array('name'=>'Visitor ID','value'=>(string)$order->get_meta('_wpa_visitor_id', true)),
       array('name'=>'Session ID','value'=>(string)$order->get_meta('_wpa_session_id', true))
      ));
     }
     // Guest purchase events have user_id=0; include them by their WooCommerce order ID.
     $purchase_rows = $wpdb->get_results($wpdb->prepare('SELECT id,created_at,event_name,url_path,referrer_host,device_type FROM '.wpa_table().' WHERE object_id=%d AND event_name=%s ORDER BY created_at DESC', (int)$order->get_id(), 'purchase'), ARRAY_A);
     foreach ((array)$purchase_rows as $row) {
      $data[] = array('group_id'=>'wp-analyz','group_label'=>'WP Analyz Analytics','item_id'=>'wpa-'.$row['id'],'data'=>array(
       array('name'=>'Date UTC','value'=>$row['created_at']), array('name'=>'Event','value'=>$row['event_name']),
       array('name'=>'Page path','value'=>$row['url_path']), array('name'=>'Referrer host','value'=>$row['referrer_host']),
       array('name'=>'Device','value'=>$row['device_type'])
      ));
     }
    }
   }
   return array('data'=>$data,'done'=>$events_done && $orders_done);
  }
 );
 return $exporters;
});
add_filter('wp_privacy_personal_data_erasers', function($erasers) {
 $erasers['wp-analyz'] = array(
  'eraser_friendly_name' => 'WP Analyz Analytics',
  'callback' => function($email, $page = 1) {
   $user = get_user_by('email', $email);
   global $wpdb;
   $removed = false;
   if ($user) $removed = (bool) $wpdb->delete(wpa_table(), array('user_id'=>(int)$user->ID), array('%d'));
   $done = true;
   if (function_exists('wc_get_orders')) {
    // Billing-email lookup includes guest orders with no WordPress user ID.
    $page = max(1, (int) $page); $limit = 100;
    $orders_page = wc_get_orders(array('billing_email'=>sanitize_email($email),'limit'=>$limit,'page'=>$page,'paginate'=>true,'return'=>'objects'));
    $orders = is_object($orders_page) && isset($orders_page->orders) ? $orders_page->orders : array();
    $max_pages = is_object($orders_page) && isset($orders_page->max_num_pages) ? max(1,(int)$orders_page->max_num_pages) : 1;
    foreach ($orders as $order) {
     // Remove linked purchase analytics even when the checkout was a guest order (user_id=0).
     $order_id = (int) $order->get_id();
     $deleted_purchase = $wpdb->delete(wpa_table(), array('object_id'=>$order_id,'event_name'=>'purchase'), array('%d','%s'));
     $deleted_refunds = $wpdb->delete(wpa_table(), array('object_id'=>$order_id,'event_name'=>'refund'), array('%d','%s'));
     if ($deleted_purchase || $deleted_refunds) $removed = true;
     foreach (array('_wpa_analytics_consent','_wpa_visitor_id','_wpa_session_id','_wpa_analytics_purchase_recorded','_wpa_analytics_refund_ids') as $meta_key) {
      if ('' !== (string) $order->get_meta($meta_key, true)) { $order->delete_meta_data($meta_key); $removed = true; }
     }
     if (method_exists($order, 'save')) $order->save();
    }
    $done = $page >= $max_pages;
   }
   return array('items_removed'=>$removed,'items_retained'=>false,'messages'=>array(),'done'=>$done);
  }
 );
 return $erasers;
});
