<?php
/**
 * Plugin Name: Ensha Booking Connector
 * Description: Shortcode and Elementor-compatible Ensha appointment widget.
 * Version: 0.26.0
 */
if (!defined('ABSPATH')) exit;
function ensha_sanitize_api_key($value){$value=trim((string)$value);$current=(string)get_option('ensha_booking_api_key','');if($value==='')return $current;if(strlen($value)<32){add_settings_error('ensha_booking_api_key','ensha_key_short','کلید API باید حداقل ۳۲ کاراکتر باشد.');return $current;}return $value;}
function ensha_sort_query_values($value){if(!is_array($value))return $value;ksort($value);foreach($value as $key=>$item)$value[$key]=ensha_sort_query_values($item);return $value;}
add_action('admin_init',function(){register_setting('ensha_booking','ensha_booking_base_url');register_setting('ensha_booking','ensha_booking_centre_id');register_setting('ensha_booking','ensha_booking_api_key',['sanitize_callback'=>'ensha_sanitize_api_key']);});
add_action('admin_menu',function(){add_options_page('Ensha Booking','Ensha Booking','manage_options','ensha-booking',function(){?>
<div class="wrap"><h1>Ensha Booking</h1><form method="post" action="options.php"><?php settings_fields('ensha_booking');?><table class="form-table">
<tr><th>Ensha URL</th><td><input class="regular-text" name="ensha_booking_base_url" value="<?php echo esc_attr(get_option('ensha_booking_base_url'));?>" placeholder="https://srun.ir/ensha"></td></tr>
<tr><th>Centre ID</th><td><input name="ensha_booking_centre_id" value="<?php echo esc_attr(get_option('ensha_booking_centre_id'));?>"></td></tr>
<tr><th>API Key</th><td><input class="regular-text" type="password" name="ensha_booking_api_key" value="" autocomplete="new-password" placeholder="برای حفظ کلید فعلی خالی بگذارید"></td></tr></table><?php submit_button();?></form></div><?php });});
add_shortcode('ensha_booking',function(){if(!is_user_logged_in())return '<p>برای رزرو ابتدا وارد حساب شوید.</p>';$uid=get_current_user_id();$nonce=wp_create_nonce('ensha_booking_'.$uid);$idempotency=str_replace('-','',wp_generate_uuid4());ob_start();?>
<form class="ensha-booking-widget" data-nonce="<?php echo esc_attr($nonce);?>" data-idempotency="<?php echo esc_attr($idempotency);?>"><label>از تاریخ<input type="date" name="from" required></label><label>تا تاریخ<input type="date" name="to" required></label><button type="button" data-load>نمایش زمان‌ها</button><select name="slot_id" data-slots required></select><button>ثبت نوبت</button><p data-result></p></form>
<script>(()=>{const f=document.currentScript.previousElementSibling,r=f.querySelector('[data-result]'),s=f.querySelector('[data-slots]');async function call(action,data){const x=await fetch('<?php echo esc_js(admin_url('admin-ajax.php'));?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action,...data,nonce:f.dataset.nonce})});const j=await x.json();if(!j.success)throw new Error(j.data?.message||'خطا');return j.data}f.querySelector('[data-load]').onclick=async()=>{try{const j=await call('ensha_slots',{from:f.from.value,to:f.to.value});s.innerHTML=j.data.map(x=>`<option value="${x.id}">${x.start} — ${x.counselor.name}</option>`).join('')}catch(e){r.textContent=e.message}};f.onsubmit=async e=>{e.preventDefault();try{const j=await call('ensha_book',{slot_id:s.value,idempotency_key:f.dataset.idempotency});r.textContent='نوبت ثبت شد: '+j.number;f.dataset.idempotency=crypto.randomUUID().replaceAll('-','')}catch(e){r.textContent=e.message}}})();</script><?php return ob_get_clean();});
function ensha_proxy($method,$path,$body=[],$idempotency_key=''){
    $base=rtrim(get_option('ensha_booking_base_url'),'/');
    $centre=(int)get_option('ensha_booking_centre_id');
    $target='/api/v1/centres/'.$centre.$path;
    $url=$base.$target;
    $method=strtoupper($method);
    $timestamp=(string)time();
    $nonce=str_replace('-','',wp_generate_uuid4());
    $encoded_body=$body?http_build_query($body,'','&'):'';
    $url_path=(string)wp_parse_url($target,PHP_URL_PATH);
    parse_str((string)(wp_parse_url($url,PHP_URL_QUERY)?:''),$query_values);
    $query=http_build_query(ensha_sort_query_values($query_values),'','&',PHP_QUERY_RFC3986);
    $canonical=implode("\n",[$method,$url_path,$query,$timestamp,$nonce,hash('sha256',$encoded_body)]);
    $headers=[
        'Accept'=>'application/json',
        'X-Ensha-Timestamp'=>$timestamp,
        'X-Ensha-Nonce'=>$nonce,
        'X-Ensha-Signature'=>hash_hmac('sha256',$canonical,(string)get_option('ensha_booking_api_key')),
    ];
    if(!in_array($method,['GET','HEAD','OPTIONS'],true)){
        $headers['Content-Type']='application/x-www-form-urlencoded';
        $headers['X-Ensha-Idempotency-Key']=$idempotency_key?:str_replace('-','',wp_generate_uuid4());
    }
    return wp_remote_request($url,['method'=>$method,'headers'=>$headers,'body'=>$encoded_body,'timeout'=>25]);
}
add_action('wp_ajax_ensha_slots',function(){check_ajax_referer('ensha_booking_'.get_current_user_id(),'nonce');$r=ensha_proxy('GET','/availability?'.http_build_query(['from'=>sanitize_text_field($_POST['from']),'to'=>sanitize_text_field($_POST['to'])]));$j=json_decode(wp_remote_retrieve_body($r),true);wp_send_json_success($j);});
add_action('wp_ajax_ensha_book',function(){check_ajax_referer('ensha_booking_'.get_current_user_id(),'nonce');$u=wp_get_current_user();$idempotency=preg_replace('/[^A-Za-z0-9_-]/','',(string)($_POST['idempotency_key']??''));if(strlen($idempotency)<16)wp_send_json_error(['message'=>'کلید Idempotency معتبر نیست.'],422);$r=ensha_proxy('POST','/appointments',['slot_id'=>(int)$_POST['slot_id'],'wordpress_user_id'=>(string)$u->ID,'first_name'=>$u->first_name?:$u->display_name,'last_name'=>$u->last_name?:'-','phone'=>get_user_meta($u->ID,'billing_phone',true),'email'=>$u->user_email],$idempotency);$j=json_decode(wp_remote_retrieve_body($r),true);if(wp_remote_retrieve_response_code($r)>=300)wp_send_json_error($j,400);wp_send_json_success($j);});
