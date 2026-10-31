<?php
/**
 * Plugin Name: Elementor Missing Image Audit & Restore
 * Description: Admin-only batched scan and manual restoration of missing local Elementor video overlay images.
 * Version: 1.0.0
 * Author: Drunall Technologies
 */
if (!defined('ABSPATH')) exit;
class Elementor_Image_Audit {
 const OPT='elementor_image_audit_results_v1';
 const NONCE='elementor_image_audit_nonce';
 function __construct(){add_action('admin_menu',[$this,'menu']);add_action('wp_ajax_mfia_scan',[$this,'scan']);add_action('wp_ajax_mfia_restore',[$this,'restore']);}
 function menu(){add_management_page('Missing Video Images','Missing Video Images','manage_options','elementor-image-audit',[$this,'page']);}
 function guard(){if(!current_user_can('manage_options'))wp_send_json_error('Permission denied',403);check_ajax_referer(self::NONCE,'nonce');}
 function collect($nodes,&$found){
  if(!is_array($nodes))return;
  foreach($nodes as $node){
   if(!is_array($node))continue;
   if(($node['widgetType']??'')==='video'){
    $s=$node['settings']??[];$overlay=$s['image_overlay']??null;
    if(is_array($overlay)&&!empty($overlay['url']))$found[]=['url'=>$overlay['url'],'id'=>(int)($overlay['id']??0),'element'=>(string)($node['id']??'')];
   }
   if(!empty($node['elements']))$this->collect($node['elements'],$found);
  }
 }
 function localpath($url){
  $up=wp_get_upload_dir();$base=wp_parse_url($up['baseurl']);$parsed=wp_parse_url($url);
  if(empty($parsed['path'])||empty($base['path']))return false;
  $path=rawurldecode($parsed['path']);$prefix=rtrim($base['path'],'/').'/';
  if(strpos($path,$prefix)!==0)return false;
  $relative=substr($path,strlen($prefix));
  if(!$relative||strpos($relative,'..')!==false||preg_match('~[\\\\\x00-\x1F]~',$relative))return false;
  return ['path'=>trailingslashit($up['basedir']).$relative,'relative'=>$relative];
 }
 function item($post,$entry){
  $url=html_entity_decode($entry['url'],ENT_QUOTES,'UTF-8');$local=$this->localpath($url);
  if(!$local){ // allow original-domain URL with matching uploads path
   $p=wp_parse_url($url);$up=wp_get_upload_dir();$base=wp_parse_url($up['baseurl']);
   if(!empty($p['path'])&&!empty($base['path'])){
    $prefix=rtrim($base['path'],'/').'/';$path=rawurldecode($p['path']);
    if(strpos($path,$prefix)===0){$relative=substr($path,strlen($prefix));if($relative&&strpos($relative,'..')===false&&!preg_match('~[\\\\\x00-\x1F]~',$relative))$local=['path'=>trailingslashit($up['basedir']).$relative,'relative'=>$relative];}
   }
  }
  if(!$local)return null;
  if(is_file($local['path'])&&filesize($local['path'])>0)return null;
  return ['post_id'=>(int)$post->ID,'post_title'=>get_the_title($post),'edit'=>get_edit_post_link($post,'raw'),'view'=>get_permalink($post),'element'=>$entry['element'],'url'=>$url,'relative'=>$local['relative'],'status'=>'missing','note'=>''];
 }
 function scan(){
  $this->guard();$page=max(1,(int)($_POST['page']??1));
  if($page===1)update_option(self::OPT,[],false);
  $q=new WP_Query(['post_type'=>'post','post_status'=>['publish','draft','private','pending','future'],'posts_per_page'=>20,'paged'=>$page,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>false]);
  $results=get_option(self::OPT,[]);$added=0;
  foreach($q->posts as $id){$post=get_post($id);$raw=get_post_meta($id,'_elementor_data',true);if(!$raw)continue;$nodes=is_string($raw)?json_decode($raw,true):$raw;if(!is_array($nodes))continue;$found=[];$this->collect($nodes,$found);
   foreach($found as $e){$item=$this->item($post,$e);if(!$item)continue;$key=$id.':'.$e['element'];$results[$key]=$item;$added++;}
  }
  update_option(self::OPT,$results,false);
  wp_send_json_success(['next'=>$page+1,'done'=>$page >= (int)$q->max_num_pages,'checked'=>count($q->posts),'total_posts'=>(int)$q->found_posts,'missing'=>count($results),'added'=>$added]);
 }
 function update_node(&$nodes,$element,$newid,$newurl){
  if(!is_array($nodes))return false;
  foreach($nodes as &$node){if(!is_array($node))continue;
   if(($node['id']??'')===$element&&($node['widgetType']??'')==='video'){
    if(!isset($node['settings']['image_overlay'])||!is_array($node['settings']['image_overlay']))return false;
    $node['settings']['image_overlay']['id']=$newid;$node['settings']['image_overlay']['url']=$newurl;return true;
   }
   if(!empty($node['elements'])&&$this->update_node($node['elements'],$element,$newid,$newurl))return true;
  }unset($node);return false;
 }
 function restore(){
  $this->guard();$key=sanitize_text_field(wp_unslash($_POST['key']??''));$results=get_option(self::OPT,[]);
  if(!isset($results[$key]))wp_send_json_error('Item not found');$r=$results[$key];
  if($r['status']==='restored')wp_send_json_success($r);
  $relative=$r['relative'];if(!preg_match('~\.(jpe?g|png|webp|gif)$~i',$relative))wp_send_json_error('Unsupported image type');
  $source = !empty($r['url']) && preg_match('~^https?://~i', $r['url']) ? $r['url'] : '';
  if(empty($source) && defined('ELEMENTOR_IMAGE_AUDIT_SOURCE')){
   $source = rtrim(ELEMENTOR_IMAGE_AUDIT_SOURCE, '/').'/wp-content/uploads/'.implode('/',array_map('rawurlencode',explode('/',$relative)));
  }
  if(empty($source)) wp_send_json_error('Source image URL is missing or cannot be resolved');
  require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
  $temp=download_url($source,20);
  if(is_wp_error($temp)){$results[$key]['status']='failed';$results[$key]['note']=$temp->get_error_message();update_option(self::OPT,$results,false);wp_send_json_error('Original download failed: '.$temp->get_error_message());}
  $info=@getimagesize($temp);$size=@filesize($temp);
  if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp','image/gif'],true)||!$size||$size>12*1024*1024){@unlink($temp);wp_send_json_error('Source is not a valid image or exceeds 12MB');}
  $file=['name'=>wp_basename($relative),'tmp_name'=>$temp,'error'=>0,'size'=>$size,'type'=>$info['mime']];
  $attachment=media_handle_sideload($file,(int)$r['post_id'],'Restored video overlay');
  if(is_wp_error($attachment)){@unlink($temp);wp_send_json_error($attachment->get_error_message());}
  $url=wp_get_attachment_url($attachment);$raw=get_post_meta($r['post_id'],'_elementor_data',true);$nodes=is_string($raw)?json_decode($raw,true):$raw;
  if(!is_array($nodes)||!$this->update_node($nodes,$r['element'],$attachment,$url)){
   wp_delete_attachment($attachment,true);wp_send_json_error('Elementor widget changed; no content updated');
  }
  // Preserve Elementor's normal slashed JSON storage representation.
  update_post_meta($r['post_id'],'_elementor_data',wp_slash(wp_json_encode($nodes)));
  if(class_exists('Elementor\\Plugin')){try{\Elementor\Plugin::$instance->files_manager->clear_cache();}catch(Throwable $e){}}
  $results[$key]['status']='restored';$results[$key]['note']='Media ID '.$attachment;$results[$key]['url']=$url;update_option(self::OPT,$results,false);
  wp_send_json_success($results[$key]);
 }
 function page(){if(!current_user_can('manage_options'))return;$results=get_option(self::OPT,[]);?>
 <div class="wrap"><h1>Missing Elementor Video Overlay Images</h1><p>Scans posts in batches (20 per request). Only Elementor video overlay images are checked; no other widgets are changed. Scan does not modify posts. Restore is manual, one image at a time, from the original host.</p>
 <p><strong>Before restoring:</strong> take a database and uploads backup. Verify that the original website owns the images and permits retrieval. Test on staging.</p>
 <button class="button button-primary" id="mfia-scan">Scan all posts</button> <span id="mfia-status"></span>
 <table class="widefat striped" style="margin-top:20px"><thead><tr><th>Post</th><th>Missing image</th><th>Status</th><th>Action</th></tr></thead><tbody id="mfia-rows"></tbody></table></div>
 <script>
 (function(){const ajax=<?php echo wp_json_encode(admin_url('admin-ajax.php'));?>,nonce=<?php echo wp_json_encode(wp_create_nonce(self::NONCE));?>;const rows=document.getElementById('mfia-rows'),status=document.getElementById('mfia-status'),btn=document.getElementById('mfia-scan');let records=<?php echo wp_json_encode($results);?>;
 function el(tag,text){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;}
 function draw(){rows.replaceChildren();for(const [key,r] of Object.entries(records)){const tr=el('tr');let td=el('td');const a=el('a',r.post_title||'Post '+r.post_id);a.href=r.edit;a.target='_blank';a.rel='noopener';td.append(a,el('div','ID '+r.post_id));tr.append(td);td=el('td');const link=el('a',r.relative);link.href=r.url;link.target='_blank';link.rel='noopener';td.append(link);tr.append(td);tr.append(el('td',r.status+(r.note?' — '+r.note:'')));td=el('td');if(r.status!=='restored'){const b=el('button','Restore image');b.className='button';b.onclick=async()=>{b.disabled=true;b.textContent='Restoring...';try{const f=new FormData();f.append('action','mfia_restore');f.append('nonce',nonce);f.append('key',key);const res=await (await fetch(ajax,{method:'POST',body:f,credentials:'same-origin'})).json();if(!res.success)throw new Error(typeof res.data==='string'?res.data:'Restore failed');records[key]=res.data;draw();}catch(e){alert(e.message);b.disabled=false;b.textContent='Retry restore';}};td.append(b);}tr.append(td);rows.append(tr);}if(!Object.keys(records).length)rows.append(el('tr','No missing Elementor video overlay images found in current scan.'));}
 btn.onclick=async()=>{if(!confirm('Start a new scan? Previous scan results will be replaced.'))return;btn.disabled=true;records={};draw();let page=1;try{while(true){status.textContent='Scanning batch '+page+'...';const f=new FormData();f.append('action','mfia_scan');f.append('nonce',nonce);f.append('page',page);const res=await (await fetch(ajax,{method:'POST',body:f,credentials:'same-origin'})).json();if(!res.success)throw new Error('Scan request failed');status.textContent='Scanned up to '+(page*20)+' of '+res.data.total_posts+' posts; '+res.data.missing+' missing overlays';if(res.data.done)break;page=res.data.next;}status.textContent+=' — complete. Reload page to view results.';location.reload();}catch(e){status.textContent=e.message;}finally{btn.disabled=false;}};draw();})();
 </script><?php }
}
new Elementor_Image_Audit();
