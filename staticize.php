<?php
/**
 * @package Staticize
 * @version 1.0
 */
/*
Plugin Name: Staticize
Plugin URI: http://checkmyworking.com/staticize
Description: Render posts as static HTML
Author: Christian Lawson-Perfect
Version: 1.0
Author URI: http://somethingorotherwhatever.com
*/

namespace staticize;

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define('POSTS_DIR','/src/content/posts');
define('CONTENT_FILENAME','.md');

function get_relative_permalink( $url ) {
    return str_replace( home_url(), "", $url );
}

class Staticize {

    protected static $instance = NULL;

    protected $output_dir = '';
    protected $static_home_url = '';
    protected $static_uploads_root = '';

    protected $last_build_time = '';

    public static function get_instance() {
        NULL == self::$instance and self::$instance = new self;
        return self::$instance;
    }

    public function get_option($name) {
        return get_option('staticize_'.$name);
    }
    public function set_option($name,$value) {
        return update_option('staticize_'.$name,$value);
    }

    public function setup() {
        $settings = get_option('staticize_options');
        $this->output_dir = $settings['staticize_output_dir'];
        $this->static_home_url = $settings['static_home_url'];
        $this->static_uploads_root = $this->static_home_url . '/uploads';
        $this->last_build_time = $this->get_option('last_build');

        if(is_admin()) {
            add_action('admin_menu', function() {
                add_menu_page('Staticize Admin','Staticize', 'manage_options','staticize',array(&$this,'admin_page'));
                add_submenu_page('staticize','Staticize Options','Options','manage_options','staticize-options',array(&$this,'options_page'));
            });
            add_action('admin_init',function() {
                register_setting('staticize','staticize_options');
                add_settings_section('staticize_settings','Staticize plugin settings',array(&$this,'settings_callback'),'staticize');
                add_settings_field(
                    'staticize_output_dir',
                    'Output directory',
                    array(&$this,'setting_text_field_cb'),
                    'staticize',
                    'staticize_settings',
                    [
                        'label_for' => 'staticize_output_dir',
                        'class' => 'staticize_row'
                    ]
                );
                add_settings_field(
                    'staticize_static_home_url',
                    'Home URL of the static site',
                    array(&$this,'setting_text_field_cb'),
                    'staticize',
                    'staticize_settings',
                    [
                        'label_for' => 'staticize_static_home_url',
                        'class' => 'staticize_row'
                    ]
                );
            });
        }

        add_action('post_updated',function($post_id,$post_after,$post_before) {
            $this->render_post($post_id);
        });
        add_action('profile_update',array(&$this,'make_authors_dict'));

        add_action('trashed_post',function($postid) {
            $this->delete_post($postid);
        });
    }

    public function settings_callback() {
    }

    public function setting_text_field_cb($args) {
        $options = get_option('staticize_options');
        ?>
        <input 
            type="text" 
            id="<?= esc_attr($args['label_for']); ?>" 
            name="staticize_options[<?= esc_attr($args['label_for']); ?>]"
            value="<?= $options[$args['label_for']]; ?>"
        >
        <p class="description">The path to the location in which to place output files.</p>
        <?php
    }

    public function admin_page() {
        ?>
        <h1>Staticize</h1>
        <?php

        ?>
        <p>Last build: <?php echo $this->last_build_time; ?></p>

        <form method="POST">
        <input type="hidden" name="rebuild" value="true">
        <label>Force rebuild: <input type="checkbox" name="force_rebuild" checked="true"></label>
        <button>Build</button>
        </form>
        <?php

        if($_POST['rebuild']) {
            $force_rebuild = $_POST['force_rebuild'];
            $this->render_all_posts($force_rebuild);
            $this->make_authors_dict();
        }
    }

