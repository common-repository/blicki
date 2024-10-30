<?php
/*
Plugin Name: Blicki
Plugin URI: http://dev.wp-plugins.org/browser/blicki/
Description: Blog + Wiki = Blicki
Author: Automattic
Version: 0.1
Author URI: http://automattic.com/
*/ 

$wpdb->post_revision_ids = $wpdb->prefix . 'post_revision_ids';	
$wpdb->post_revisions = $wpdb->prefix . 'post_revisions';

function blicki_activate() {
	global $wpdb;

	// Check if already created.
	foreach ($wpdb->get_col("SHOW TABLES",0) as $table ) {
		if ($table == $wpdb->post_revision_ids) {
			return;
		}
    	}

	// Need to maybe create table.
	$wpdb->query("CREATE TABLE $wpdb->post_revision_ids (
			post_ID bigint(20) NOT NULL default '0',
			current_revision mediumint(12) NOT NULL default '0',
			PRIMARY KEY  (post_ID)
			)");

	$wpdb->query("CREATE TABLE $wpdb->post_revisions (
		ID bigint(20) unsigned NOT NULL auto_increment,
		post_ID bigint(20) unsigned NOT NULL default '0',
		post_revision int(12) NOT NULL default '0',
		post_revision_author bigint(20) NOT NULL default '0',
		post_revision_author_IP varchar(100) NOT NULL default '',
		post_author bigint(20) NOT NULL default '0',
		post_date datetime NOT NULL default '0000-00-00 00:00:00',
		post_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
		post_content longtext NOT NULL,
		post_title text NOT NULL,
		post_category int(4) NOT NULL default '0',
		post_excerpt text NOT NULL,
		post_status enum('publish','draft','private','static','object','attachment','inherit','future') NOT NULL default 'publish',
		comment_status enum('open','closed','registered_only') NOT NULL default 'open',
		ping_status enum('open','closed') NOT NULL default 'open',
		post_password varchar(20) NOT NULL default '',
		post_name varchar(200) NOT NULL default '',
		to_ping text NOT NULL,
		pinged text NOT NULL,
		post_modified datetime NOT NULL default '0000-00-00 00:00:00',
		post_modified_gmt datetime NOT NULL default '0000-00-00 00:00:00',
		post_content_filtered text NOT NULL,
		post_parent bigint(20) NOT NULL default '0',
		guid varchar(255) NOT NULL default '',
		menu_order int(11) NOT NULL default '0',
		post_type varchar(20) NOT NULL default 'post',
		post_mime_type varchar(100) NOT NULL default '',
		comment_count bigint(20) NOT NULL default '0',
		PRIMARY KEY  (ID),
		KEY post_name (post_name),
		KEY type_status_date (post_type,post_status,post_date,ID))"
	);

	add_option('blicki_who_can_edit', 'authors');
	//add_option('blicki_who_can_delete', 'authors');
	//add_option('blicki_who_can_view', 'authors');
}

function blicki_add_options_page() {
	add_options_page(__('Blicki Options'), __('Blicki'), 'manage_options', __FILE__, 'blicki_options_page');	
}

function blicki_content($content) { 
    return preg_replace_callback("/\[\[(.*?)\]\]/", 'blicki_link_callback', $content); 
}

function blicki_current_user_can($user_caps, $requested_caps, $cap_data) {
	$requested_cap = $cap_data[0];

	$user_id = $cap_data[1];
	$post_id = '';
	if ( isset($cap_data[2]) )
		$post_id = $cap_data[2];
	//$current_user = new WP_User($user_id);

	if ( 'edit_page' == $requested_cap ) {
		foreach ($requested_caps as $req_cap)
			$req_caps[$req_cap] = true;
		$who_can_edit = get_post_meta($post_id, '_blicki_who_can_edit', true);
		if ( empty($who_can_edit) )
			$who_can_edit = get_settings('blicki_who_can_edit');
		if ( 'anyone' == $who_can_edit ) {
			$user_caps = array_merge($user_caps, $req_caps);
		} else if ('registered_users' == $who_can_edit ) {
			if ( is_user_logged_in() )
				$user_caps = array_merge($user_caps, $req_caps);
		} else {
			$caps = map_meta_cap('edit_page', $user_id, $post_id);
			foreach ($caps as $cap) {
				if ( empty($user_caps[$cap]) || !$user_caps[$cap] )
					return $user_caps;
			}
			$user_caps = array_merge($user_caps, $req_caps);
		}
	} else if ( 'edit_pages' == $requested_cap ||
		'read' == $requested_cap ) {
		foreach ($requested_caps as $req_cap)
			$req_caps[$req_cap] = true;
		$who_can_edit = get_option('blicki_who_can_edit');
		if ( 'anyone' == $who_can_edit ) {
			$user_caps = array_merge($user_caps, $req_caps);
		} else if ('registered_users' == $who_can_edit ) {
			if ( is_user_logged_in() )
				$user_caps = array_merge($user_caps, $req_caps);
		}
		return $user_caps;
	} else if ( 'delete_page' == $requested_cap || 'delete_pages' == $requested_cap ) {
		foreach ($requested_caps as $req_cap)
			$req_caps[$req_cap] = true;
		$who_can_delete = get_option('blicki_who_can_delete');
		if ( 'anyone' == $who_can_delete ) {
			$user_caps = array_merge($user_caps, $req_caps);
		} else if ('registered_users' == $who_can_delete ) {
			if ( is_user_logged_in() )
				$user_caps = array_merge($user_caps, $req_caps);
		}
		return $user_caps;
	} else if ( 'blicki_change_access' == $requested_cap ) {
		$caps = map_meta_cap('edit_page', $user_id, $post_id);
		foreach ($caps as $cap) {
			if ( empty($user_caps[$cap]) || !$user_caps[$cap] )
				return $user_caps;
		}

		$user_caps['blicki_change_access'] = true;
	}
	
	return $user_caps;
}

