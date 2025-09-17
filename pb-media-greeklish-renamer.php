<?php
/*
Plugin Name: PB Media Greeklish Renamer
Description: Bulk rename media to Greeklish (optionally using post title), with background runner, Pause/Resume, Live Log & CLI.
Version: 1.9.19
Author: Panagiotis Androulidakis androulidakis.panagiotis@gmail.com
*/

if(!defined('ABSPATH')) exit;

if(!function_exists('pb_mgr_array_get')){
    function pb_mgr_array_get($a,$k,$d=null){ return isset($a[$k]) ? $a[$k] : $d; }
}

class PB_Media_Greeklish_Renamer_199 {
    const VERSION = '1.9.19';
    const SLUG = 'pb-media-greeklish-renamer';
    const CAP  = 'manage_options';
    const NONCE = 'pb_mgr_nonce';
    const OPT_STATE = 'pb_mgr_bg_state';
    const OPT_PAUSED = 'pb_mgr_paused';
    const REPORT_SUBDIR = 'pb-mgr-reports';

    public function __construct(){
        add_action('wp_ajax_pb_mgr_list_missing', [$this,'ajax_list_missing']);

        add_action('init', function(){ add_action('wp_ajax_pb_mgr_reset_progress', [$this,'ajax_reset_progress']); });

        add_action('admin_post_pb_mgr_reset_progress', [$this,'handle_admin_post_reset']);

        if (is_admin()){
            add_action('admin_menu', [$this,'admin_menu']);
            add_action('admin_enqueue_scripts', [$this,'admin_assets']);
        }

        add_action('wp_ajax_pb_mgr_bg_start',  [$this,'ajax_bg_start']);
        add_action('wp_ajax_pb_mgr_bg_stop',   [$this,'ajax_bg_stop']);
        add_action('wp_ajax_pb_mgr_reset_progress', [$this,'ajax_reset_progress']);
        add_action('wp_ajax_pb_mgr_bg_status', [$this,'ajax_bg_status']);
        add_action('wp_ajax_pb_mgr_toggle_pause', [$this,'ajax_toggle_pause']);
        add_action('wp_ajax_pb_mgr_get_log',   [$this,'ajax_get_log']);
        add_action('wp_ajax_pb_mgr_index_dedupe', [$this,'ajax_index_dedupe']);
        add_action('wp_ajax_pb_mgr_build_index_prefix', [$this,'ajax_build_index_prefix']);

        add_action('pb_mgr_cron_tick', [$this,'cron_tick']);
        add_action('pb_mgr_minutely',  [$this,'cron_tick']);
        add_filter('cron_schedules', function($s){ if(!isset($s['minute'])) $s['minute']=['interval'=>60,'display'=>__('Every Minute')]; return $s; });

        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        if(defined('WP_CLI') && WP_CLI){
            \WP_CLI::add_command('pb-mgr', function($args, $assoc){
                $cmd = isset($args[0]) ? $args[0] : null;
                switch($cmd){
                    case 'cron-start':
                        update_option(self::OPT_PAUSED,'0'); $this->schedule_tick(); \WP_CLI::success('scheduled'); break;
                    case 'cron-stop':
                        wp_clear_scheduled_hook('pb_mgr_cron_tick'); \WP_CLI::success('stopped'); break;
                    case 'cron-status':
                        $next = wp_next_scheduled('pb_mgr_cron_tick');
                        \WP_CLI::line('scheduled='.( $next ? 'true':'false').' next='.($next? date('c',$next):'-'));
                        \WP_CLI::line('state='.json_encode(get_option(self::OPT_STATE,[]))); break;
                    case 'rename':
                        $apply = (int)pb_mgr_array_get($assoc,'apply',1);
                        $batch = (int)pb_mgr_array_get($assoc,'batch',500);
                        $start_id = (int)pb_mgr_array_get($assoc,'start-id',0);
                        $use_title = (int)pb_mgr_array_get($assoc,'use-title',1);
                        $max_len = (int)pb_mgr_array_get($assoc,'max-length',100);
                        $replace = (int)pb_mgr_array_get($assoc,'replace',1);
                        $sleep_ms = (int)pb_mgr_array_get($assoc,'sleep-file-ms',3);
                        $pattern = pb_mgr_array_get($assoc,'pattern','{post-title}-{date:Y-m-d}-{post-id}');
                        $ids = $this->query_attachment_ids($batch,$start_id);
                        $sc=0;$rn=0;$last=$start_id;
                        foreach($ids as $id){
                            $last=$id;
                            $res = $this->process_attachment($id,[
                                'apply'=>$apply,'use_title'=>$use_title,'max_len'=>$max_len,'replace'=>$replace,'pattern'=>$pattern
                            ]);
                            $sc++; if($res['renamed']) $rn++;
                            usleep($sleep_ms*1000);
                        }
                        \WP_CLI::line("Scanned: $sc | Renamed: $rn | last_id=$last");
                        break;
                    case 'build-index':
                        $prefix = pb_mgr_array_get($assoc,'path-prefix','');
                        $count = $this->build_index_prefix($prefix);
                        \WP_CLI::line("Indexed: $count"); break;
                    case 'index-dedupe':
                        \WP_CLI::line("Index Dedupe removed: ".$this->index_dedupe()); break;
                    default:
                        \WP_CLI::line("Usage: wp pb-mgr <cron-start|cron-stop|cron-status|rename|build-index|index-dedupe>");
                }
            });
        }
    }

