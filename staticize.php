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

$post_dirs =[
    'post' => '/src/content/posts',
    'page' => '/src/content'
];
define('CONTENT_FILENAME','.html');

function get_relative_permalink( $url ) {
    return str_replace( home_url(), "", $url );
}

function copy_dir($source,$dest) {
    if(!file_exists($dest)) {
        mkdir($dest, 0755);
    }
    foreach (
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST) as $item
    ) {
        if ($item->isDir()) {
            $dir = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if(!file_exists($dir)) {
                mkdir($dir);
            }
        } else {
            $item_dest = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if(!file_exists($item_dest) || filemtime($item)>filemtime($item_dest)) {
                copy($item, $item_dest);
            }
        }
    }
}

class Staticize {

    protected static $instance = NULL;

    public $spress_site_dir = '';
    public $output_dir = '';
    protected $static_home_url = '';
    protected $static_uploads_root = '';
	protected $php_bin = 'php';

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
        $this->spress_site_dir = $settings['staticize_spress_site_dir'];
        $this->output_dir = $settings['staticize_output_dir'];
        $this->static_home_url = $settings['staticize_static_home_url'];
        $this->static_uploads_root = $this->static_home_url . '/uploads';
        $this->php_bin = $settings['staticize_php_bin'];
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
                    'staticize_spress_site_dir',
                    'Spress site directory',
                    array(&$this,'setting_text_field_cb'),
                    'staticize',
                    'staticize_settings',
                    [
                        'label_for' => 'staticize_spress_site_dir',
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
                add_settings_field(
                    'staticize_output_dir',
                    'Path to output directory',
                    array(&$this,'setting_text_field_cb'),
                    'staticize',
                    'staticize_settings',
                    [
                        'label_for' => 'staticize_output_dir',
                        'class' => 'staticize_row'
                    ]
                );
                add_settings_field(
                    'staticize_php_bin',
                    'Name of the PHP executable to use',
                    array(&$this,'setting_text_field_cb'),
                    'staticize',
                    'staticize_settings',
                    [
                        'label_for' => 'staticize_php_bin',
                        'class' => 'staticize_row'
                    ]
                );
            });
        }

        add_action('post_updated',function($post_id) {
			ob_start();
            $post = new StaticizePost($this,$post_id,get_post($post_id));
            $post->render();
			$this->run_spress();
			ob_end_clean();
        });
        add_action('profile_update',function() {
			ob_start();
            $this->write_authors_dict();
            $this->run_spress();
			ob_end_clean();
        });

        add_action('trash_post',function($postid) {
			ob_start();
            $post = new StaticizePost($this,$postid);
            $post->delete_post();
            $this->run_spress();
			ob_end_clean();
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
            $this->run_spress();
?>
<h2>Done!</h2>
<p>See the static site at <a target="_blank" href="<?= $this->static_home_url ?>"><?= $this->static_home_url ?></a></p>
<?php
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
        $posts = new \WP_Query(['orderby' => 'modified','nopaging' => true, 'post_type' => 'post']);

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
				echo "<li>$post->name</li>";
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
        file_put_contents($this->spress_site_dir . "/src/data/categories.json", json_encode($this->category_dict, JSON_PRETTY_PRINT));
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
                'bio' => $user->get('description')
            );
			if(function_exists('get_wp_user_avatar_src')) {
				$user_dict[$user->user_login]['avatar'] = $this->fix_upload_url(get_wp_user_avatar_src($user->id));
			}
        }

        file_put_contents($this->spress_site_dir . "/src/data/authors.json", json_encode($user_dict, JSON_PRETTY_PRINT));

    }

    function run_spress() {
        echo "<h2>Running Spress</h2>";
        chdir($this->spress_site_dir);
        $descriptorspec = array(
           0 => array("pipe", "r"),
           1 => array("pipe", "w"),
           2 => array("pipe", "w")
        );
        $env = [
            "HOME" => '/home'
        ];
        $process = proc_open("$this->php_bin spress.phar site:build --env=prod -s $this->spress_site_dir",$descriptorspec,$pipes,__DIR__,$env);
        if(is_resource($process)) {
            fclose($pipes[0]);
            echo "<pre>".stream_get_contents($pipes[1])."</pre>";
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            if($stderr) {
                echo "STDERR <pre>".$stderr."</pre>";
            }
            fclose($pipes[2]);
            $return_value = proc_close($process);
        }

        copy_dir($this->spress_site_dir . '/build',$this->output_dir);
        $upload_path = wp_upload_dir()['basedir'];
        echo "<h2>Copying uploads</h2>";
        copy_dir($upload_path,$this->output_dir.'/uploads');
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
	protected $comments;
	protected $pings;

    protected function is_draft() {
        return $this->status != 'publish';
    }

    protected $content;
    protected $filename;

    function __construct($staticize,$id,$post=NULL) {
		global $post_dirs;
        $this->staticize = $staticize;
        $this->id = $id;
        $this->post = $post or get_post($id);

        $this->title = get_the_title($this->post);
        $this->slug = get_post_field('post_name',$this->post);
        $this->publish_date = get_the_date('Y-m-d',$this->post);
        $this->type = get_post_type($this->post);
        $post_dir = $post_dirs[$this->type];
        $this->destination = $this->staticize->spress_site_dir . $post_dir;

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
		if(function_exists('get_coauthors')) {
			$postauthors = get_coauthors($this->post);
		} else {
			$postauthors = [get_the_author_meta('user_login')];
		}
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

		$this->comments = get_comments([
			'post_id'=>$this->id,
			'type'=>'comment',
			'orderby'=>'comment_date_gmt',
			'order'=>'ASC',
			'status' => 'approve'
		]);
		$this->pings = get_comments([
			'post_id'=>$this->id,
			'type'=>'pings',
			'orderby'=>'comment_date_gmt',
			'order'=>'ASC',
			'status' => 'approve'
		]);
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

        //echo "<li>$this->title</li>";

        if(!file_exists($this->destination)) {
			echo $this->destination;
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
comments: 
<?php foreach($this->comments as $comment) { $this->render_comment($comment); }?>
pings: 
<?php foreach($this->pings as $comment) { $this->render_comment($comment); }?>
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

	protected function render_comment($comment) {
?>
-
  author: "<?= $comment->comment_author ?>"
  id: <?= $comment->comment_ID ?> 
  author_email: "<?= $comment->comment_author_email ?>"
  author_url: "<?= $comment->comment_author_url ?>"
  date: "<?= $comment->comment_date ?>"
  content: <?= json_encode(apply_filters('comment_text',get_comment_text($comment),$comment)); ?> 
  type: "<?= $comment->comment_type ?>"
<?php
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