function blicki_diff($text1 , $text2) {
	include(dirname(__FILE__) .'/Diff.php');

	$text1 = str_replace(array("\r\n", "\r"), "\n", $text1);
	$text2 = str_replace(array("\r\n", "\r"), "\n", $text2);
	
	$lines1 = split("\n", $text1);
	$lines2 = split("\n", $text2);

	// create the diff object
	$diff = &new Diff($lines1, $lines2);
	$formatter = &new TableDiffFormatter();
	//$formatter = &new DiffFormatter();
	$diff = $formatter->format($diff);
	return "<table>\n" . $diff . "</table>\n";
	//return $diff; 
}

function blicki_edit_page_form() {
	global $post_ID;
	if ( ! current_user_can('blicki_change_access') )
		return;

	$who = get_post_meta($post_ID, '_blicki_who_can_edit', true);
	if ( empty($who) )
		$who = get_settings('blicki_who_can_edit');
?>
<p><?php _e('Who can edit this page?'); ?>
<select name="blicki_who_can_edit" id="blicki_who_can_edit">
<option value="anyone" <?php selected('anyone', $who); ?>><?php _e('Anyone') ?></option>
<option value="registered_users" <?php selected('registered_users', $who); ?>><?php _e('Registered users') ?></option>
<option value="authors" <?php selected('authors', $who); ?>><?php _e('Authors and Editors') ?></option>
</select></p>
<?
}

function blicki_get_current_revision_number($post_id) {
	global $wpdb;

	return $wpdb->get_var("SELECT current_revision FROM $wpdb->post_revision_ids WHERE post_ID = '$post_id'");
}

function blicki_get_page_link($page) {
	if ( $page_obj = get_page_by_path(rawurlencode($page)) )
		return get_page_link($page_obj->ID);
	else if ( $page_obj = get_page_by_title($page) )
		return get_page_link($page_obj->ID);

	// If the page doesn't exist, link to the page editor.
	$link = get_settings('siteurl') . '/wp-admin/page-new.php'
		. '?post_title=' . rawurlencode($page);
	return $link;
}

function blicki_get_plugin_page_link() {
	$name = plugin_basename(__FILE__);
	$args = array('page' => $name, 'noheader' => 1);
	return add_query_arg($args, get_settings('siteurl') . "/wp-admin/admin.php");
}

function blicki_get_revision($post_id, $revision) {
	global $wpdb;

	$rev = $wpdb->get_row("SELECT * FROM $wpdb->post_revisions WHERE post_ID = '$post_id' AND post_revision = '$revision'");
	$rev->ID = $post_id;
	return $rev;
}

function blicki_get_revision_link($post_id, $revision) {
	$link = get_permalink($post_id);
	
	return add_query_arg('revision', $revision, $link);
}

function blicki_get_diff_link($post_id, $revision, $from) {
	$link = get_permalink($post_id);
	
	$args = array('revision' => $revision, 'from' => $from);
	return add_query_arg($args, $link);
}

function blicki_get_revisions($post_id, $args = '') {
	global $wpdb;
	if ( is_array($args) )
		$r = &$args;
	else
		parse_str($args, $r);

	$defaults = array('limit' => 0);
	$r = array_merge($defaults, $r);
	extract($r);

	if ( !empty($limit) )
		$limit = "LIMIT $limit";
	else
		$limit = '';

	$revisions = $wpdb->get_col("SELECT post_revision FROM $wpdb->post_revisions WHERE post_ID = '$post_id' ORDER BY post_revision DESC $limit");
	
	if ( empty($revisions) )
		return array();
		
	return $revisions;
}