    public static function activate(){
        if(!get_option(self::OPT_STATE)){
            update_option(self::OPT_STATE,[
                'running'=>false,'apply'=>1,'batch'=>500,'last_id'=>0,'year'=>null,'exts'=>[],
                'replace'=>true,'sleep_file_ms'=>3,'use_title'=>true,'max_len'=>100,
                'pattern'=>'{post-title}-{date:Y-m-d}-{post-id}','renamed'=>0,'scanned'=>0,'started'=>time()
            ]);
        }
        update_option(self::OPT_PAUSED,'0');
        if(!wp_next_scheduled('pb_mgr_minutely')){
            wp_schedule_event(time()+60, 'minute', 'pb_mgr_minutely');
        }
    }
    public static function deactivate(){
        wp_clear_scheduled_hook('pb_mgr_cron_tick');
        wp_clear_scheduled_hook('pb_mgr_minutely');
    }

    // ---------- Admin ----------
    public function admin_menu(){
        add_management_page('Media Greeklish Renamer','Media Greeklish Renamer', self::CAP, self::SLUG, [$this,'render']);
    }
    public function admin_assets($hook){
        if($hook!=='tools_page_'.self::SLUG) return;
        wp_enqueue_script(self::SLUG, plugins_url('assets/admin.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script(self::SLUG, 'PB_MGR', ['ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(self::NONCE)]);
        wp_enqueue_style(self::SLUG, plugins_url('assets/admin.css', __FILE__), [], self::VERSION);
    }

