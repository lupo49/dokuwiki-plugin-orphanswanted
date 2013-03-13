<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     <dae@douglasedmunds.com>
 * @author     Andy Webber <dokuwiki at andywebber dot com>
 * @author     Federico Ariel Castagnini
 * @author     Cyrille37 <cyrille37@gmail.com>
 * @author	   Matthias Schulte <dokuwiki@lupo49.de>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_INC.'inc/search.php');

class helper_plugin_orphanswanted extends DokuWiki_Plugin {

    function orph_callback_search_wanted(&$data, $base, $file, $type, $lvl, $opts) {

        if($type == 'd') {
            return true; // recurse all directories, but we don't store namespaces
        }

        if(!preg_match("/.*\.txt$/", $file)) {
            // Ignore everything but TXT
            return true;
        }

        // search the body of the file for links
        // dae mod
        //	orph_Check_InternalLinks(&$data,$base,$file,$type,$lvl,$opts);
        $this->orph_Check_InternalLinks($data,$base,$file,$type,$lvl,$opts);

        $eventData = array(
            'data' => &$data,
            'file' => $file
        );
        trigger_event('PLUGIN_ORPHANS_WANTED_PROCESS_PAGE', $eventData);

        // get id of this file
        $id = pathID($file);

        //check ACL
        if(auth_quickaclcheck($id) < AUTH_READ) {
            return false;
        }

        // try to avoid making duplicate entries for forms and pages
        $item = &$data["$id"];
        
        if(isset($item)) {
            // This item already has a member in the array
            // Note that the file search found it
            $item['exists'] = true;
        } else {
            // Create a new entry
            $data["$id"]=array('exists' => true, 'links' => 0);
        }
        return true;
    }

    function orph_handle_link(&$data, $link) {
        global $conf;

        if(isset($data[$link])) {
            // This item already has a member in the array
            // Note that the file search found it
            $data[$link]['links'] ++ ;   // count the link
        } else {
            // Create a new entry
            $data[$link] = array(
          'exists' => false,  // Only found a link, not the file
          'links'  => 1
            );
            // echo "      <!-- added link to list --> \n";
        }

        if ($conf['allowdebug']) echo "<p>-- New count for link <b>" . $link . "</b>: " . $data[$link]['links'] . "</p>\n";
    }


    /**
     * Search for internal wiki links in page $file
     */
    function orph_Check_InternalLinks( &$data, $base, $file, $type, $lvl, $opts ) {
        global $conf;

        define('LINK_PATTERN', '%\[\[([^\]|#]*)(#[^\]|]*)?\|?([^\]]*)]]%');

        if(!preg_match("/.*\.txt$/", $file)) {
            return;
        }

        $currentID = pathID($file);
        $currentNS = getNS($currentID);

        if($conf['allowdebug']) echo sprintf("<p><b>%s</b>: %s</p>\n", $file, $currentID);

        // echo "  <!-- checking file: $file -->\n";
        $body = @file_get_contents($conf['datadir'] . $file);

        // ignores entries in <nowiki>, %%, <code> and emails with @
        foreach( array(
                  '/<nowiki>.*?<\/nowiki>/',
                  '/%%.*?%%/',
                  '@<code[^>]*?>.*?<\/code>@siu',
                  '@<file[^>]*?>.*?<\/file>@siu'
        )
        as $ignored )
        {
            $body = preg_replace($ignored, '',  $body);
        }

        $links = array();
        preg_match_all( LINK_PATTERN, $body, $links );

        foreach($links[1] as $link) {
            if($conf['allowdebug']) echo sprintf("--- Checking %s<br />\n", $link);

            if( (0 < strlen(ltrim($link)))
            and ! preg_match('/^[a-zA-Z0-9\.]+>{1}.*$/u',$link) // Interwiki
            and ! preg_match('/^\\\\\\\\[\w.:?\-;,]+?\\\\/u',$link) // Windows Share
            and ! preg_match('#^([a-z0-9\-\.+]+?)://#i',$link) // external link (accepts all protocols)
            and ! preg_match('<'.PREG_PATTERN_VALID_EMAIL.'>',$link) // E-Mail (pattern above is defined in inc/mail.php)
            and ! preg_match('!^#.+!',$link) // inside page link (html anchor)
            ) {
                $pageExists = false;
                resolve_pageid($currentNS, $link, $pageExists );
                if ($conf['allowdebug']) echo sprintf("---- link='%s' %s ", $link, $pageExists?'EXISTS':'MISS');
                 
                if(((strlen(ltrim($link)) > 0)           // there IS an id?
                and !auth_quickaclcheck($link) < AUTH_READ)) {
                    // should be visible to user
                    //echo "      <!-- adding $link -->\n";
                     
                    if($conf['allowdebug']) echo ' A_LINK' ;
                     
                    $link= utf8_strtolower( $link );
                    $this->orph_handle_link($data, $link);
                }
                else
                {
                    if($conf['allowdebug']) echo ' EMPTY_OR_FORBIDDEN' ;
                }
            } // link is not empty and is a local link?
            else {
                if($conf['allowdebug']) echo ' NOT_INTERNAL';
            }

            if($conf['allowdebug']) echo "<br />\n";
        } // end of foreach link
    }

