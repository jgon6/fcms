<?php
session_start();
$stripcap = 'true';
if (get_magic_quotes_gpc()) {
    $_REQUEST = array_map('stripslashes', $_REQUEST);
    $_GET = array_map('stripslashes', $_GET);
    // a bug found with an array in $_POST
    if (!isset($_POST['addphoto']) && !isset($_POST['add_editphoto'])) {
        $stripcap = 'false';
        $_POST = array_map('stripslashes', $_POST);
    }
    $_COOKIE = array_map('stripslashes', $_COOKIE);
}
include_once('../inc/config_inc.php');
include_once('../inc/util_inc.php');
include_once('../inc/language.php');

// Check that the user is logged in
isLoggedIn('gallery/');

header("Cache-control: private");
include_once('../inc/gallery_class.php');
include_once('../inc/database_class.php');
$database = new database('mysql', $cfg_mysql_host, $cfg_mysql_db, $cfg_mysql_user, $cfg_mysql_pass);
$gallery = new PhotoGallery($_SESSION['login_id'], $database);

// Setup the Template variables;
$TMPL['pagetitle'] = $LANG['link_gallery'];
$TMPL['path'] = "../";
$TMPL['admin_path'] = "../admin/";
$TMPL['javascript'] = '
<script type="text/javascript">
//<![CDATA[
Event.observe(window, \'load\', function() {
    hideUploadOptions(\''.$LANG['rotate_options'].'\', \''.$LANG['tag_options'].'\');
    hidePhotoDetails(\''.$LANG['photo_details'].'\');
    initConfirmPhotoDelete(\''.$LANG['js_del_photo'].'\');
    initConfirmCommentDelete(\''.$LANG['js_del_comment'].'\');
    initConfirmCategoryDelete(\''.$LANG['js_del_cat'].'\');
});
//]]>
</script>';

include_once(getTheme($_SESSION['login_id'], $TMPL['path']) . 'header.php');
?>
    <div id="leftcolumn">
        <?php
        include_once(getTheme($_SESSION['login_id'], $TMPL['path']) . 'sidenav.php');
        if (checkAccess($_SESSION['login_id']) < 3) {
            include_once(getTheme($_SESSION['login_id'], $TMPL['path']) . 'adminnav.php');
        }
        ?>
    </div>
    <div id="content">
        <div id="gallery" class="centercontent">
            <?php
            $show_latest = true;
            
            // Edit Photo
            if (isset($_POST['add_editphoto'])) {
                $photo_caption = stripslashes($_POST['photo_caption']);
                $sql = "UPDATE `fcms_gallery_photos` "
                     . "SET category='" . addslashes($_POST['category']) . "', "
                        . "caption='" . addslashes($photo_caption) . "' "
                     . "WHERE id=" . $_POST['photo_id'];
                mysql_query($sql) or displaySQLError(
                    'Edit Photo Error', __FILE__ . ' [' . __LINE__ . ']', $sql, mysql_error()
                );
                
                // Has someone been tagged?
                if (isset($_POST['tagged'])) {
                    
                    // Check whether previously tagged members exist
                    if (isset($_POST['prev_tagged_users'])) {
                        
                        // Delete all members who were previously tagged, but now are not
                        $prev_users = explode(",", $_POST['prev_tagged_users']);
                        foreach ($prev_users as $user) {
                            $key = array_search($user, $_POST['tagged']);
                            if ($key === false) {
                                $sql = "DELETE FROM `fcms_gallery_photos_tags` "
                                     . "WHERE `photo` = " . $_POST['photo_id'] . " "
                                     . "AND `user` = $user";
                                mysql_query($sql) or displaySQLError('
                                    Delete Tagged Member Error', __FILE__ . ' [' . __LINE__ . ']', 
                                    $sql, mysql_error()
                                );
                            }
                            
                        }
                        
                        // Add only members who were not previously tagged
                        foreach ($_POST['tagged'] as $user) {
                            $key = array_search($user, $prev_users);
                            if ($key === false) {
                                $sql = "INSERT INTO `fcms_gallery_photos_tags` (`user`, `photo`) "
                                     . "VALUES ($user, " . $_POST['photo_id'] . ")";
                                mysql_query($sql) or displaySQLError(
                                    'Tag Members Error', __FILE__ . ' [' . __LINE__ . ']', 
                                    $sql, mysql_error()
                                );
                            }
                            
                        }
                        
                    } else {
                        
                        // Add all tagged members, since no one was previously tagged
                        foreach ($_POST['tagged'] as $user) {
                            $sql = "INSERT INTO `fcms_gallery_photos_tags` (`user`, `photo`) "
                                 . "VALUES ($user, " . $_POST['photo_id'] . ")";
                            mysql_query($sql) or displaySQLError('
                                Tag Members Error', __FILE__ . ' [' . __LINE__ . ']', 
                                $sql, mysql_error()
                            );
                        }
                    }
                
                // If no one is currently tagged, but we have previously tagged members, 
                // then we are removing all members
                } elseif (isset($_POST['prev_tagged_users'])) {
                    $sql = "DELETE FROM `fcms_gallery_photos_tags` "
                         . "WHERE `photo` = " . $_POST['photo_id'];
                    mysql_query($sql) or displaySQLError(
                        'Delete All Tagged Error', __FILE__ . ' [' . __LINE__ . ']', 
                        $sql, mysql_error()
                    );
                }
                
                echo '<p class="ok-alert" id="msg">' . $LANG['ok_photo_info'] . '</p>';
                echo '<script type="text/javascript">window.onload=function(){ var ';
                echo 't=setTimeout("$(\'msg\').toggle()",4000); }</script>';
            }
            
            // Display Edit Form
            if (isset($_POST['editphoto'])) {
                $show_latest = false;
                $gallery->displayEditPhotoForm($_POST['photo'], $_POST['url']);
            }

            // Delete photo confirmation
            if (isset($_POST['deletephoto']) && !isset($_POST['confirmed'])) {
                $show_latest = false;
                echo '
                <div class="info-alert clearfix">
                    <form action="index.php" method="post">
                        <h2>'.$LANG['js_del_photo'].'</h2>
                        <p><b><i>'.$LANG['cannot_be_undone'].'</i></b></p>
                        <div>
                            <input type="hidden" name="photo" value="'.$_POST['photo'].'"/>
                            <input type="hidden" name="url" value="'.$_POST['url'].'"/>
                            <input style="float:left;" type="submit" id="delconfirm" name="delconfirm" value="'.$LANG['yes'].'"/>
                            <a style="float:right;" href="index.php?'.$_POST['url'].'">'.$LANG['cancel'].'</a>
                        </div>
                    </form>
                </div>';

            // Delete Photo
            } elseif (
                isset($_POST['delconfirm']) || 
                (isset($_POST['confirmed']) && !isset($_POST['editphoto']))
            ) {
                $show_latest = false;
                $sql = "SELECT `user`, `category`, `filename` "
                     . "FROM `fcms_gallery_photos` WHERE `id` = " . $_POST['photo'];
                $result = mysql_query($sql) or displaySQLError(
                    'Photo Error', __FILE__ . ' [' . __LINE__ . ']', $sql, mysql_error()
                );
                $filerow = mysql_fetch_array($result);
                $file_photo = $filerow['filename'];
                $photo_user_id = $filerow['user'];
                $photo_cat_id = $filerow['category'];
                
                // Remove the photo from the DB
                $sql = "DELETE FROM `fcms_gallery_photos` WHERE `id` = " . $_POST['photo'];
                mysql_query($sql) or displaySQLError(
                    'Delete Photo Error', __FILE__ . ' [' . __LINE__ . ']', $sql, mysql_error()
                );
                $sql = "DELETE FROM `fcms_gallery_comments` WHERE `photo` = " . $_POST['photo'];
                mysql_query($sql) or displaySQLError(
                    'Delete Comments Error', __FILE__ . ' [' . __LINE__ . ']', $sql, mysql_error()
                );
                
                // Remove the Photo from the server
                unlink("photos/member$photo_user_id/" . $file_photo);
                unlink("photos/member$photo_user_id/tb_" . $file_photo);
                mysql_free_result($result);
                $sql = "SELECT `full_size_photos` FROM `fcms_config`";
                $result = mysql_query($sql) or displaySQLError(
                    'Full Size Error', __FILE__ . ' [' . __LINE__ . ']', $sql, mysql_error()
                );
                $r = mysql_fetch_array($result);
                if ($r['full_size_photos'] == 1) {
                    unlink("photos/member$photo_user_id/full_" . $file_photo);
                }
                $gallery->displayGalleryMenu($photo_user_id);
                $gallery->showCategories(0, $photo_user_id, $photo_cat_id);
            }
            
            // Do you have access to perform actions?
            if (isset($_GET['action']) && 
                (
                    checkAccess($_SESSION['login_id']) <= 3 || 
                    checkAccess($_SESSION['login_id']) == 8 || 
                    checkAccess($_SESSION['login_id']) == 5
                )
            ) {
                // We don't want to show the gallery menu on delete confirmation screen
                if (!isset($_POST['delcat']) || isset($_POST['confirmedcat'])) {
                    $gallery->displayGalleryMenu('none');
                }
                
                // Upload a photo or photos
                if ($_GET['action'] == "upload") {
                    $show_latest = false;
                    $last_cat = 0;
                    if (isset($_POST['addphoto'])) {
                        if (empty($_POST['category'])) { 
                            echo "<p class=\"error-alert\">" . $LANG['err_cat_first'] . "</p>";
                        } else { 
                            $last_cat = $_POST['category'];
                            if (isset($_POST['rotate'])) {
                                $rotate = $_POST['rotate'];
                            } else {
                                $rotate = '0';
                            }
                            $photo_id = $gallery->uploadPhoto(
                                $last_cat, $_FILES['photo_filename'], 
                                $_POST['photo_caption'], $rotate, $stripcap
                            );
                            if (isset($_POST['tagged'])) {
                                $sql = "INSERT INTO `fcms_gallery_photos_tags` (`user`, `photo`) "
                                     . "VALUES ";
                                $first = true;
                                foreach ($_POST['tagged'] as $member) {
                                    if (!$first) { $sql .= ", "; }
                                    $sql .= "($member, $photo_id) ";
                                    $first = false;
                                }
                                mysql_query($sql) or displaySQLError(
                                    'Tagging Error', __FILE__ . ' [' . __LINE__ . ']', 
                                    $sql, mysql_error()
                                );
                            }
                        }
                        // Email members
                        $sql = "SELECT u.`email`, s.`user` "
                             . "FROM `fcms_user_settings` AS s, `fcms_users` AS u "
                             . "WHERE `email_updates` = '1'"
                             . "AND u.`id` = s.`user`";
                        $result = mysql_query($sql) or displaySQLError(
                            'Email Updates Error', __FILE__ . ' [' . __LINE__ . ']', 
                            $sql, mysql_error()
                        );
                        if (mysql_num_rows($result) > 0) {
                            while ($r = mysql_fetch_array($result)) {
                                $name = getUserDisplayName($_SESSION['login_id']);
                                $to = getUserDisplayName($r['user']);
                                $subject = "$name " . $LANG['added_photos1'] . " "
                                    . $LANG['added_photos2_email'];
                                $email = $r['email'];
                                $url = getDomainAndDir();
                                $msg = $LANG['dear'] . " $to,

$name " . $LANG['added_photos1'] . " " . $LANG['added_photos2_email'] . "

{$url}index.php?uid=" . $_SESSION['login_id'] . "&cid=$last_cat

----
" . $LANG['opt_out_updates'] . "

{$url}settings.php

";
                                mail($email, $subject, $msg, $email_headers);
                            }
                        }                        
                    }
                    if (isset($_GET['photos'])) {
                        $gallery->displayUploadForm($_GET['photos'], $last_cat);
                    } else {
                        $gallery->displayUploadForm(1, $last_cat);
                    }
                } elseif ($_GET['action'] == "category") {
                    $show_latest = false;
                    $show_cat = true;

                    if (isset($_POST['newcat'])) {
                        if(empty($_POST['cat_name'])) {
                            echo "<p class=\"error-alert\">".$LANG['err_cat_name1']." <a href=\"?page=photo&amp;category=edit\">".$LANG['err_cat_name2']."</a> ".$LANG['err_cat_name3']."</p>";
                        } else {
                            $sql = "INSERT INTO `fcms_gallery_category`(`name`, `user`) VALUES('" . addslashes($_POST['cat_name']) . "', " . $_SESSION['login_id'] . ")";
                            mysql_query($sql) or displaySQLError('New Category Error', 'gallery/index.php [' . __LINE__ . ']', $sql, mysql_error());
                            echo "<p class=\"ok-alert\">".$LANG['ok_cat_add1']." <b>" . stripslashes($_POST['cat_name']) . "</b> ".$LANG['ok_cat_add2']." <a href=\"?action=upload\">".$LANG['ok_cat_add3']."</a> ".$LANG['ok_cat_add4']."</p>";
                        }
                    }
                    if (isset($_POST['editcat'])) {
                        if(empty($_POST['cat_name'])) {
                            echo "<p class=\"error-alert\">".$LANG['err_cat_blank']."</p>";
                        } else {
                            $sql = "UPDATE fcms_gallery_category SET name = '" . addslashes($_POST['cat_name']) . "' WHERE id = " . $_POST['cid'];
                            mysql_query($sql) or displaySQLError('Update Category Error', 'gallery/index.php [' . __LINE__ . ']', $sql, mysql_error());
                            echo "<p class=\"ok-alert\">".$LANG['ok_cat_edit1']." " . stripslashes($_POST['cat_name']) . " ".$LANG['ok_cat_edit2']."</p>";
                        }
                    }

                    // Delete category confirmation
                    if (isset($_POST['delcat']) && !isset($_POST['confirmedcat'])) {
                        $show_cat = false;
                        echo '
                <div class="info-alert clearfix">
                    <form action="index.php?action=category" method="post">
                        <h2>'.$LANG['js_del_cat'].'</h2>
                        <p><b><i>'.$LANG['cannot_be_undone'].'</i></b></p>
                        <div>
                            <input type="hidden" name="cid" value="'.$_POST['cid'].'"/>
                            <input style="float:left;" type="submit" id="delconfirmcat" name="delconfirmcat" value="'.$LANG['yes'].'"/>
                            <a style="float:right;" href="index.php?action=category">'.$LANG['cancel'].'</a>
                        </div>
                    </form>
                </div>';

                    // Delete category
                    } elseif (isset($_POST['delconfirmcat']) || (isset($_POST['confirmedcat']) && !isset($_POST['editcat']))) {
                        $sql = "DELETE FROM fcms_gallery_category WHERE id = " . $_POST['cid'];
                        mysql_query($sql) or displaySQLError('Delete Category Error', 'gallery/index.php [' . __LINE__ . ']', $sql, mysql_error());
                        echo "<p class=\"ok-alert\">".$LANG['ok_cat_del']."</p>";
                    }
                    if ($show_cat) {
                        $gallery->displayAddCatForm();
                    }
                }
            }
            if (isset($_GET['uid']) && !isset($_GET['cid']) && !isset($_GET['pid'])) {
                $show_latest = false;
                if (isset($_GET['page'])) { $page = ($_GET['page'] * 16) - 16; } else { $page = 0; }
                $gallery->displayGalleryMenu($_GET['uid']);
                $gallery->showCategories($page, $_GET['uid']);
            } elseif (isset($_GET['cid']) && !isset($_GET['pid'])) {
                $show_latest = false;
                if (isset($_GET['page'])) { $page = ($_GET['page'] * 16) - 16; } else { $page = 0; }
                $gallery->displayGalleryMenu($_GET['uid'], $_GET['cid']);
                $gallery->showCategories($page, $_GET['uid'], $_GET['cid']);
            } elseif (isset($_GET['pid'])) {
                $show_latest = false;
                $show_photo = true;

                // Add Comment
                if (isset($_POST['addcom'])) {
                    $com = ltrim($_POST['post']);
                    if (!empty($com)) {
                        $sql = "INSERT INTO `fcms_gallery_comments`(`photo`, `comment`, `date`, `user`) VALUES(" . $_GET['pid'] . ", '" . addslashes($_POST['post']) . "', NOW(), " . $_SESSION['login_id'] . ")";
                        mysql_query($sql) or displaySQLError('Add Comment Error', 'gallery/index.php [' . __LINE__ . ']', $sql, mysql_error());
                    }
                }

                // Delete Comment confirmation
                if (isset($_POST['delcom']) && !isset($_POST['confirmedcom'])) {
                    $show_photo = false;
                    echo '
                <div class="info-alert clearfix">
                    <form action="index.php?uid='.$_GET['uid'].'&amp;cid='.$_GET['cid'].'&amp;pid='.$_GET['pid'].'" method="post">
                        <h2>'.$LANG['js_del_comment'].'</h2>
                        <p><b><i>'.$LANG['cannot_be_undone'].'</i></b></p>
                        <div>
                            <input type="hidden" name="id" value="'.$_POST['id'].'"/>
                            <input style="float:left;" type="submit" id="delconfirmcom" name="delconfirmcom" value="'.$LANG['yes'].'"/>
                            <a style="float:right;" href="index.php?uid='.$_GET['uid'].'&amp;cid='.$_GET['cid'].'&amp;pid='.$_GET['pid'].'">'.$LANG['cancel'].'</a>
                        </div>
                    </form>
                </div>';

                // Delete Comment
                } elseif (isset($_POST['delconfirmcom']) || isset($_POST['confirmedcom'])) {
                    $sql = "DELETE FROM `fcms_gallery_comments` WHERE id=" . $_POST['id'];
                    mysql_query($sql) or displaySQLError('Delete Comment Error', 'gallery/index.php [' . __LINE__ . ']', $sql, mysql_error());
                }

                // Vote
                if (isset($_GET['vote'])) {
                    $sql = "UPDATE `fcms_gallery_photos` SET `votes` = `votes`+1, `rating` = `rating`+" . $_GET['vote'] . " WHERE `id` = " . $_GET['pid'];
                    mysql_query($sql) or displaySQLError('Vote Error', 'gallery/index.php [' . __LINE__ . ']', $sql, mysql_error());
                }
                if ($show_photo) {
                    $gallery->showPhoto($_GET['uid'], $_GET['cid'], $_GET['pid']);
                }
            }
            if ($show_latest) {
                $gallery->displayGalleryMenu();
                $gallery->displayLatestCategories();
                $gallery->showCategories(-1, 0, 'comments');
                echo "<p class=\"alignright\"><a class=\"rss\" href=\"../rss.php?feed=gallery\">" . $LANG['rss_feed'] . "</a></p>";
            } ?>
        </div><!-- #gallery .centercontent -->
    </div><!-- #content -->
    <?php displayFooter("fix"); ?>
</body>
</html>  	 