function blicki_get_rollback_link($post_id, $revision) {
	$link = blicki_get_plugin_page_link();
	$query_args = array('action' => 'rollback', 'post_ID' => $post_id, 'revision' => $revision, 'noheader' => 1);
	return add_query_arg($query_args, $link);	
}

function blicki_increment_current_revision_number($post_id) {
	global $wpdb;

	$revision = blicki_get_current_revision_number($post_id);

	if ( empty($revision) ) {
		$revision = '1';
		$wpdb->query("INSERT INTO $wpdb->post_revision_ids (post_ID, current_revision) VALUES ('$post_id', '$revision')");		
	} else {		
		$revision++;
		$wpdb->query("UPDATE $wpdb->post_revision_ids SET current_revision = '$revision'");
	}

	return $revision;
}

function blicki_insert_revision($post_id) {
	global $wpdb, $current_user;

	$post = get_post($post_id, ARRAY_A);

	if ( empty($post) )
		return false;

	$post = add_magic_quotes($post);

	$post_revision = blicki_increment_current_revision_number($post_id);

	unset($post['ID']);
	unset($post['fullpath']);
	$post['post_revision_author'] = $current_user->ID;
	$post['post_revision_author_IP'] = $_SERVER['REMOTE_ADDR'];
	$keys = implode(', ', array_keys($post));
	$keys .= ', post_ID, post_revision';
	
	$values = '';
	foreach (array_values($post) as $value) {
		$values .= "'$value', ";
	}
	$values .= "'$post_id', '$post_revision'";
	
	$wpdb->query("INSERT INTO $wpdb->post_revisions ($keys) VALUES ($values)");
	
	return $wpdb->insert_id;
}

function blicki_is_diff() {
	if ( isset($_GET['revision']) && isset($_GET['from']) )
		return true;
	return false;
}

function blicki_is_history() {
	
}

function blicki_link_callback($link) { 

	$link = $link[1];
    $page_link = blicki_get_page_link($link);

    
    return "<a href=\"$page_link\">$link</a>"; 
} 

function blicki_list_revisions($post_id = 0, $args = '') {
	global $post;

	if ( empty($post_id) )
		$post_id = $post->ID;

	if ( empty($post_id) )
		return;

	if ( is_array($args) )
		$r = &$args;
	else
		parse_str($args, $r);

	$defaults = array('limit' => 0);
	$r = array_merge($defaults, $r);
	extract($r);
	
	$revisions = blicki_get_revisions($post_id, $args);
	
	if ( empty($revisions) )
		return;

	$current = blicki_get_current_revision_number($post_id);

	$list = '';
	foreach ($revisions as $revision) {
		$post = blicki_get_revision($post_id, $revision);
		$author = get_userdata($post->post_revision_author);
		$title = sprintf(__('Revision %1$s made on %2$s at %3$s by %4$s'), $revision, mysql2date(get_settings('date_format'), $post->post_modified), mysql2date(get_settings('time_format'), $post->post_modified),  wp_specialchars($author->display_name));
		$list .= "<li>\n";
		$list .= '<a href="' . blicki_get_revision_link($post_id, $revision) . '" title="' . $title . '">' . $title . '</a>';
		$list .= "\n</li>\n";		

		$list .= "<ul>\n";
		// View link.
		$list .= "<li>\n";
		$list .= '<a href="' . blicki_get_revision_link($post_id, $revision) . '" title="' . __('View this revision') . '">' . __('View this revision') . '</a>';
		$list .= "\n</li>\n";
		
		if ( $revision == $current ) {
			$list .= "</ul>\n";
			continue;
		}

		// Diff to current link.
		$list .= "<li>\n";
		$list .= '<a href="' . blicki_get_diff_link($post_id, $revision, $current) . '" title="' . __('Compare this revision to the current revision') . '">' . __('Compare this revision to the current revision') . '</a>';
		$list .= "\n</li>\n";
		// Rollback link.
		if ( current_user_can('edit_post', $post_id) ) {
			$list .= "<li>\n";
			$list .= ' ' . '<a href="' . blicki_get_rollback_link($post_id, $revision) . '">' . __('Rollback to this revision') . '</a>';
			$list .= "\n</li>\n";	
		}
		$list .= "</ul>\n";

	}
	
	$list = "<ul>\n$list</ul>\n";

	echo $list;	
	return $list;
}

