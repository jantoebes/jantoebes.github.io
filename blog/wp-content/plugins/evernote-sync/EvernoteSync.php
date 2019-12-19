<?php
/**
 * Created by PhpStorm.
 * User: TangGaowei
 * Date: 2018/9/3
 * Time: 17:00
 */
require_once 'src/autoload.php';

class EvernoteSync
{
    private $record_old_table_name = "evernote_sync_pots";
    private $record_table_name = "evernote_sync_record";
    private $user_table_name = "evernote_sync_user";
    private $client = null;

    private static $sync = null;

    /**
     * 私有化构造函数
     */
    private function __construct()
    {        
        $sandbox = false;
        $token = get_option('evernotesync_token', NULL);

        $this->client = new \Evernote\Client($token, $sandbox);
        
        // 根据不同的平台调用接口
        if (get_option('evernotesync_platform', 1) == 2) {
            // Evernote 同步
            $this->client->getAdvancedClient()->setEvernote();
        } else {
            // 印象笔记同步
            $this->client->getAdvancedClient()->setYinxiang();
        }
    }

    /**
     * 单例模式
     */
    public static function instance()
    {
        if (is_null(self::$sync)) {
            self::$sync = new EvernoteSync();
        }
        return self::$sync;
    }

    /**
     * 获取同步记录的表名
     */
    public function getRecordOldTableName()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->record_old_table_name;
        return $table_name;
    }

    /**
     * 获取同步记录的表名
     */
    public function getRecordTableName()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->record_table_name;

        // 初始化同步记录表
        if ($wpdb->get_var("show tables like '$table_name'") != $table_name) {
            $sql = "CREATE TABLE IF NOT EXISTS `" . $table_name . "` (
                        `id` int(11) NOT NULL auto_increment,
                        `guid` varchar(60) default '',
                        `title` varchar(200) default '',
                        `hash` varchar(60) default '',
                        `address` varchar(200) default '',
                        `url` varchar(200) default '',
                        `created` bigint(15) default NULL,
                        `updated` bigint(15) default NULL,
                        `postid` int(11) default NULL,
                        UNIQUE KEY `id` (`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }

        return $table_name;
    }

    /**
     * 获取同步用户的表名
     */
    public function getUserTableName()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . $this->user_table_name;

        // 初始化同步记录表
        if ($wpdb->get_var("show tables like '$table_name'") != $table_name) {
            $sql = "CREATE TABLE IF NOT EXISTS `" . $table_name . "` (
                        `id` int(11) NOT NULL auto_increment,
                        `userid` bigint(15) default NULL,
                        `token` varchar(200) default '',
                        `created` bigint(15) default NULL,
                        `updated` bigint(15) default NULL,
                        UNIQUE KEY `id` (`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            dbDelta($sql);
        }

        return $table_name;
    }

    /**
     * 获取处理笔记的客户端对象
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * 获取笔记对象
     * @param $noteGuid
     */
    public function getNote($noteGuid)
    {
        return $this->client->getNote($noteGuid);
    }

    /**
     * 获取文章摘要
     */
    public function getExcerpt($str)
    {
        $excerpt = null;
        // 从<p>标签里生成摘要
        preg_match_all('/<p[^>]*?>([\s\S]*?)<\/p>/ims', $str, $mat);
        if(count($mat[0]) == 0){
            preg_match_all('/<div[^>]*?>(\s*?)<\/div>/ims', $str, $mat);
        }
        for ($i = 0; $i < count($mat[0]); $i++) {
            $tmp = strip_tags($mat[1][$i]);
            $tmp = trim($tmp);
            if (is_null($excerpt) && strlen($tmp) > 5) {
                $excerpt = $tmp;
                break;
            }
        }
        // 如果<p>标签生成摘要失败，则从纯字符串里生成摘要
        if (is_null($excerpt)) {
            // 获取 wordpress 的编码
            $charset = get_bloginfo('charset');
            // 清除字符串里的标签
            $str = strip_tags($str);
            // 截取500个字符
            $str = mb_substr($str, 0, 500);
            // 初始化位置变量
            $pos = false;
            // 参考标点
            $arr = array("。", "！", "？", "”", ".", "!", "?", "\"");
            for ($i = 0; $i < count($arr); $i++) {
                $tmp = $arr[$i];
                $pos = mb_strrpos($str, $tmp, $charset);
                if ($pos !== false) {
                    $pos++;
                    break;
                }
            }
            // 查询到标点
            if ($pos !== false) {
                $excerpt = mb_substr($str, 0, $pos, $charset);
            }
        }

        if(is_null($excerpt)) return '';

        return $excerpt;
    }

    /**
     * 构造 H 标签
     * @param $str
     */
    public function makeHeaderTag($str)
    {
        $ret = $str;

        preg_match_all('/(<div><b><span style="font-size: \d\dpx;">[^<>]+<\/span><\/b><\/div>)/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $ret = $this->formatHeaderTag($ret, $mat[1][$i]);
        }

        preg_match_all('/(<div><span style="font-size: \d\dpx;"><b>[^<>]+<\/b><\/span><\/div>)/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $ret = $this->formatHeaderTag($ret, $mat[1][$i]);
        }

        preg_match_all('/(<div style="font-weight: bold; font-size: \d\dpx;">[^<>]+<\/div>)/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $ret = $this->formatHeaderTag($ret, $mat[1][$i]);
        }

        return $ret;
    }

    private function formatHeaderTag($str, $mat_val)
    {
        $ret = $str;
        $tmp = $mat_val;
        // 提取 font-size 的值
        preg_match_all('/font-size:\s?(\d\d)px/ims', $tmp, $mat2);
        $tmp = '';
        for ($j = 0; $j < count($mat2[0]); $j++) {
            $tmp = $mat2[1][$j];
        }
        if (strlen($str) == 0) {
            return $ret;
        }
        // 将 font-size 转化为整型
        $size = intval($tmp);
        switch ($size) {
            case 48:
                $size = 1;
                break;
            case 32:
                $size = 2;
                break;
            case 24:
                $size = 3;
                break;
            case 21:
                $size = 4;
                break;
            case 19:
                $size = 5;
                break;
        }
        if ($size > 10) {
            return $ret;
        }
        // 构造标签名
        $tag = sprintf("h%d", $size);
        $tmp = preg_replace('/[\w\W]+>([^<>]+)<[\w\W]+/i', "<$tag>$1</$tag>", $mat_val);

        $ret = str_replace($mat_val, $tmp, $ret);

        return $ret;
    }

    /**
     * 格式化文章
     * @param $str
     * @return string
     */
    public function formatArticle($str)
    {
        $ret = $str;

        // 清除马克飞象的“Edit”链接
        $ret = preg_replace('/<a[^>]*?>[^<]*?Edit[^<]*?<\/a>/i', '', $ret);

        // 清除空的<div>标签
        $ret = preg_replace('/<div[^>]*?><\/div>/i', '', $ret);

        // 清除前后空格
        $ret = trim($ret);

        // 不进行格式化
        if (get_option('evernotesync_format_content', 1) == 2) {
            return $ret;
        }

        // 清除不需要的标签（<a><span><b><strong><em><u><strike><i>可出现在<p>内）
        $ret = strip_tags($ret, '<a><span><b><strong><em><u><strike><i><div><p><pre><code><video><source><embed><object><param><audio><bgsound><h1><h2><h3><h4><h5><table><tr><td><th><br><img><blockquote><ol><ul><li>');

        // 清除前后空格
        $ret = trim($ret);

        // 支持 evernote 自带的代码格式(<div style="-en-codeblock: true;...>)
        // 将 <div style="-en-codeblock: true;...> 内人 <div> 标签转为 <br/>
        preg_match_all('/(<div[^>]*?codeblock[^>]*?>[\s\S]*?<\/div><\/div>)/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $tmp = $mat[1][$i];
            preg_match_all('/(<div>[\s\S]*?<\/div>)/ims', $tmp, $mat2);
            $tmp = '';
            for ($j = 0; $j < count($mat2[0]); $j++) {
                $tmp2 = $mat2[1][$j];
                if ('<div><br/></div>' == $tmp2) {
                    $tmp2 = '<br/>';
                } else {
                    $tmp2 = preg_replace('/<div>/i', '', $tmp2);
                    $tmp2 = preg_replace('/<\/div>/i', '<br/>', $tmp2);
                }
                $tmp .= $tmp2;
            }
            if (!empty($tmp)) {
                $tmp = str_replace('<br/>', "\n", $tmp);
                $tmp = '<pre><code>' . $tmp . '</code></pre>';
            }
            $ret = str_replace($mat[1][$i], $tmp, $ret);
        }

        // 清除 pre 标签下的非<br>标签
        preg_match_all('/<pre[^>]*?>([\s\S]*?)<\/pre>/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $tmp = $mat[1][$i];
            $tmp = preg_replace('/<br\/>/i', '#--br/--#', $tmp);
            // 清楚非code、pre标签
            $tmp = preg_replace('/<(?!code|\/code)[^>]*?>/i', '', $tmp);
            $ret = str_replace($mat[1][$i], $tmp, $ret);
        }

        // 清除 code 标签下的非<br>标签
        preg_match_all('/<code[^>]*?>([\s\S]*?)<\/code>/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $tmp = $mat[1][$i];
            $tmp = preg_replace('/<br\/>/i', '#--br/--#', $tmp);
            // 清楚非code、pre标签
            $tmp = preg_replace('/<(?!code|\/code)[^>]*?>/i', '', $tmp);
            $ret = str_replace($mat[1][$i], $tmp, $ret);
        }

        // 构造 H 标签
        $ret = $this->makeHeaderTag($ret);

        // 清除标签属性
        $ret = preg_replace('/<(div|p|pre|code)((\s[^>]*)?)>/i', '<$1>', $ret);

        // 将不参加处理的标签进行转义
        $ret = preg_replace('/(<)(\/?)(\b(a|span|b|strong|em|u|strike|i|img|h\d)\b)(([^>]*?)?)(>)/ims', '#--$2$3$5--#', $ret);

        // 格式化<br>
        $ret = preg_replace('/<br[^>]*?>/ims', '<br/>', $ret);

        // 保留<table>标签的样式和内部标签
        preg_match_all('/(<table[^>]*?>[\s\S]*?<\/table>)/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $tmp = $mat[1][$i];
            $tmp = str_replace('<', '#--', $tmp);
            $tmp = str_replace('>', '--#', $tmp);
            $ret = str_replace($mat[1][$i], $tmp, $ret);
        }

        // 清除'>'和'<'间的空格
        $ret = preg_replace('/>(\s*?)</ims', '><', $ret);

        // 清除重复的<br>标签
        $ret = preg_replace('/(<br\/>){2,10}/i', '$1', $ret);

        // 清除 <div.../>、<p.../>、<span.../>
        $ret = preg_replace('/<(div|p|span)((\s[^>]*)?)\/>/i', '', $ret);

        // 清除空标签
        $ret = preg_replace('/<([0-9a-zA-Z]+)[^>\/]*?>[\s]*?<\/\1>/i', '', $ret);

        // 清除空标签
        $ret = preg_replace('/<([0-9a-zA-Z]+)[^>\/]*?>[\s]*?<br\/>[\s]*?<\/\1>/i', '<br/>', $ret);

        // <div> to <p>
        $ret = preg_replace('/<div/ims', '<p', $ret);
        $ret = preg_replace('/<\/div.*?>/ims', '</p>', $ret);

        // 清除重复的<p><p>和</p></p>标签
        $ret = preg_replace('/(<p>){2,10}/i', '$1', $ret);
        $ret = preg_replace('/(<\/p>){2,10}/i', '$1', $ret);

        // 清除<p>中的<br/>
        preg_match_all('/<p[^>]*?>([\s\S]*?)<\/p>/ims', $ret, $mat);
        for ($i = 0; $i < count($mat[0]); $i++) {
            $tmp = $mat[1][$i];
            $tmp = preg_replace('/<br[^>]*?>/i', '', $tmp);
            $ret = str_replace($mat[1][$i], $tmp, $ret);
        }

        // 将 <br> 前面的段落放到 <p> 中
        $ret = preg_replace('/([^<>]+)<br\/>/ims', '<p>$1</p>', $ret);

        // 清理无用的 <br>
        $ret = preg_replace('/<br\/>/i', '', $ret);

        // 将首尾的段落放到 <p> 中
        $ret = preg_replace('/([^<>]+)$/i', '<p>$1</p>', $ret);
        $ret = preg_replace('/^([^<>]+)/i', '<p>$1</p>', $ret);

        // 清除空仅包含 <img> 标签的 <p>
        $ret = preg_replace('/<p>\s*?(<img[^>]*?>)\s*?<\/p>/ims', '$1', $ret);

        // 清除<p></p>
        $ret = preg_replace('/<p><\/p>/ims', '', $ret);

        // 恢复<p>内的标签转义
        $ret = str_replace('#--', '<', $ret);
        $ret = str_replace('--#', '>', $ret);

        // 清楚标签里的 style 属性
        $ret = preg_replace('/<(h\d|a|li|ul)((\s[^>]*)?)(\sstyle="[^"]*?"|\sstyle=\'[^\']*?\')/ims', '<$1$2', $ret);

        return addslashes($ret);
    }

    /**
     * 定时处理
     * @param bool $force
     * @return bool
     */
    public function timer($force = false)
    {
        // 当前时间戳
        $time = current_time('timestamp');
        // 最后一次同步的时间
        $laststr = get_option('evernotesync_last');
        // 发布方式（1 发布、2 草稿、3 定时）
        $mode = get_option('evernotesync_publish_mode');
        // 定时的时间点
        $timedstr = get_option('evernotesync_timed_time');

        // 保存最后一次同步时间
        update_option('evernotesync_last', date("Y-m-d H:i:s", $time));

        if (($laststr && $mode && $timedstr) && !$force) {
            if ($mode == 3) {
                $lasttime = strtotime($laststr);
                $datestr = date("Y-m-d ", $time);
                $timedtime = strtotime($datestr . $timedstr . ':00');

                $logstr = $laststr;
                $logstr = $logstr . '<br/>';
                $logstr = $logstr . $datestr . $timedstr . ':00';
                $logstr = $logstr . '<br/>';
                $logstr = $logstr . date("Y-m-d H:i:s", $time);
                if ($timedtime < $lasttime || $timedtime >= $time) {
                    $logstr = $logstr . 'false';
                } else {
                    $logstr = $logstr . 'true';
                }

                update_option('evernotesync_log', $logstr);

                if ($timedtime < $lasttime || $timedtime >= $time) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 创建多级目录
     */
    function mkdirs($dir)
    {
        if (!is_dir($dir)) {
            if (!mkdirs(dirname($dir))) {
                return false;
            }
            if (!mkdir($dir, 0777)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 根据标签搜索笔记
     */
    public function searchNotes()
    {        
        $notebook = null;
        $syncTag = get_option('evernotesync_tag', 'posts');
        $search = new \Evernote\Model\Search('tag:' . $syncTag);

        /**
         * 在默认范围搜索
         */
        $scope = \Evernote\Client::SEARCH_SCOPE_DEFAULT;

        /**
         * 按钮修改时间排序
         */
        $order = \Evernote\Client::SORT_ORDER_RECENTLY_UPDATED;

        /**
         * 返回的最大记录数
         */
        $syncCount = get_option('evernotesync_sync_count', 10);
        error_log("syncCount=$syncCount");
        $maxResult = get_option('evernotesync_sync_count', 10);
        error_log("maxResult=$maxResult");

        return $this->client->findNotesWithSearch($search, $notebook, $scope, $order, $maxResult);
    }

    /**
     * 获取同步资源的URL
     */
    public function getBaseUrl()
    {
        $now = time();
        $month = date("m", $now);
        $year = date("Y", $now);
        $upload_dir = wp_upload_dir();
        $baseurl = $upload_dir['baseurl'] . "/" . $year . "/" . $month . "/";

        // 清除路径里带的双引号
        return str_replace('"', '', $baseurl);
    }

    /**
     * 获取笔记内容
     */
    public function getContent($note)
    {
        $content = $note->content;

        // 删除 xml 标签
        $content = preg_replace('/<\?xml[^>]*?>/ims', '', $content);
        $content = preg_replace('/<!DOCTYPE[^>]*?>/ims', '', $content);
        $content = preg_replace('/<en-note[^>]*?>/ims', '', $content);
        $content = str_replace('</en-note>', '', $content);

        // 删除印象笔记的 <del> 标签
        $content = preg_replace('/<del[^>]*?>[\s\S]*?<\/del>/ims', '', $content);

        // 删除印象笔记的隐藏标签
        return preg_replace('/<center[^>]*?display[^>]*?>[\s\S]*?<\/center>/ims', '', $content);
    }

    /**
     * 获取笔记的资源
     * @param $note
     */
    public function getResourceArray($note)
    {
        $res_array = array();

        // 获取图片资源
        $basedir = $this->getBaseDir();
        $baseurl = $this->getBaseUrl();

        // 创建多级目录
        $this->mkdirs($basedir);

        if (is_array($note->resources)) {
            for ($i = 0; $i < count($note->resources); $i++) {
                // 获取资源
                $resource = $note->resources[$i];
                // 获取资源类型
                $mime = $resource->mime;
                // 是否为图片
                $is_image = true;
                if (strpos($mime, 'image') === false) {
                    $is_image = false;
                }
                $ext_name = 'zip';
                if ($is_image) {
                    $ext_name = preg_replace('/image\//i', '', $mime);
                }
                // 获取资源的hash值
                $bin = unpack("H*", $resource->data->bodyHash);
                $hash = $bin[1];
                $hash = strtolower($hash);
                // 使用 hash 值作为文件名称
                $filename = $hash . '.' . $ext_name;
                // 获取资源属性
                $attributes = $resource->attributes;
                if (!empty($attributes)) {
                    if (!is_null($attributes->fileName)) {
                        $filename = $attributes->fileName;
                        $ext_name = $this->getExtName($filename);
                    }
                }
                // 构造资源将要传出的物理地址
                $filepath = $basedir . $hash . '.' . $ext_name;
                // 记录资源的URL
                $url = $baseurl . $hash . '.' . $ext_name;
                // 保存到数组
                $res_array[$hash] = array(
                    'resource' => $resource,
                    'filename' => $filename,
                    'url' => $url,
                    'filepath' => $filepath,
                    'is_image' => $is_image
                );
            }
        }

        return $res_array;
    }

    /**
     * 获取文件后缀
     */
    function getExtName($filepath)
    {
        return pathinfo($filepath, PATHINFO_EXTENSION);
    }

    /**
     * 上传资源
     * @param $res_array
     */
    public function uploadResources($res_array)
    {
        $featureid = null;

        if (is_array($res_array)) {
            foreach ($res_array as $key => $res) {
                if (is_array($res)) {
                    $resource = $res['resource'];
                    $filepath = $res['filepath'];
                    $is_image = $res['is_image'];

                    // 避免重复上传
                    if (!file_exists($filepath)) {
                        // 上传图片
                        file_put_contents($filepath, $resource->data->body, LOCK_EX);
                        // 将图片添加到多媒体，便于管理
                        $wp_filetype = wp_check_filetype(basename($filepath), null);
                        $wp_upload_dir = wp_upload_dir();
                        $attachment = array(
                            'guid' => $wp_upload_dir['url'] . '/' . basename($filepath),
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => preg_replace('/\.[^.]+$/', '', basename($filepath)),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        );
                        $attach_id = wp_insert_attachment($attachment, $filepath);
                        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
                        wp_update_attachment_metadata($attach_id, $attach_data);
                        // 将第一张图片作为文章的特征图
                        if (is_null($featureid) && $is_image) {
                            $featureid = $attach_id;
                        }
                    }
                }
            }
        }

        return $featureid;
    }

    /**
     * 获取同步记录
     * @param $noteGuid
     */
    public function getSyncRecord($noteGuid)
    {
        global $wpdb;
        $table_name = $this->getRecordTableName();
        $records = $wpdb->get_results("SELECT postid, updated FROM $table_name where guid='$noteGuid'");
        if (is_array($records) && count($records) > 0) {
            return $records[0];
        }

        // 如果新表里没有查找，则到旧表里再查一遍
        $table_name = $this->getRecordOldTableName();
        if ($wpdb->get_var("show tables like '$table_name'") == $table_name) {
            $records = $wpdb->get_results("SELECT postid, updated FROM $table_name where guid='$noteGuid'");
            if (is_array($records) && count($records) > 0) {
                return $records[0];
            }
        }
    }

    /**
     * 获取同步资源的物理地址
     */
    public function replaceMediaTags($content, $res_array)
    {
        $media_pattern = "/<en-media[^>]*>/ims";
        preg_match_all($media_pattern, $content, $output_array);
        $media_array = $output_array[0];
        if (is_array($media_array)) {
            foreach ($media_array as $media_inner) {
                // 解析出hash值
                preg_match('/hash="([^"]*)"/', $media_inner, $matches);
                $hash = $matches[1];
                $hash = strtolower($hash);
                // 解析出 style 属性
                preg_match('/style="([^"]*)"/', $media_inner, $matches);
                $style = '';
                if(!empty($matches)) $style=$matches[1];
                // 解析出 width 属性
                preg_match('/width="([^"]*)"/', $media_inner, $matches);                
                $width = '';
                if(!empty($matches)) $width = $matches[1];
                // 解析出 height 属性
                preg_match('/height="([^"]*)"/', $media_inner, $matches);                
                $height = '';
                if(!empty($matches)) $height = $matches[1];

                // 获取资源信息
                $res = $res_array[$hash];
                if (!is_null($res)) {
                    $filename = $res['filename'];
                    $is_image = $res['is_image'];
                    $url = $res['url'];
                    $url = str_replace('"', '', $url);
                    if ($is_image) {
                        // 图片资源生成图片标签用于显示
                        if (get_option('evernotesync_sync_image_attribute', 2) == 1) {
                            $theHTML = '<img src="' . $url . '" style="' . $style . '" width="' . $width . '" height="' . $height . '"/>';
                        }
                        else{
                            $theHTML = '<img src="' . $url . '"/>';
                        }
                    } else {
                        // 非图片的资源生成链接用于下载
                        $theHTML = '<a href="' . $url . '">' . $filename . '</a>';
                    }
                    $content = str_replace($media_inner, $theHTML, $content);
                    $content = str_replace('</en-media>', '', $content);
                }
            }
        }
        return $content;
    }

    /**
     * 获取同步资源的物理地址
     */
    public function getBaseDir()
    {
        $now = time();
        $month = date("m", $now);
        $year = date("Y", $now);
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'] . "/" . $year . "/" . $month . "/";

        // 清除路径里带的双引号
        return str_replace('"', '', $basedir);
    }

    /**
     * 检查文章是否已经更新
     */
    public function checkUpdated($result)
    {
        global $wpdb;

        $table_name = $this->getRecordTableName();
        $noteGuid = $result->guid;
        $noteUpdated = $result->updated;

        $records = $wpdb->get_results("SELECT postid, updated FROM $table_name where guid='$noteGuid'");
        if (is_array($records) && count($records) > 0) {
            $record = $records[0];
            if ($noteUpdated <= $record->updated) {
                return TRUE;
            }
        }

        // 如果新表里没有查找，则到旧表里再查一遍
        $table_name = $this->getRecordOldTableName();
        if ($wpdb->get_var("show tables like '$table_name'") == $table_name) {
            $records = $wpdb->get_results("SELECT postid, updated FROM $table_name where guid='$noteGuid'");
            if (is_array($records) && count($records) > 0) {
                $record = $records[0];
                if ($noteUpdated <= $record->updated) {
                    return TRUE;
                }
            }
        }

        return FALSE;
    }

} 