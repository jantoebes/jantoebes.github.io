<?php
/*
Plugin Name: Evernote Sync
Plugin URI: http://www.biliyu.com/evernote-sync
Description: The evernote timing synchronization to wordpress.
Version: 2.0.5
Author: Gaowei Tang
Author URI: http://www.biliyu.com/
Text Domain: evernotesync
Domain Path: /languages/
*/
/*

1. 该插件同时适用于 Evernote 和 印象笔记（以下统一称为 Evernote）；
2. 添加“posts”标签的 Evernote 将自动同步至 WordPress；
3. 大约每 30 分钟同步一次；
4. 同步内容包括分类、标签、标题、内容及其包含的图片；
5. Evernote 中相同名称的标签同步为 WordPress 的分类；
6. 其它 Evernote 标签按名称全部同步为 WordPress 的标签。（除“posts”标签外）；
7. 支持印象笔记和 Evernote 国际版授权的方式获取 token。
*/
/*
Features:

1. The plugin applies to Evernote and Yinxiang;
2. Add "posts" tag Evernote will automatically sync to WordPress;
3. About synchronization every 30 minutes;
4. Synchronous including categories, tags, titles, content and pictures contained;
5. The same name tags of Evernote synchronization into WordPress categories;
6. Other Evernote tags are all synchronized to the WordPress label. (except for "posts");
7. Support for Yinxiang(china) and Evernote Authorization to get the token.
*/
require_once 'src/autoload.php';
require_once 'EvernoteSync.php';
require_once(ABSPATH . 'wp-admin/includes/image.php');
use EDAM\Error\EDAMUserException;
use EDAM\Error\EDAMErrorCode;

/**
 * Doesn't work if PHP version is not 5.3.0 or higher
 */
if (version_compare(phpversion(), '5.3.0', '<')) {
    return;
}

/**
 * Loader class for the EvernoteSync plugin
 */
class EvernoteSyncLoader
{

    /**
     * 印象笔记授权
     */
    public static function oauth1()
    {
        $callback = get_site_url() . '/wp-admin/options-general.php?page=evernotesync.php';

        $url = "http://www.yongdui.com/oauth.php?china=true&callback=" . urlencode($callback);

        echo "<script language='javascript'type='text/javascript'>";
        echo "window.location.href='$url'";
        echo "</script>";
    }

    /**
     * Evernote 授权
     */
    public static function oauth2()
    {
        $callback = get_site_url() . '/wp-admin/options-general.php?page=evernotesync.php';

        $url = "http://www.yongdui.com/oauth.php?china=false&callback=" . urlencode($callback);

        echo "<script language='javascript'type='text/javascript'>";
        echo "window.location.href='$url'";
        echo "</script>";
    }

    /**
     * 清楚同步记录，让文章重新导入
     */
    public static function clear()
    {
        global $wpdb;

        // 初始化同步对象
        $sync = EvernoteSync::instance();

        // 清空同步记录表
        $table_name = $sync->getRecordTableName();
        if ($wpdb->get_var("show tables like '$table_name'") == $table_name) {
            $wpdb->query($wpdb->prepare("DELETE from $table_name where id>%d", 0));
        }
        // 清空旧的同步记录表
        $table_name = $sync->getRecordOldTableName();
        if ($wpdb->get_var("show tables like '$table_name'") == $table_name) {
            $wpdb->query($wpdb->prepare("DELETE from $table_name where id>%d", 0));
        }
    }