function blicki_options_page() {
?>
<div class="wrap">
<h2><?php _e('Blicki Options') ?></h2>
<form name="form1" method="post" action="options.php">
<fieldset class="options">
<legend><?php _e('Writing') ?></legend>
<table width="100%" cellspacing="2" cellpadding="5" class="editform">
<tr valign="top">
<th width="33%" scope="row"><?php _e('Who can create and edit pages:') ?></th>
<td>
<select name="blicki_who_can_edit" id="blicki_who_can_edit" >
<option value="anyone" <?php selected('anyone', get_settings('blicki_who_can_edit')); ?>><?php _e('Anyone') ?></option>
<option value="registered_users" <?php selected('registered_users', get_settings('blicki_who_can_edit')); ?>><?php _e('Registered users') ?></option>
<option value="authors" <?php selected('authors', get_settings('blicki_who_can_edit')); ?>><?php _e('Authors and Editors') ?></option>
</select>
</td>
</tr>
</table>
</fieldset>

<p class="submit">
<input type="hidden" name="action" value="update" />
<input type="hidden" name="page_options" value="blicki_who_can_edit" />
<input type="submit" name="Submit" value="<?php _e('Update Options') ?> &raquo;" />
</p>
</form>
</div>
<?php
}

function blicki_plugin_page() {
	if ( ! isset($_REQUEST['action']) )
		return;

	if ( 'rollback' != $_REQUEST['action'] )
		return;
		
	$post_id = (int) $_REQUEST['post_ID'];
	$revision = (int) $_REQUEST['revision'];
	if ( $post_id && $revision && current_user_can('edit_post', $post_id) )
		blicki_rollback($post_id, $revision);

	$sendback = $_SERVER['HTTP_REFERER'];
	$sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
	header ('Location: ' . $sendback);
}

function blicki_post_form_list() {
	global $post;

	$post_id = $post->ID;

	if ( ! blicki_get_revisions($post_id) )
		return;

	echo '<fieldset id="postrevisions" class="dbx-box">';
	echo '<h3 class="dbx-handle">' .  __('Revisions') . '</h3>';
	echo '<div class="dbx-content">';
	blicki_list_revisions($post_id);
	echo '</div>';
	echo '</fieldset>';	
}

function blicki_publish_page($post_ID) {
	if ( !isset($_POST['blicki_who_can_edit']) )
		return;

	$who = $_POST['blicki_who_can_edit'];
	if ( ! update_post_meta($post_ID, '_blicki_who_can_edit',  $who))
		add_post_meta($post_ID, '_blicki_who_can_edit',  $who, true);
}

function blicki_register_plugin_page_hook() {
	$hookname = plugin_basename(__FILE__);
	$hookname = preg_replace('!\.php!', '', $hookname);
	$hookname = '_page_' . $hookname;
	add_action($hookname, 'blicki_plugin_page');
}

function blicki_rollback($post_id, $revision) {
	$rev = blicki_get_revision($post_id, $revision);

	if ( empty($rev) )
		return false;

	$rev->ID = $post_id;
	unset($rev->post_revision_author);
	unset($rev->post_revision_author_IP);
	$rev = get_object_vars($rev);
	$rev = add_magic_quotes($rev);
	
	return wp_update_post($rev);
}

function blicki_template_loader() {
	if ( blicki_is_diff() && $template = get_query_template('diff') ) {
		include($template);
		exit;
	}
}

function blicki_the_posts($posts) {
	$rev = (int) $_REQUEST['revision'];
	if ( empty($rev) )
		return $posts;

	if ( count($posts) != 1 )
		return $posts;

	$post_rev = blicki_get_revision($posts[0]->ID, $rev);

	if ( empty($post_rev) )
		return $posts;

	$posts[0] = $post_rev;

	$from = (int) $_REQUEST['from'];

	if ( empty($from) )
		return $posts;

	$post_from = blicki_get_revision($posts[0]->ID, $from);

	if ( empty($post_from) )
		return $posts;

	$posts[0]->post_content = blicki_diff(apply_filters('the_content', $post_rev->post_content), apply_filters('the_content', $post_from->post_content));

	return $posts;
}

blicki_register_plugin_page_hook();
register_activation_hook(__FILE__, 'blicki_activate');
add_action('save_post', 'blicki_insert_revision', 5);
add_action('dbx_post_advanced', 'blicki_post_form_list');
add_action('dbx_page_advanced', 'blicki_post_form_list');
add_filter('the_posts', 'blicki_the_posts');
add_filter('user_has_cap', 'blicki_current_user_can', 10, 3);
add_action('admin_menu', 'blicki_add_options_page');
add_filter('the_content', 'blicki_content');
add_filter('comment_text', 'blicki_content');
add_action('template_redirect', 'blicki_template_loader');
add_action('edit_page_form', 'blicki_edit_page_form');
add_action('publish_page', 'blicki_publish_page');

/* TODO 
 * history links /page/history/, /page/?history=1, blicki_revision_history_link()
 * Load history.php if showing history page.
 * Load diff.php if showing diffs between revision.
 * [[page]] links to page
 * If page not found, show custom 404 that provides link to create the page.
 * Page tagging
 * Add special cap that allows users to edit only "Wiki" pages.
 */
?>