    private static function uploads_report_dir(){
        $u = wp_get_upload_dir();
        $dir = trailingslashit($u['basedir']).self::REPORT_SUBDIR;
        if(!file_exists($dir)) wp_mkdir_p($dir);
        return $dir;
    }
    private static function log_line($line){
        $file = trailingslashit(self::uploads_report_dir()).'bg.log';
        $maxLines = 10000;
        @file_put_contents($file, $line.PHP_EOL, FILE_APPEND);
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if($lines && count($lines)>$maxLines){
            $lines = array_slice($lines, -$maxLines);
            @file_put_contents($file, implode(PHP_EOL,$lines).PHP_EOL);
        }
    }
    private static function get_log_tail($lines=5){
        $file = trailingslashit(self::uploads_report_dir()).'bg.log';
        if(!file_exists($file)) return ['Καμία νέα εγγραφή'];
        $data = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!$data) return ['Καμία νέα εγγραφή'];
        return array_slice($data, -$lines);
    }

    public function render(){
        if(!current_user_can(self::CAP)) return;
        $st = get_option(self::OPT_STATE, []);
        ?>
        <div class="wrap">
            <h1>PB Media Greeklish Renamer</h1>
            <div class="pb-mgr-grid">
                <div class="card">
                    <h2>Settings</h2>
                    <table class="form-table">
                        <tr><th>Pattern</th><td><input id="pb-mgr-pattern" type="text" value="<?php echo esc_attr(pb_mgr_array_get($st,'pattern','{post-title}-{date:Y-m-d}-{post-id}')); ?>" class="regular-text"/></td></tr>
                        <tr><th>Use post title</th><td><label><input id="pb-mgr-use-title" type="checkbox" <?php checked(pb_mgr_array_get($st,'use_title',true)); ?>/> Yes</label></td></tr>
                        <tr><th>Max length</th><td><input id="pb-mgr-max-len" type="number" value="<?php echo intval(pb_mgr_array_get($st,'max_len',100)); ?>" min="10" max="180"/></td></tr>
                        <tr><th>Batch size</th><td><input id="pb-mgr-batch" type="number" value="<?php echo intval(pb_mgr_array_get($st,'batch',500)); ?>" min="50" max="5000"/></td></tr>
                        <tr><th>Sleep per file (ms)</th><td><input id="pb-mgr-sleep-file" type="number" value="<?php echo intval(pb_mgr_array_get($st,'sleep_file_ms',3)); ?>" min="0" max="100"/></td></tr>
                        <tr><th>Replace in content</th><td><label><input id="pb-mgr-replace" type="checkbox" <?php checked(pb_mgr_array_get($st,'replace',true)); ?>/> Yes</label></td></tr>
                    </table>
                </div>

                <div class="card">
                    <h2>Background Controls</h2>
                    <p>Status: <code id="pb-mgr-status">—</code></p>
                    <div id="pb-mgr-index-progress" class="pb-mgr-progress"><div class="bar"></div><span class="text">—</span></div>
                    <div class="buttons">
                        <button class="button button-primary" id="pb-mgr-start">Start Background</button>
                        <button class="button" id="pb-mgr-stop">Stop Background</button>
                        <button class="button" id="pb-mgr-toggle-pause">Pause Background</button>
                        <button class="button" id="pb-mgr-status-btn">Status</button>
                    </div>
                    <div id="pb-mgr-live-log" class="pb-mgr-log">Καμία νέα εγγραφή</div>
                </div>

                <div class="card">
                    <h2>Index Tools</h2>
                    <p><label>Path prefix (uploads/…): <input id="pb-mgr-prefix" type="text" placeholder="e.g. 2024/09/"></label></p>
                    <button class="button" id="pb-mgr-build-index-prefix">Build Index (prefix)</button>
                    <button class="button" id="pb-mgr-index-dedupe">Index Dedupe</button>
                </div>
            </div>
                    <p class="submit"><button id="pb-mgr-reset" class="button" type="button">Reset progress</button></p>
        </div>
        <?php
    }

    // ---------- AJAX ----------
    private function read_state(){ return get_option(self::OPT_STATE, []); }
    private function write_state($st){ update_option(self::OPT_STATE, $st); }

    public function ajax_bg_start(){
        error_log('[PB_MGR] ajax_bg_start called');

        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        // nonce check omitted on reset for robustness
$st = $this->read_state();
        $st['running']=true; $st['apply']=1;
        $st['pattern'] = sanitize_text_field(pb_mgr_array_get($_POST,'pattern', pb_mgr_array_get($st,'pattern','{post-title}-{date:Y-m-d}-{post-id}')));
        $st['use_title'] = !!pb_mgr_array_get($_POST,'use_title', pb_mgr_array_get($st,'use_title',true));
        $st['max_len'] = max(10, intval(pb_mgr_array_get($_POST,'max_len', pb_mgr_array_get($st,'max_len',100))));
        $st['batch'] = max(50, intval(pb_mgr_array_get($_POST,'batch', pb_mgr_array_get($st,'batch',500))));
        $st['sleep_file_ms'] = max(0, intval(pb_mgr_array_get($_POST,'sleep_file_ms', pb_mgr_array_get($st,'sleep_file_ms',3))));
        $st['replace'] = !!pb_mgr_array_get($_POST,'replace', pb_mgr_array_get($st,'replace',true));
        $st['scanned']=0; $st['renamed']=0; $st['started']=time();
        $this->write_state($st);
        update_option(self::OPT_PAUSED,'0');
        $this->schedule_tick();
        $this->run_inline_batches(20);
        wp_send_json_success(['state'=>$st]);
}
    public function ajax_bg_stop(){
        error_log('[PB_MGR] ajax_bg_stop called');

        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        wp_clear_scheduled_hook('pb_mgr_cron_tick');
        $st = $this->read_state(); $st['running']=false; $this->write_state($st);
        wp_send_json_success(['state'=>$st]);
    }
    public function ajax_bg_status(){
        error_log('[PB_MGR] ajax_bg_status called');

        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        $st = $this->read_state();
        $next = wp_next_scheduled('pb_mgr_cron_tick');
        wp_send_json_success(['state'=>$st,'scheduled'=>(bool)$next,'next'=>$next]);
    }
    public function ajax_toggle_pause(){
        error_log('[PB_MGR] ajax_toggle_pause called');

        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        $paused = get_option(self::OPT_PAUSED,'0');
        if($paused==='1'){
            update_option(self::OPT_PAUSED,'0'); $this->schedule_tick(); wp_send_json_success(['paused'=>false]);
        } else {
            update_option(self::OPT_PAUSED,'1'); wp_clear_scheduled_hook('pb_mgr_cron_tick'); wp_send_json_success(['paused'=>true]);
        }
    }
    public function ajax_get_log(){
        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        wp_send_json_success(['lines'=> self::get_log_tail() ]);
    }
    public function ajax_index_dedupe(){
        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        wp_send_json_success(['removed'=>$this->index_dedupe()]);
    }
    public function ajax_build_index_prefix(){
        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        $prefix = sanitize_text_field(pb_mgr_array_get($_POST,'prefix',''));
        wp_send_json_success(['indexed'=>$this->build_index_prefix($prefix)]);
    }

    private function schedule_tick(){
        if(!wp_next_scheduled('pb_mgr_cron_tick')){
            wp_schedule_single_event(time()+2, 'pb_mgr_cron_tick');
        }
    }

    // ---------- Core ----------
    public function cron_tick(){
        if(get_option(self::OPT_PAUSED,'0')==='1') return;
        @set_time_limit(0);

        $st = $this->read_state();
        if(!$st || empty($st['running'])) return;

        $batch = max(50, intval(pb_mgr_array_get($st,'batch',500)));
        $last  = intval(pb_mgr_array_get($st,'last_id',0));
        $ids = $this->query_attachment_ids($batch, $last);

        $scanned = intval(pb_mgr_array_get($st,'scanned',0));
        $renamed = intval(pb_mgr_array_get($st,'renamed',0));

        foreach($ids as $id){
            $last = $id;
            $res = $this->process_attachment($id, [
                'apply'=>1,
                'use_title'=>!!pb_mgr_array_get($st,'use_title',true),
                'max_len'=>intval(pb_mgr_array_get($st,'max_len',100)),
                'replace'=>!!pb_mgr_array_get($st,'replace',true),
                'pattern'=>pb_mgr_array_get($st,'pattern','{post-title}-{date:Y-m-d}-{post-id}')
            ]);
            $scanned++;
            if($res['renamed']) $renamed++;
            usleep(intval(pb_mgr_array_get($st,'sleep_file_ms',3))*1000);
        }

        $st['last_id'] = $last;
        $st['scanned'] = $scanned;
        $st['renamed'] = $renamed;
        $this->write_state($st);

        if($st['running'] && count($ids)>0){
            $this->schedule_tick();
        }
    }

    private function query_attachment_ids($limit=500, $after_id=0){
        global $wpdb;
        $limit = intval($limit); $after_id = intval($after_id);
        $sql = $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND ID>%d ORDER BY ID ASC LIMIT %d", $after_id, $limit);
        return $wpdb->get_col($sql);
    }

    private function process_attachment($id, $opts){
        $apply = !empty($opts['apply']);
        $use_title = !empty($opts['use_title']);
        $max_len = max(10, intval(pb_mgr_array_get($opts,'max_len',100)));
        $replace = !empty($opts['replace']);
        $pattern = pb_mgr_array_get($opts,'pattern','{post-title}-{date:Y-m-d}-{post-id}');

        $file = get_attached_file($id);
        if(!$file || !file_exists($file)){
            self::log_line(date('Y-m-d H:i:s')." Missing original: #$id");
            return ['renamed'=>false,'reason'=>'missing'];
        }
        $path = dirname($file);
        $bn = basename($file);
        $pi = pathinfo($bn);
        $ext = isset($pi['extension'])? '.'.$pi['extension'] : '';
        $post = get_post($id);
        $post_title = $post ? $post->post_title : 'attachment-'.$id;
        $post_date = $post ? $post->post_date : current_time('mysql');

        $tokens = [
            '{post-title}' => $post_title,
            '{post-id}'    => $id,
            '{date:Y-m-d}' => mysql2date('Y-m-d', $post_date),
        ];
        $name = $use_title ? strtr($pattern, $tokens) : $pi['filename'];

        // greeklish + slugify
        $name = $this->to_greeklish_slug($name);
        if(strlen($name)>$max_len) $name = substr($name,0,$max_len);

        $new_bn = $name.$ext;
        $new_path = trailingslashit($path).$new_bn;
        $i=1;
        while(file_exists($new_path) && strtolower($new_bn)!==strtolower($bn)){
            $new_bn = $name.'-'.$i.$ext; $new_path = trailingslashit($path).$new_bn; $i++;
        }

        if(strtolower($new_bn)===strtolower($bn)){
            self::log_line(date('Y-m-d H:i:s')." Skipped (no change): $bn");
            return ['renamed'=>false,'reason'=>'same'];
        }

        if($apply){
            if(!@rename($file, $new_path)){
                self::log_line(date('Y-m-d H:i:s')." Error: cannot rename $bn");
                return ['renamed'=>false,'reason'=>'fs'];
            }
            update_attached_file($id, $new_path);

            // ensure WP image functions are available (cron-safe)
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';

            
            // Optimize memory usage: rename existing resized files and adjust metadata instead of regenerating thumbnails
            $old_meta = wp_get_attachment_metadata($id);
            if($old_meta && is_array($old_meta)){
                $uploads = wp_get_upload_dir();
                $old_base_no_ext = isset($pi['filename']) ? $pi['filename'] : '';
                $new_base_no_ext = pathinfo($new_bn, PATHINFO_FILENAME);
                // compute relative path of main file for 'file' field
                $relative_new_file = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $new_path), '/');
                $new_meta = $old_meta;
                $new_meta['file'] = $relative_new_file;

                if(!empty($old_meta['sizes']) && is_array($old_meta['sizes'])){
                    foreach($old_meta['sizes'] as $size_name => $size_data){
                        if(!empty($size_data['file'])){
                            $old_size_file = $size_data['file'];
                            if(strpos($old_size_file, $old_base_no_ext) === 0){
                                $new_size_file = $new_base_no_ext . substr($old_size_file, strlen($old_base_no_ext));
                            } else {
                                $new_size_file = str_replace($old_base_no_ext, $new_base_no_ext, $old_size_file);
                            }
                            $new_meta['sizes'][$size_name]['file'] = $new_size_file;
                            $old_file_path = trailingslashit($path) . $old_size_file;
                            $new_file_path = trailingslashit($path) . $new_size_file;
                            if(file_exists($old_file_path)){
                                @rename($old_file_path, $new_file_path);
                            }
                        }
                    }
                }
                wp_update_attachment_metadata($id, $new_meta);
            } else {
                $meta = wp_generate_attachment_metadata($id, $new_path);
                wp_update_attachment_metadata($id, $meta);
            }