    public function options_page() {
        if(!current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['settings-updated'])) {
            add_settings_error('staticize_messages', 'staticize_message', __('Settings Saved', 'staticize'), 'updated');
        }
        settings_errors('staticize_messages');
        ?>
        <div class="wrap">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="POST">
                <?php
                settings_fields('staticize');
                do_settings_sections('staticize');
                submit_button('Save settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function render_all_posts($force_rebuild=false) {
        $last_build_time = get_option('staticize_last_build');

        $posts = new \WP_Query('orderby=modified&nopaging=true');

        ?>
            <h2>Rebuilding posts</h2>
            <ul>
        <?php

        $this->built = 0;

        while($posts->have_posts()) {
            $posts->the_post();
            $modified_date = get_the_modified_date('U');
            if($force_rebuild || $modified_date > $this->last_build_time) {
                $this->render_post();
            }
        }
        ?></ul><?php

        echo "<p>$this->built posts built.</p>";

        $this->last_build_time = time();
        $this->set_option('last_build',$this->last_build_time);
    }

    public function render_post($post_obj=NULL) {
        $GLOBALS['more'] = true;
        if(!empty($post_obj)) {
            $GLOBALS['post'] = $post_obj;
            setup_postdata($post_obj);
        }
        $post = $post or $post_obj;

        $title = get_the_title();
        echo "<li>$title</li>";

        $slug = get_post_field('post_name');
        $publish_date = get_the_date('Y-m-d');

        //$permalink = get_relative_permalink(get_permalink());
        $destination = $this->output_dir . POSTS_DIR;
        if(!file_exists($destination)) {
            mkdir($destination,0700,true);
        }

        $tags = array();
        $posttags = get_the_tags();
        if($posttags) {
            foreach($posttags as $tag) {
                $tags[] = '"'.$tag->name.'"';
            }
        }
        $categories = array();
        $postcategories = get_the_category();
        if($postcategories) {
            foreach($postcategories as $category) {
                $categories[] = '"'.$category->name.'"';
            }
        }


        $authors = array();
        $postauthors = get_coauthors();
        foreach($postauthors as $author) {
            $authors[] = '"'.$author->user_login.'"';
        }

        $status = get_post_status();
        $is_draft = $status != 'publish';

        ob_start();

?>---
layout: "post"
title: "<?php the_title(); ?>"
slug: "<?php echo get_post_field('post_name'); ?>"
date: "<?php the_date('Y-m-d'); ?>" 
categories: [<?php echo implode(',',$categories); ?>]
tags: [<?php echo implode(',',$tags); ?>]
authors: [<?php echo implode(',',$authors); ?>]
draft: <?php echo $is_draft ? 'true' : 'false'; ?> 
status: "<?php echo $status; ?>"
---
<?php

        $content = apply_filters('the_content',get_the_content());
        $content = str_replace('{{','{{ \'{{\' }}',$content);
        $content = preg_replace("/<span id=\"more-\d+\">(.*)<\/span>/","\n<p>--more $1--</p>\n",$content);
        echo $content;

        $contents = ob_get_clean();
        $contents = $this->fix_upload_url($contents);
        $filename = $this->post_filename(get_the_ID());
        file_put_contents($destination.'/'.$filename,$contents);

        $this->built += 1;
    }

    public function make_authors_dict() {
        $users = get_users();
        $user_dict = array();
        foreach($users as $user) {
            $user_dict[$user->user_login] = array(
                'nicename' => $user->user_nicename,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'id' => $user->id,
                'url' => $user->user_url,
                'bio' => $user->get('description'),
                'avatar' => $this->fix_upload_url(get_wp_user_avatar_src($user->id))
            );
        }

        file_put_contents($this->output_dir . "/authors.json", json_encode($user_dict, JSON_PRETTY_PRINT));

    }

    protected function post_filename($id) {
        $date = get_the_date('Y-m-d');
        return "$date-post-" . $id . CONTENT_FILENAME;
    }

    protected function fix_upload_url($str) {
        $uploads_url = home_url('/wp-content/uploads');
        return str_replace($uploads_url, $this->static_uploads_root, $str);
    }

    public function delete_post($postid) {
        $filename = $this->output_dir . POSTS_DIR . '/' . $this->post_filename($postid);
        file_put_contents('/tmp/poo',$filename);
        if(file_exists($filename)) {
            unlink($filename);
        }
    }

}

function install() {
    $staticize = Staticize::get_instance();
    $staticize->set_option('last_build',0);
}

add_action('plugins_loaded',
    array(Staticize::get_instance(),'setup')
);