    //    three choices
    //    $params_array used to extract excluded namespaces for report
    //    orphans =  orph_report_table($data, true, false, $params_array);
    //    wanted =  orph_report_table($data, false, true), $params_array;
    //    valid  =  orph_report_table($data, true, true, $params_array);

    function orphan_pages($params_array) {
        global $conf;
        $result = '';
        $data = array();
        search($data,$conf['datadir'], array($this, 'orph_callback_search_wanted'), array('ns' => $ns));
        $result .=  $this->orph_report_table($data, true, false, $params_array, 'orphan');

        return $result;
    }

    function wanted_pages($params_array) {
        global $conf;
        $result = '';
        $data = array();
        search($data,$conf['datadir'], array($this, 'orph_callback_search_wanted'), array('ns' => $ns));
        $result .= $this->orph_report_table($data, false, true, $params_array, 'wanted');

        return $result;
    }

    function valid_pages($params_array) {
        global $conf;
        $result = '';
        $data = array();
        search($data,$conf['datadir'], array($this, 'orph_callback_search_wanted'), array('ns' => $ns));
        $result .= $this->orph_report_table($data, true, true, $params_array, 'valid');

        return $result;
    }

    function all_pages($params_array) {
        global $conf;
        $result = '';
        $data = array();
        search($data,$conf['datadir'], array($this, 'orph_callback_search_wanted') , array('ns' => $ns));

        $result .= "</p><p>Orphans</p><p>";
        $result .= $this->orph_report_table($data, true, false, $params_array, 'orphan');
        $result .= "</p><p>Wanted</p><p>";
        $result .= $this->orph_report_table($data, false, true, $params_array, 'wanted');
        $result .= "</p><p>Valid</p><p>";
        $result .= $this->orph_report_table($data, true, true, $params_array, 'valid');

        return $result;
    }

    function orph_report_table($data, $page_exists, $has_links, $params_array, $caller = null) {
        global $conf;
        $ignoredPages = $this->getConf('ignoredpages'); // Fetch pages which shouldn't be listed
        if($ignoredPages != '') {
            $ignoredPages = explode(';', $ignoredPages);
        } else {
            $ignoredPages = null;
        }

        $show_heading = ($page_exists && $conf['useheading']) ? true : false ;
        //take off $params_array[0];
        $exclude_array = array_slice($params_array,1);

        $count = 1;
        $output = '';

        // for valid html - need to close the <p> that is feed before this
        $output .= '</p>';
        $output .= '<table class="inline"><tr><th> # </th><th> ID </th>'
                    . ($show_heading ? '<th>Title</th>' : '' )
                    . ($caller != "orphan" ? '<th>Links</th>' : '')
                    . '</tr>'
                    . "\n" ;

        arsort($data);

        foreach($data as $id=>$item) {
            if( ! (($item['exists'] == $page_exists) and (($item['links'] <> 0)== $has_links)) ) continue ;

            // $id is a string, looks like this: page, namespace:page, or namespace:<subspaces>:page
            $match_array = explode(":", $id);
            //remove last item in array, the page identifier
            $match_array = array_slice($match_array, 0, -1);
            //put it back together
            $page_namespace = implode (":", $match_array);
            //add a trailing :
            $page_namespace = $page_namespace . ':';

            //set it to show, unless blocked by exclusion list
            $show_it = true;

            if(!is_null($ignoredPages) && in_array($id, $ignoredPages)) {
                    echo "Skipped page (global ignored): " . $id . "<br />";
                    $show_it = false;
            } else {
                foreach ($exclude_array as $exclude_item) {
                    //add a trailing : to each $item too
                    $exclude_item = $exclude_item . ":";
                    // need === to avoid boolean false
                    // strpos(haystack, needle)
                    // if exclusion is beginning of page's namespace , block it
                    if (strpos($page_namespace, $exclude_item) === 0) {
                        //there is a match, so block it
                        $show_it = false;
                    }
                }
            }

            if($show_it) {
                $output .=  "<tr><td>$count</td><td><a href=\"". wl($id)
                            . "\" class=\"" . ($page_exists ? "wikilink1" : "wikilink2") . "\" >"
                            . $id .'</a></td>'
                            . ($show_heading ? '<td>' . hsc(p_get_first_heading($id)) .'</td>' : '' );

                if($caller != "orphan") { // Skip "link" column if user wants orphan pages only
                    $output .= '<td>' . $item['links']
                                . ($has_links ? "&nbsp;:&nbsp;<a href=\"". wl($id, 'do=backlink')
                                . "\" class=\"wikilink1\">Show&nbsp;backlinks</a>" : '') . "</td>";
                }
                $output .= "</tr>\n";
                $count++;
            }
        }

        $output .=  "</table>\n";
        //for valid html = need to reopen a <p>
        $output .= '<p>';

        return $output;
    }
}
