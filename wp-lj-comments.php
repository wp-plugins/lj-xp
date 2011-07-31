<?php

//if (empty($wp)) require_once('wp-config.php');
require_once('wp-blog-header.php');
global $wpdb;
$id = $_GET['post_id']; 
$number=$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID = '$id' AND comment_approved = '1'");
$font = 2;
if($number < 10) $image_width = 8;
else if ($number > 10 && $number < 100) $image_width = 16;
else $image_width = 24; 
$image_height = 12;

/* Displaying image with comments number */

header("Content-type: image/png");
$img = @ImageCreate ($image_width, $image_height);
$black = ImageColorAllocate($img, 0, 0, 0);
$white = ImageColorAllocate($img, 255, 255, 255);
$transparent = ImageColorAllocateAlpha($img, 255, 255, 255, 127);
ImageFill($img, 0, 0, $transparent); // Background
ImageString($img , $font, 1, 1, "$number", $black); // Font
ImagePng($img);
ImageDestroy($img);

?>