    /**
     * 同步笔记
     * @param bool $force 是否强制同步
     */
    public static function sync($force = false)
    {
        global $wpdb;

        try {
            // 初始化同步对象
            $sync = EvernoteSync::instance();

            // 同步记录表名
            $table_name = $sync->getRecordTableName();

            // 定时处理
            if (!$sync->timer($force)) {
                return;
            }
            // 获取笔记操作对象
            $client = $sync->getClient();

            // 测试 API 是否过期
            $client->getAdvancedClient()->getNoteStore();

            // 搜索标签下的笔记
            $results = $sync->searchNotes();
            if (is_array($results)) {
                // 反向遍历数组
                foreach (array_reverse($results) as $result) {
                    $noteGuid = $result->guid;
                    $noteTitle = $result->title;
                    $noteCreated = $result->created;
                    $noteUpdated = $result->updated;

                    // 判断文章是否更新
                    if ($sync->checkUpdated($result)) {
                        continue;
                    }

                    // 获取笔记对象
                    $note = $sync->getNote($noteGuid);

                    // 获取笔记资源
                    $res_array = $sync->getResourceArray($note);

                    // 上传资源并记录特征图ID
                    $featureid = $sync->uploadResources($res_array);

                    // 获取笔记内容
                    $content = $sync->getContent($note);

                    // 将内容中的资源标签<en-media>替换为图片标签<img>
                    $content = $sync->replaceMediaTags($content, $res_array);

                    // 格式化文章内容
                    $content = $sync->formatArticle($content);

                    // 格式化 H 标签
                    $content = $sync->makeHeaderTag($content);

                    // 查询同步记录
                    $record = $sync->getSyncRecord($noteGuid);

                    // 获取文章
                    $post = null;
                    $postid = null;
                    if (!empty($record)) {
                        $postid = $record->postid;
                        $post = get_post($postid);
                    }

                    // 获取文章摘要
                    $excerpt = $sync->getExcerpt($content);

                    // 用于同步的标签
                    $syncTag = get_option('evernotesync_tag', 'posts');
                    // 'post_status' => [ 'draft' | 'publish' | 'pending'| 'future' | 'private' | custom registered status ] //新文章的状态。
                    $publishMode = '';
                    // 获取分类ID
                    $categoryIds = array();
                    $tags = '';
                    $tagGuids = $note->edamNote->tagGuids;
                    if (is_array($tagGuids)) {
                        foreach ($tagGuids as $tagGuid) {
                            $tagName = $client->getUserNotestore()->getTag($tagGuid)->name;
                            // 获取分类ID（传入分类名称）
                            $cat_ID = get_cat_ID($tagName);
                            if ($cat_ID > 0) {
                                // 保存分类ID
                                array_push($categoryIds, $cat_ID);
                            } elseif ($tagName == 'private') {
                                $publishMode = 'private';
                            } elseif ($tagName == 'draft') {
                                $publishMode = 'draft';
                            } elseif ($tagName == 'publish') {
                                $publishMode = 'publish';
                            } elseif ($tagName != $syncTag) {
                                // 拼接标签
                                if (strlen($tags) > 0) {
                                    $tags = $tags . ',';
                                }
                                $tags = $tags . $tagName;
                            }
                        }
                    }

                    // 没有找到文章，则新建
                    if (empty($post)) {
                        // 创建 post 对象（数组）
                        if (get_option('evernotesync_publish_mode') == 2) {
                            $publishMode = 'draft';
                        }
                        if (empty($publishMode)) {
                            $publishMode = 'publish';
                        }

                        $my_post = array(
                        'post_title' => $noteTitle,
                        'post_content' => $content,
                        'post_status' => $publishMode,
                        'post_author' => 1,
                        'post_category' => $categoryIds,
                        'tags_input' => $tags
                    );

                        // 追加摘要
                        $generateExcerpt = get_option('evernotesync_generate_excerpt', 1);
                        error_log("generateExcerpt1=$generateExcerpt");
                        if ($generateExcerpt == 1) {
                            $my_post['post_excerpt'] = $excerpt;
                        } else {
                            $my_post['post_excerpt'] = '';
                        }

                        // 追加发布时间
                        if (get_option('evernotesync_publish_time') == 2) {
                            $my_post['post_date'] = date("Y-m-d h:i:s", $noteCreated / 1000);
                        }

                        // 写入日志到数据库
                        $postid = wp_insert_post($my_post);

                        // 插入同步记录
                        $wpdb->insert($table_name, array('postid' => $postid, 'title' => $noteTitle, 'guid' => $noteGuid, 'created' => $noteCreated, 'updated' => $noteUpdated));
                    } else {
                        // 创建 post 对象（数组）
                        $my_post = array(
                        'ID' => $postid,
                        'post_title' => $noteTitle,
                        'post_content' => $content,
                        'post_author' => 1,
                        'post_category' => $categoryIds,
                        'tags_input' => $tags
                    );

                        // 追加摘要
                        $generateExcerpt = get_option('evernotesync_generate_excerpt', 1);
                        error_log("generateExcerpt2=$generateExcerpt");
                        if ($generateExcerpt == 1) {
                            $my_post['post_excerpt'] = $excerpt;
                        }

                        // 追加发布状态
                        if (!empty($publishMode)) {
                            $my_post['post_status'] = $publishMode;
                        }

                        // 写入日志到数据库
                        wp_update_post($my_post);

                        // 更新同步记录
                        $wpdb->update(
                        $table_name, // Table
                        array('postid' => $postid, 'title' => $noteTitle, 'guid' => $noteGuid, 'created' => $noteCreated, 'updated' => $noteUpdated), // Array of key(col) => val(value to update to)
                        array('guid' => $noteGuid) // Where
                    );
                    }

                    // 设置特征图
                    if (!is_null($featureid) && !is_null($postid)) {
                        set_post_thumbnail($postid, $featureid);
                    }
                }
            }

            // delete exception
            delete_option('evernotesync_exception');

            return true;
        } catch (EDAMUserException $e) {
            if ($e->errorCode == EDAMErrorCode::AUTH_EXPIRED) {

                // record exceptoin
                update_option('evernotesync_exception', __('Authorization expired, please reauthorize.', 'evernotesync'));

                return false;
            }
        }
    }

