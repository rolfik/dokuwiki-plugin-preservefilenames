<?php
/**
 * DokuWiki Plugin PreserveFilenames
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Kazutaka Miyasaka <kazmiya@gmail.com>
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'action.php');

class action_plugin_preservefilenames extends DokuWiki_Action_Plugin {
    /**
     * Returns some info
     */
    function getInfo() {
        return confToHash(DOKU_PLUGIN.'preservefilenames/plugin.info.txt');
    }

    /**
     * Registers an event handler
     */
    function register(&$controller) {
        $controller->register_hook('MEDIA_UPLOAD_FINISH',         'AFTER',  $this, '_saveMeta');
        $controller->register_hook('MEDIA_DELETE_FILE',           'AFTER',  $this, '_deleteMeta');
        $controller->register_hook('MEDIA_SENDFILE',              'BEFORE', $this, '_sendFile');
        $controller->register_hook('PARSER_HANDLER_DONE',         'BEFORE', $this, '_replaceLinkTitle');
        $controller->register_hook('MEDIAMANAGER_STARTED',        'AFTER',  $this, '_exportToJSINFO');
        $controller->register_hook('MEDIAMANAGER_CONTENT_OUTPUT', 'BEFORE', $this, '_showMediaList');
        $controller->register_hook('AJAX_CALL_UNKNOWN',           'BEFORE', $this, '_showMediaListAjax');
    }

    /**
     * Saves the name of the uploaded media file to a meta file
     */
    function _saveMeta(&$event) {
        global $conf;

        $id = $event->data[2];
        $filename_tidy = noNS($id);

        // retrieve original filename
        if (!empty($_POST['id'])) { // via normal uploader
            $filename_pat = $conf['useslash'] ? '/([^:;\/]*)$/' : '/([^:;]*)$/';
            preg_match($filename_pat, $_POST['id'], $matches);
            $filename_orig = $matches[1];
        } elseif (isset($_FILES['Filedata'])) { // via multiuploader
            $filename_orig = $_FILES['upload']['name'];
        } else {
            return;
        }
        $filename_safe = $this->_sanitizeFileName($filename_orig);

        // no need to backup original filename
        if ($filename_tidy === $filename_safe) return;

        // fallback if suspicious characters found
        if ($filename_orig !== $filename_safe) return;

        // save original filename to metadata
        $metafile = metaFN($id, '.filename');
        io_saveFile($metafile, serialize(array(
            'filename' => $filename_safe,
        )));
    }

    /**
     * Deletes a meta file associated with the deleted media file
     */
    function _deleteMeta(&$event) {
        $id = $event->data['id'];
        $metafile = metaFN($id, '.filename');
        if (@unlink($metafile)) io_sweepNS($id, 'metadir');
    }

    /**
     * Sends a media file with its original filename
     * 
     * @see sendFile() in lib/exe/fetch.php
     */
    function _sendFile(&$event) {
        global $conf;
        global $MEDIA;

        $d = $event->data;
        $event->preventDefault();
        list($file, $mime, $dl, $cache) = array($d['file'], $d['mime'], $d['download'], $d['cache']);

        $fmtime = @filemtime($file);

        // send headers
        header("Content-Type: $mime");
        // smart http caching headers
        if ($cache == -1) {
            // cache
            // cachetime or one hour
            header('Expires: '.gmdate('D, d M Y H:i:s', time() + max($conf['cachetime'], 3600)).' GMT');
            header('Cache-Control: public, proxy-revalidate, no-transform, max-age='.max($conf['cachetime'], 3600));
            header('Pragma: public');
        } elseif ($cache > 0) {
            // recache
            // remaining cachetime + 10 seconds so the newly recached media is used
            header('Expires: '.gmdate("D, d M Y H:i:s", $fmtime + $conf['cachetime'] + 10).' GMT');
            header('Cache-Control: public, proxy-revalidate, no-transform, max-age='.max($fmtime - time() + $conf['cachetime'] + 10, 0));
            header('Pragma: public');
        } elseif ($cache == 0) {
            // nocache
            header('Cache-Control: must-revalidate, no-transform, post-check=0, pre-check=0');
            header('Pragma: public');
        }
        // send important headers first, script stops here if '304 Not Modified' response
        http_conditionalRequest($fmtime);

        // retrieve original filename and send Content-Disposition header
        $filename = $this->_getOriginalFileName($MEDIA);
        if ($filename === false) $filename = urldecode($this->_correctBasename($d['file']));
        header($this->_buildContentDispositionHeader($dl, $filename));

        // use x-sendfile header to pass the delivery to compatible webservers
        if (http_sendfile($file)) exit;

        // send file contents
        $fp = @fopen($file, 'rb');
        if ($fp) {
            http_rangeRequest($fp, filesize($file), $mime);
        } else {
            header('HTTP/1.0 500 Internal Server Error');
            print "Could not read $file - bad permissions?";
        }
    }

    /**
     * Replaces titles of non-labeled internal media links with their original filenames
     * 
     * @see _media in inc/parser/xhtml.php
     */
    function _replaceLinkTitle(&$event) {
        global $ID;
        global $conf;

        require_once(DOKU_INC.'inc/JpegMeta.php');

        $ns = getNS($ID);

        // get the instructions list from the handler
        $calls =& $event->data->calls;

        // array index numbers for readability
        list($handler_name, $instructions, $source, $title, $linking) = array(0, 1, 0, 1, 6);

        // scan internal media with no link title
        $last = count($calls) - 1;
        for ($i = 0; $i <= $last; $i++) {
            if (!preg_match('/^(?:in|ex)ternalmedia$/', $calls[$i][$handler_name])) continue;
            if (!empty($calls[$i][$instructions][$title])) continue;

            $inst =& $calls[$i][$instructions];

            list($src, $hash) = explode('#', $inst[$source], 2);
            if ($calls[$i][$handler_name] === 'internalmedia') {
                resolve_mediaid($ns, $src, $exists);
                if (!$exists && !$this->getConf('fix_phpbug37738')) continue;
            }

            list($ext, $mime, $dl) = mimetype($src);
            $render = ($inst[$linking] === 'linkonly') ? false : true;

            // are there any link title alternatives?
            if (substr($mime, 0, 5) === 'image') {
                if ($ext == 'jpg' || $ext == 'jpeg') {
                    $jpeg = new JpegMeta(mediaFN($src));
                    if ($jpeg !== false && $jpeg->getTitle()) continue;
                }
                if ($render) continue;
            } elseif ($mime == 'application/x-shockwave-flash' && $render) {
                continue;
            }

            // fill the title with original filename
            if ($calls[$i][$handler_name] === 'internalmedia') {
                $filename = $this->_getOriginalFileName($src);
                if ($filename !== false) {
                    $inst[$title] = $filename;
                    continue;
                }
            }

            // use a workaround for phpbug#37738
            if ($this->getConf('fix_phpbug37738')) {
                $inst[$title] = $this->_correctBasename(noNS($src));
            }
        }
    }

    /**
     * Exports configuration settings to $JSINFO
     */
    function _exportToJSINFO(&$event) {
        global $JSINFO;

        $JSINFO['plugin_preservefilenames'] = array(
            'in_mediamanager' => true,
        );
    }

    /**
     * Shows a list of media
     */
    function _showMediaList(&$event) {
        global $NS;
        global $AUTH;
        global $JUMPTO;

        if ($event->data['do'] !== 'filelist') return;
        $event->preventDefault();

        ptln('<div id="media__content">');
        $this->_listMedia($NS, $AUTH, $JUMPTO);
        ptln('</div>');
    }

    /**
     * Shows a list of media via ajax
     */
    function _showMediaListAjax(&$event) {
        global $JUMPTO;

        if ($event->data !== 'medialist_preservefilenames') return;
        $event->preventDefault();

        require_once(DOKU_INC.'inc/media.php');

        $ns = cleanID($_POST['ns']);
        $auth = auth_quickaclcheck("$ns:*");

        $this->_listMedia($ns, $auth, $JUMPTO);
    }

    /**
     * Return list of files for the Mediamanager
     * 
     * @see media_filelist() in inc/media.php
     */
    function _listMedia($ns, $auth, $jumpto) {
        global $conf;
        global $lang;

        print '<h1 id="media__ns">:'.hsc($ns).'</h1>'.NL;

        if ($auth < AUTH_READ) {
            print '<div class="nothing">'.$lang['nothingfound'].'</div>'.NL;
        } else {
            media_uploadform($ns, $auth);

            $dir = utf8_encodeFN(str_replace(':', '/', $ns));
            $data = array();
            search($data, $conf['mediadir'], 'search_media',
                    array('showmsg' => true, 'depth' => 1), $dir);

            if (empty($data)) {
                print '<div class="nothing">'.$lang['nothingfound'].'</div>'.NL;
            } else {
                foreach ($data as $item) {
                    $filename = $this->_getOriginalFileName($item['id']);
                    if ($filename !== false) $item['file'] = utf8_encodeFN($filename);
                    media_printfile($item, $auth, $jumpto);
                }
            }
        }
        media_searchform($ns);
    }

    /**
     * Returns original filename if exists
     */
    function _getOriginalFileName($id) {
        $meta = unserialize(io_readFile(metaFN($id, '.filename'), false));
        return empty($meta['filename']) ? false : $this->_sanitizeFileName($meta['filename']);
    }

    /**
     * Returns a sanitized safe filename
     * 
     * @see http://en.wikipedia.org/wiki/Filename
     */
    function _sanitizeFileName($filename) {
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '',  $filename); // control
        $filename = preg_replace('/["*:<>?|\/\\\\]/', '_', $filename); // graphic
        return $filename;
    }

    /**
     * Builds appropriate "Content-Disposition" header strings
     */
    function _buildContentDispositionHeader($download, $filename) {
        // use RFC2231 if enabled and accessed via RFC2231-compliant browsers
        $use_rfc2231 = false;
        if ($this->getConf('use_rfc2231') && isset($_SERVER['HTTP_USER_AGENT'])
                && preg_match('/(?:Gecko|Opera)\//', $_SERVER['HTTP_USER_AGENT'])) {
            $use_rfc2231 = true;
        }

        $type = $download ? 'attachment' : 'inline';
        $escaped = rawurlencode($filename); // use *raw*urlencode (space matters)
        $filename_part = $use_rfc2231 ? "filename*=UTF-8''$escaped" : 'filename="'.$escaped.'"';

        return "Content-Disposition: $type; $filename_part;";
    }

    /**
     * Returns a correct basename
     * 
     * (fixes PHP Bug #37738: basename() bug in handling multibyte filenames)
     */
    function _correctBasename($path) {
        static $rawurldecode_callback;

        if (!isset($rawurldecode_callback)) {
            $rawurldecode_callback = create_function(
                '$matches',
                'return rawurldecode($matches[0]);'
            );
        }
        return rawurldecode(basename(preg_replace_callback(
            '/%(?:[013-7][0-9a-fA-F]|2[0-46-9a-fA-F])/', // ASCII except for '%'
            $rawurldecode_callback,
            rawurlencode($path)
        )));
    }
}
