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

define('POST_DIRS',[
    'post' => '/src/content/posts',
    'page' => '/src/content'
]);
define('CONTENT_FILENAME','.html');

function get_relative_permalink( $url ) {
    return str_replace( home_url(), "", $url );
}

class Staticize {

    protected static $instance = NULL;

    public $output_dir = '';
    protected $static_home_url = '';
    protected $static_uploads_root = '';

    protected $last_build_time = '';

    public $category_dict = array();

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
        $this->make_categories_dict();

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
            $post = new StaticizePost($this,$post_id,get_post($post_id));
            $post->render();
        });
        add_action('profile_update',array(&$this,'write_authors_dict'));

        add_action('trash_post',function($postid) {
            $post = new StaticizePost($this,$postid);
            $post->delete_post();
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
            <button>Build</button>
        </form>
        <?php

        if($_POST['rebuild']) {
            $this->write_categories_dict();
            $this->render_all(true);
            $this->write_authors_dict();
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

    public function render_all($force_rebuild=false) {
        $last_build_time = get_option('staticize_last_build');

        $posts = new \WP_Query(['orderby' => 'modified','nopaging' => true, 'post_type' => 'page']);

        ?>
            <h2>Rebuilding pages</h2>
            <ul>
        <?php

        $this->built = 0;

        while($posts->have_posts()) {
            $posts->the_post();
            $modified_date = get_the_modified_date('U');
            if($force_rebuild || $modified_date > $this->last_build_time) {
                $post = new StaticizePost($this,$GLOBALS['post']->ID);
                $post->render();
                $this->built += 1;
            }
        }
        ?></ul><?php

        echo "<p>$this->built pages built.</p>";
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
                $post = new StaticizePost($this,$GLOBALS['post']->ID);
                $post->render();
                $this->built += 1;
            }
        }
        ?></ul><?php

        echo "<p>$this->built posts built.</p>";

        $this->last_build_time = time();
        $this->set_option('last_build',$this->last_build_time);
    }

    protected function make_categories_dict() {
        $categories = get_categories();
        $category_dict = array();
        $categories_by_id = [];
        foreach($categories as $category) {
            $category_dict[$category->slug] = [
                'name' => $category->name,
                'slug' => $category->slug,
                'id' => $category->term_id,
                'description' => $category->description,
                'parent_id' => $category->parent,
                'children' => array()
            ];
            $categories_by_id[$category->term_id] = $category->slug;
        }
        foreach($category_dict as $slug => $category) {
            if($category['parent_id']) {
                $p = $category;
                while($p['parent_id']) {
                    $parent = $category_dict[$categories_by_id[$p['parent_id']]];
                    $parent['children'][] = $category['slug'];
                    $category_dict[$parent['slug']] = $parent;
                    $p = $parent;
                }
                $category['parent'] = $category_dict[$categories_by_id[$category['parent_id']]]['slug'];
                $category_dict[$category['slug']] = $category;
            }
        }

        $this->category_dict = $category_dict;

    }
    public function write_categories_dict() {
        file_put_contents($this->output_dir . "/categories.json", json_encode($this->category_dict, JSON_PRETTY_PRINT));
    }

    public function write_authors_dict() {
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

    public function fix_upload_url($str) {
        $uploads_url = home_url('/wp-content/uploads');
        return str_replace($uploads_url, $this->static_uploads_root, $str);
    }
}

class StaticizePost {
    protected $staticize;
    protected $id;
    protected $post;
    protected $title;
    protected $slug;
    protected $publish_date;
    protected $type;
    protected $destination;
    protected $tags;
    protected $realcategories;
    protected $categories;
    protected $authors;
    protected $status;

    protected function is_draft() {
        return $this->status != 'publish';
    }

    protected $content;
    protected $filename;

    function __construct($staticize,$id,$post=NULL) {
        $this->staticize = $staticize;
        $this->id = $id;
        $this->post = $post or get_post($id);

        $this->title = get_the_title($this->post);
        $this->slug = get_post_field('post_name',$this->post);
        $this->publish_date = get_the_date('Y-m-d',$this->post);
        $this->type = get_post_type($this->post);
        $post_dir = POST_DIRS[$this->type];
        $this->destination = $this->staticize->output_dir . $post_dir;

        $this->tags = array();
        $posttags = get_the_tags($this->post);
        if($posttags) {
            foreach($posttags as $tag) {
                $this->tags[] = $tag->name;
            }
        }
        $this->realcategories = array();
        $postcategories = get_the_category($this->post);
        if($postcategories) {
            foreach($postcategories as $category) {
                $this->realcategories[] = $category->slug;
            }
        }
        $this->categories = array();
        foreach($this->realcategories as $slug) {
            $c = $this->staticize->category_dict[$slug];
            $this->categories[] = $slug;
            while($c['parent']) {
                $c = $this->staticize->category_dict[$c['parent']];
                $this->categories[] = $c['slug'];
            }
        }
        $this->categories = array_unique($this->categories);

        $this->authors = array();
        $postauthors = get_coauthors($this->post);
        foreach($postauthors as $author) {
            $this->authors[] = $author->user_login;
        }

        $this->status = get_post_status($this->post);


        switch($this->type) {
        case 'post':
            $this->filename = "$this->publish_date-post-$this->id" . CONTENT_FILENAME;
            break;
        case 'page':
            $this->filename = $this->slug . CONTENT_FILENAME;
            break;
        }
    }

    public function content() {
        $GLOBALS['more'] = true;
        $GLOBALS['post'] = $this->post;
        setup_postdata($this->post);
        $content = apply_filters('the_content',get_the_content());
        $content = str_replace('{{','{{ \'{{\' }}',$content);
        $content = preg_replace("/<span id=\"more-\d*\">(.*)<\/span>/","\n<p>--more $1--</p>\n",$content);
        return $content;
    }

    public function render() {
        $GLOBALS['more'] = true;
        $GLOBALS['post'] = $this->post;
        setup_postdata($this->post);

        echo "<li>$this->title</li>";

        if(!file_exists($this->destination)) {
            mkdir($destination,0700,true);
        }

        ob_start();

        $this->frontmatter();

        echo $this->content();

        $contents = ob_get_clean();
        $contents = $this->staticize->fix_upload_url($contents);
        file_put_contents("$this->destination/$this->filename",$contents);
    }


    protected function frontmatter() {
        switch($this->type) {
        case 'post':
?>---
layout: "post"
title: "<?= $this->title; ?>"
slug: "<?= $this->slug; ?>"
date: "<?= $this->publish_date; ?>" 
categories: <?= json_encode($this->categories); ?> 
real_categories: <?= json_encode($this->realcategories); ?> 
tags: <?= json_encode($this->tags); ?> 
authors: <?= json_encode($this->authors); ?> 
draft: <?= $this->is_draft() ? 'true' : 'false'; ?>  
status: "<?= $this->status; ?>"
---
<?php
            break;
        case 'page':
?>---
layout: "page"
title: "<?= $this->title; ?>"
slug: "<?= $this->slug; ?>"
authors: <?= json_encode($this->authors); ?> 
draft: <?= $this->is_draft() ? 'true' : 'false'; ?> 
status: "<?= $this->status; ?>"
---
<?php
            break;
        }
    }

    public function delete_post() {
        if(file_exists($this->filename)) {
            unlink($this->filename);
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