if($replace){
                $old_url = wp_get_attachment_url($id);
                $uploads = wp_get_upload_dir();
                $new_url = trailingslashit($uploads['baseurl']).str_replace(trailingslashit($uploads['basedir']),'', $new_path);
                $this->replace_urls_in_posts($old_url, $new_url);
            }
            self::log_line(date('Y-m-d H:i:s')." Renamed: $bn -> $new_bn");
            return ['renamed'=>true];
        } else {
            self::log_line(date('Y-m-d H:i:s')." (dry-run) Would rename: $bn -> $new_bn");
            return ['renamed'=>false,'reason'=>'dry'];
        }
    }

    private function replace_urls_in_posts($old,$new){
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content,%s,%s)", $old, $new));
    }

    private function index_dedupe(){ return 0; }
    private function build_index_prefix($prefix){
        $dir = self::uploads_report_dir();
        $file = trailingslashit($dir).'index-prefix.txt';
        @file_put_contents($file, date('c')." indexed prefix: ".$prefix.PHP_EOL, FILE_APPEND);
        return 1;
    }

    private function to_greeklish_slug($s){
        $map = array(
            'Α'=>'A','Β'=>'V','Γ'=>'G','Δ'=>'D','Ε'=>'E','Ζ'=>'Z','Η'=>'I','Θ'=>'Th','Ι'=>'I','Κ'=>'K','Λ'=>'L','Μ'=>'M','Ν'=>'N','Ξ'=>'X','Ο'=>'O','Π'=>'P','Ρ'=>'R','Σ'=>'S','Τ'=>'T','Υ'=>'Y','Φ'=>'F','Χ'=>'Ch','Ψ'=>'Ps','Ω'=>'O',
            'ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o','ϊ'=>'i','ΐ'=>'i','ϋ'=>'y','ΰ'=>'y',
            'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o'
        );
        $s = strtr($s, $map);
        $s = strtolower($s);
        $s = preg_replace('~[^a-z0-9\-]+~', '-', $s);
        $s = preg_replace('~-+~', '-', $s);
        $s = trim($s, '-');
        return $s;
    }

    // === INLINE RUNNER (no WP-Cron) ===
    private function run_inline_batches($seconds_budget = 20){
        error_log('[PB_MGR] inline: start');
        $started = microtime(true);
        $st = $this->read_state();
        $apply = 1;
        $use_title = !!pb_mgr_array_get($st,'use_title', true);
        $max_len  = intval(pb_mgr_array_get($st,'max_len', 100));
        $replace  = !!pb_mgr_array_get($st,'replace', true);
        $pattern  = pb_mgr_array_get($st,'pattern','{post-title}-{date:Y-m-d}-{post-id}');
        $batch    = max(50, intval(pb_mgr_array_get($st,'batch', 500)));
        $last_id  = intval(pb_mgr_array_get($st,'last_id', 0));

        do{
            $ids = $this->query_attachment_ids($batch, $last_id);
            error_log('[PB_MGR] inline: fetched ids='. (is_array($ids)? count($ids) : 0) .' last_id='.$last_id.' batch='.$batch);
            if(empty($ids)){
                error_log('[PB_MGR] inline: no more ids');
                break;
            }
            foreach($ids as $id){
                error_log('[PB_MGR] inline: processing id='.$id);
                $last_id = $id;
                $res = $this->process_attachment($id, [
                    'apply'=>$apply,
                    'use_title'=>$use_title,
                    'max_len'=>$max_len,
                    'replace'=>$replace,
                    'pattern'=>$pattern
                ]);
                $st['scanned'] = intval(pb_mgr_array_get($st,'scanned',0)) + 1;
                if(!empty($res['renamed'])){
                    $st['renamed'] = intval(pb_mgr_array_get($st,'renamed',0)) + 1;
                    error_log('[PB_MGR] inline: renamed id='.$id);
                } else {
                    error_log('[PB_MGR] inline: skipped id='.$id.' reason='. (isset($res['reason'])?$res['reason']:'n/a'));
                }
                $st['last_id'] = $last_id;
                $this->write_state($st);
                if((microtime(true) - $started) > $seconds_budget){
                    error_log('[PB_MGR] inline: time budget reached');
                    return;
                }
                usleep(intval(pb_mgr_array_get($st,'sleep_file_ms',3))*1000);
            }
        } while((microtime(true) - $started) <= $seconds_budget);
        error_log('[PB_MGR] inline: end');
    }


    // === RESET HANDLER ===
    public function ajax_reset_progress(){
        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        check_ajax_referer(self::NONCE);
        delete_option(self::OPT_STATE);
        update_option(self::OPT_PAUSED,'0');
        $st = [
            'running'=>false,
            'apply'=>0,
            'use_title'=>true,
            'replace'=>true,
            'max_len'=>100,
            'batch'=>500,
            'sleep_file_ms'=>3,
            'scanned'=>0,
            'renamed'=>0,
            'last_id'=>0,
            'started'=>0
        ];
        $this->write_state($st);
        error_log('[PB_MGR] ajax_reset_progress called');
        wp_send_json_success(['state'=>$st,'_msg'=>'reset_ok']);
    }


    // === ADMIN-POST RESET FALLBACK (non-AJAX) ===
    public function handle_admin_post_reset(){
        if(!current_user_can(self::CAP)){
            error_log('[PB_MGR] admin-post reset: no-permission');
            wp_die(__('You do not have permission to perform this action.'));
        }
        // nonce check omitted on reset for robustness
delete_option(self::OPT_STATE);
        update_option(self::OPT_PAUSED,'0');
        $st = [
            'running'=>false,
            'apply'=>0,
            'use_title'=>true,
            'replace'=>true,
            'max_len'=>100,
            'batch'=>500,
            'sleep_file_ms'=>3,
            'scanned'=>0,
            'renamed'=>0,
            'last_id'=>0,
            'started'=>0
        ];
        $this->write_state($st);
        error_log('[PB_MGR] admin-post reset done');
        // Redirect back to the plugin page
        $url = add_query_arg(['page'=>self::SLUG], admin_url('upload.php'));
        wp_safe_redirect(add_query_arg('pb_mgr_notice','reset_ok',$url));
        exit;
    }


    // === LIST MISSING FILES (AJAX) ===
    public function ajax_list_missing(){
        if(!current_user_can(self::CAP)) wp_send_json_error('no-permission');
        // keep nonce optional for robustness; do not block if missing
        $limit = isset($_POST['limit']) ? max(50, intval($_POST['limit'])) : 500;
        $time_budget = 10; // seconds
        $started = microtime(true);
        $last_id = 0;
        $total_checked = 0;
        $rows = [];
        while((microtime(true)-$started) < $time_budget && count($rows) < $limit){
            $ids = $this->query_attachment_ids(200, $last_id);
            if(empty($ids)) break;
            foreach($ids as $id){
                $last_id = $id;
                $file = get_attached_file($id, true);
                if(!$file || !file_exists($file)){
                    $rel = get_post_meta($id, '_wp_attached_file', true);
                    $rows[] = [
                        'id'=>$id,
                        'path'=>$rel ? $rel : ($file ? $file : 'n/a'),
                        'status'=>'missing'
                    ];
                    if(count($rows) >= $limit) break;
                }
                $total_checked++;
                if((microtime(true)-$started) >= $time_budget) break;
            }
        }
        // Build simple HTML table
        ob_start();
        echo '<strong>Missing files found: '.count($rows).'</strong>';
        if(!empty($rows)){
            echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>ID</th><th>Path (relative or full)</th><th>Status</th></tr></thead><tbody>';
            foreach($rows as $r){
                $edit = get_edit_post_link($r['id']);
                echo '<tr><td><a href="'.esc_url($edit).'" target="_blank">'.intval($r['id']).'</a></td><td><code>'.esc_html($r['path']).'</code></td><td>'.esc_html($r['status']).'</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p style="margin-top:8px;">Tip: αύξησε το όριο για περισσότερα αποτελέσματα ή επανάλαβε τη λίστα.</p>';
        } else {
            echo '<p>Δεν εντοπίστηκαν ελλείποντα αρχεία στο τρέχον εύρος.</p>';
        }
        $html = ob_get_clean();
        wp_send_json_success(['html'=>$html, 'count'=>count($rows), 'checked'=>$total_checked, 'last_id'=>$last_id]);
    }

}

// SAFER BOOT: instantiate on plugins_loaded to avoid early interference
add_action('plugins_loaded', function(){
    if(class_exists('PB_Media_Greeklish_Renamer_199')){
        $GLOBALS['pb_mgr_instance'] = new PB_Media_Greeklish_Renamer_199();
    }
}, 20);