    public static function showOptionsPage()
    {
        evernote_sync_timer();

        if (isset($_GET['token'])) {
            update_option('evernotesync_token', $_GET['token']);
            update_option('evernotesync_expires', date('Y-m-d', floatval($_GET['expires'])/1000));

            if ($_GET['china'] === 'true') {
                update_option('evernotesync_platform', 1);
            } else {
                update_option('evernotesync_platform', 2);
            }
        }

        // 获取有效期
        $expires = get_option('evernotesync_expires', null); 
        
        ?><div class="wrap"><?php

        // 手动同步
        if (isset($_POST['sync'])) {            
            if(EvernoteSyncLoader::sync(true)){
                ?><div class="updated"><?php _e('Sync success.', 'evernotesync') ?></div><?php
            }
            else{
                ?><div class="error"><?php _e('Sync fail!', 'evernotesync') ?></div><?php
            }
        }
        // 清楚同步记录
        if (isset($_POST['clear'])) {
            error_log("sync record clear");
            EvernoteSyncLoader::clear(); 
            ?><div class="updated"><?php _e('Clear success', 'evernotesync') ?></div><?php
        }
        // 印象笔记授权
        if (isset($_POST['oauth1']) || isset($_GET['oauth_token'])) {
            EvernoteSyncLoader::oauth1(); 
            ?><div class="updated">oauth success</div><?php
        }
        // Evernote 授权
        if (isset($_POST['oauth2']) || isset($_GET['oauth_token'])) {
            EvernoteSyncLoader::oauth2(); 
            ?><div class="updated">oauth success</div><?php
        } 
        
        // 打印异常
        $exception = get_option('evernotesync_exception', null);
        if(isset($exception)){
            ?><div class="error"><?php echo $exception; ?></div><?php
        }

        ?><script>
            jQuery(document).ready(function () {
                jQuery('#publishMode').change(function () {
                    if (jQuery(this).val() == 3) {
                        jQuery('#timedSpan').show();
                    }
                    else {
                        jQuery('#timedSpan').hide();
                    }
                });
                jQuery('#btnClear').click(function(){
                    if(!confirm("<?php _e('Do you really want to delete all sync records?', 'evernotesync')?>"))
                    {
                        return false;
                    }
                });
            });
        </script>
        <h2><?php _e('EvernoteSync Plugin Options', 'evernotesync') ?></h2>
        <?php
        $success = true;
        $error = "";
        $mode = get_option('evernotesync_publish_mode', 1);
        $publish_time = get_option('evernotesync_publish_time', 1);
        $format_content = get_option('evernotesync_format_content', 1);
        $sync_image_attribute = get_option('evernotesync_sync_image_attribute', 2);
        $generate_excerpt = get_option('evernotesync_generate_excerpt', 1);
        $sync_count = get_option('evernotesync_sync_count', 10);
        // oauth
        /*if (isset($_GET['oauth_token'])) {
            update_option('evernotesync_token', $_GET['oauth_token']);
        }*/
        if (isset($_POST['submit'])) {
            // save data
            update_option('evernotesync_tag', $_POST['evernotesync_tag']);
            update_option('evernotesync_platform', $_POST['evernotesync_platform']);
            update_option('evernotesync_token', $_POST['evernotesync_token']);
            // 每次同步几篇笔记
            $sync_count = $_POST['evernotesync_sync_count'];
            $patten = "/^([0-9]+)$/";
            if (preg_match($patten, $sync_count)) {
                update_option('evernotesync_sync_count', $sync_count);
            } else {
                $success = false;
                $error = $error . '<div class="error">' . __('invalid format for Sync Count!', 'evernotesync') . '</div>';
            }

            // 是否格式化内容
            $format_content = $_POST['evernotesync_format_content'];
            update_option('evernotesync_format_content', $format_content);

            // 是否同步图片属性
            $sync_image_attribute = $_POST['evernotesync_sync_image_attribute'];
            update_option('evernotesync_sync_image_attribute', $sync_image_attribute);

            // 摘要
            $generate_excerpt = $_POST['evernotesync_generate_excerpt'];
            update_option('evernotesync_generate_excerpt', $generate_excerpt);
            // 发布时间选择
            $publish_time = $_POST['evernotesync_publish_time'];
            update_option('evernotesync_publish_time', $publish_time);
            $mode = $_POST['evernotesync_publish_mode'];
            // processing publish mode
            if ($mode == 3) { // timing mode
                $time = $_POST['evernotesync_timed_time'];
                update_option('evernotesync_timed_time', $time);
                $patten = "/^(0?[0-9]|1[0-9]|2[0-3])\:(0?[0-9]|[1-5][0-9])$/";
                if (preg_match($patten, $time)) {
                    update_option('evernotesync_publish_mode', $mode);
                } else {
                    update_option('evernotesync_publish_mode', 2);
                    $success = false;
                    $error = $error . '<div class="error">' . __('Time format is error!', 'evernotesync') . '</div>';
                }
            } else {
                update_option('evernotesync_publish_mode', $mode);
            }
            // output message
            if ($success) {
                ?>
                <div class="updated"><?php _e('Save success.', 'evernotesync') ?></div>
            <?php
            } else {
                echo $error;
            }
        } ?>
        <div><br/><?php _e('Explain: Sync with "posts" tag notes', 'evernotesync') ?></div>
        <form action="" method="post">
            <table width="100%" class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_platform">
                            <?php _e('Platform', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <select id="evernotesync_platform" name="evernotesync_platform">
                            <option
                                value="1"<?php if (get_option('evernotesync_platform') == 1) {
            echo ' selected';
        } ?>><?php _e('Yinxiang', 'evernotesync') ?></option>
                            <option
                                value="2"<?php if (get_option('evernotesync_platform') == 2) {
            echo ' selected';
        } ?>><?php _e('Evernote', 'evernotesync') ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_token">
                            <?php _e('Authorization Token', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <input id="evernotesync_token" name="evernotesync_token" type="text" size="60"
                               value="<?php echo get_option('evernotesync_token') ?>"/>
                                <div>
                                    <?php _e('Authorization to obtain a token:', 'evernotesync') ?>
                                    <input type="submit" name="oauth1" class="button-link" value="<?php _e('Yinxiang Authorization', 'evernotesync') ?>"/>
                                    &nbsp;|&nbsp;
                                    <input type="submit" name="oauth2" class="button-link" value="<?php _e('Evernote Authorization', 'evernotesync') ?>"/>
                                </div>
                    </td>
                </tr>
                <?php if (isset($expires)) {
            ?>
                <tr valign="top">
                    <th scope="row">
                        <label>
                            <?php _e('Authorization Expires', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <?php echo $expires ?>
                    </td>
                </tr>
                <?php
        } ?>
                <tr valign="top">
                    <th scope="row">
                        <label for="publishMode">
                            <?php _e('Publish Mode', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <select id="publishMode" name="evernotesync_publish_mode">
                            <option
                                value="1"<?php if ($mode == 1) {
            echo ' selected';
        } ?>><?php _e('published', 'evernotesync') ?></option>
                            <option
                                value="2"<?php if ($mode == 2) {
            echo ' selected';
        } ?>><?php _e('draft', 'evernotesync') ?></option>
                            <option
                                value="3"<?php if ($mode == 3) {
            echo ' selected';
        } ?>><?php _e('timed', 'evernotesync') ?></option>
                        </select>
                            <span id="timedSpan"<?php if ($mode != 3) {
            echo ' style="display:none;"';
        } ?>>
                                <?php _e('Every Day', 'evernotesync') ?>&nbsp;
                                <input name="evernotesync_timed_time" type="text" size="10"
                                       value="<?php echo get_option('evernotesync_timed_time') ?>"/>
                                (<?php _e('e.g.', 'evernotesync') ?>, 7:00)
                            </span>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_publish_time">
                            <?php _e('Publish Time', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <select id="evernotesync_publish_time" name="evernotesync_publish_time">
                            <option
                                value="1"<?php if (empty($publish_time) || $publish_time == 1) {
            echo ' selected';
        } ?>><?php _e('use WordPress creation time', 'evernotesync') ?></option>
                            <option
                                value="2"<?php if ($publish_time == 2) {
            echo ' selected';
        } ?>><?php _e('use EverNote creation time', 'evernotesync') ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_format_content">
                            <?php _e('Format Content', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <select id="evernotesync_format_content" name="evernotesync_format_content">
                            <option
                                value="1"<?php if (empty($format_content) || $format_content == 1) {
            echo ' selected';
        } ?>><?php _e('Yes', 'evernotesync') ?></option>
                            <option
                                value="2"<?php if ($format_content == 2) {
            echo ' selected';
        } ?>><?php _e('No', 'evernotesync') ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_sync_image_attribute">
                            <?php _e("Sync image's attributes", 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <select id="evernotesync_sync_image_attribute" name="evernotesync_sync_image_attribute">
                            <option
                                value="1"<?php if (!empty($sync_image_attribute) && $sync_image_attribute == 1) {
            echo ' selected';
        } ?>><?php _e('Yes', 'evernotesync') ?></option>
                            <option
                                value="2"<?php if ($sync_image_attribute == 2) {
            echo ' selected';
        } ?>><?php _e('No', 'evernotesync') ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_generate_excerpt">
                            <?php _e('Generate Excerpt', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <select id="evernotesync_generate_excerpt" name="evernotesync_generate_excerpt">
                            <option
                                value="1"<?php if (empty($generate_excerpt) || $generate_excerpt == 1) {
            echo ' selected';
        } ?>><?php _e('Yes', 'evernotesync') ?></option>
                            <option
                                value="2"<?php if ($generate_excerpt == 2) {
            echo ' selected';
        } ?>><?php _e('No', 'evernotesync') ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_tag">
                            <?php _e('Count of Each Sync', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <input id="evernotesync_sync_count" name="evernotesync_sync_count" type="text" size="24"
                               value="<?php echo $sync_count ?>"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="evernotesync_tag">
                            <?php _e('Tag for Sync', 'evernotesync') ?>
                        </label>
                    </th>
                    <td>
                        <input id="evernotesync_tag" name="evernotesync_tag" type="text" size="24"
                               value="<?php echo get_option('evernotesync_tag', 'posts') ?>"/>
                    </td>
                </tr>
            </table>
            <div>                
                <p style="color:red">
                <?php 
                if(null === get_option('evernotesync_token', null)){
                    _e("Please click the 'Yinxiang Authorization' or 'Evernote Authorization' link to get the token.", 'evernotesync'); 
                }
                ?>
                </p>
            </div>
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="<?php _e('Save Options', 'evernotesync') ?>"/>
            </p>

        </form>

        <p>
        <form action="" method="post" style="display:inline; ">
            <input type="submit" name="sync" class="button" value="<?php _e('Manual Sync', 'evernotesync') ?>"/>
        </form>
        <form action="" method="post" style="display:inline;">
            <input id="btnClear" type="submit" name="clear" class="button" value="<?php _e('Clear Sync Record', 'evernotesync') ?>"/>
        </form>
        <a class="button" target="_blank" href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=65VUX9EUS2QBE"><?php _e('Donate', 'evernotesync') ?></a>
        </p>
        <?php if (get_option('evernotesync_last') != null) {
            ?>
            <div>
                <?php _e('The last synchronization time', 'evernotesync') ?>:
                <?php echo get_option('evernotesync_last') ?>
            </div>
            <div>
                <?php _e('Next time', 'evernotesync') ?>:
                <?php
                date_default_timezone_set(get_option('timezone_string'));
            echo date("Y-m-d H:i:s", wp_next_scheduled('evernote_sync_cron')); ?>
            </div>
            <!--<div><?php echo get_option('evernotesync_log') ?></div>-->
        <?php
        } ?>
        </div>
    <?php
    }

    /**
     * Enables the EvernoteSync plugin with registering all required hooks.
     */
    public static function enable()
    {
        // 加载国际化数据
        load_plugin_textdomain('evernotesync', false, dirname(plugin_basename(__FILE__)) . '/localization/');

        // Init database（启动插件是执行）
        register_activation_hook(__FILE__, array('EvernoteSyncLoader', 'activation'));

        // 插件停用是执行
        register_deactivation_hook(__FILE__, array('EvernoteSyncLoader', 'deactivation'));

        // Add plugin options page
        add_action('admin_menu', array('EvernoteSyncLoader', 'addPluginOptionsPage'));

        return true;
    }

    /*当插件启用时*/
    public static function activation()
    {
        error_log('activation');
        evernote_sync_timer();
    }

    /*当插件停止时*/
    public static function deactivation()
    {
        error_log('deactivation');
        wp_clear_scheduled_hook('evernote_sync_cron');
    }

    // 添加管理页面
    public static function addPluginOptionsPage()
    {
        if (function_exists('add_options_page')) {
            add_options_page(__('EvernoteSync', 'evernotesync'), __('EvernoteSync', 'evernotesync'), 'manage_options', 'evernotesync.php', array('EvernoteSyncLoader', 'showOptionsPage'));
        }
    }
}

EvernoteSyncLoader::enable();

// 设定间隔时间（此处设定为 30 分钟，存在误差）
add_filter('cron_schedules', 'evernote_sync_time');
function evernote_sync_time($schedules)
{
    error_log("evernote_sync_time()");
    $schedules['evernote_sync_time'] = array('interval' => 1800, 'display' => '30 minutes');
    return $schedules;
}

// 设置定时执行的任务
add_action('evernote_sync_cron', 'evernote_sync_task');
function evernote_sync_task()
{
    error_log("evernote_sync_task()");
    EvernoteSyncLoader::sync();
}

// 设置定时器
function evernote_sync_timer()
{
    error_log("evernote_sync_timer()");
    if (!wp_next_scheduled('evernote_sync_cron')) {
        error_log("evernote_sync_schedule 1 step");
        wp_schedule_event(time(), 'evernote_sync_time', 'evernote_sync_cron');
    }
}


if (!function_exists('evernote_action_links')) {
    function evernote_action_links($actions, $plugin_file, $action_links = array(), $position = 'after')
    {
        static $plugin;
        if (!isset($plugin)) {
            $plugin = plugin_basename(__FILE__);
        }
        if ($plugin == $plugin_file && !empty($action_links)) {
            foreach ($action_links as $key => $value) {
                $link = array($key => '<a target="_blank" href="' . $value['url'] . '">' . $value['label'] . '</a>');
                if ($position == 'after') {
                    $actions = array_merge($actions, $link);
                } else {
                    $actions = array_merge($link, $actions);
                }
            }//foreach
        }// if
        return $actions;
    }
}

if (!function_exists('evernote_plugin_row_meta')) {
    add_filter('plugin_row_meta', 'evernote_plugin_row_meta', 10, 2);

    function evernote_plugin_row_meta($actions, $plugin_file)
    {
        $action_links = array(
            'donatelink' => array(
                'label' => __('Donate', 'evernotesync'),
                'url' => 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=65VUX9EUS2QBE'
            ),
            'homepage' => array(
                'label' => __('Home Page', 'evernotesync'),
                'url' => 'https://www.biliyu.com'
            )
        );
        return evernote_action_links($actions, $plugin_file, $action_links, 'after');
    }
}
