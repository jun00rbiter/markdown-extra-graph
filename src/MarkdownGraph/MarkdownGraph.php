<?php
namespace jun00rbiter\MarkdownGraph;

/**
 * MarkdownGraph  -  PHP Markdown Extra に graphviz のグラフ記述シンタックスを追加
 *
 * @package   markdown-extra-graph
 * @author    jun00rbiter
 * @copyright jun00rbiter <https://github.com/jun00rbiter/markdown-extra-graph>
 * @copyright (Original PHP Markdown Extra) Michel Fortin <https://michelf.com/projects/php-markdown/>
 * @copyright (Original Markdown) John Gruber <https://daringfireball.net/projects/markdown/>
 */

use Michelf\MarkdownExtra;

class MarkdownGraph extends MarkdownExtra
{
    public $dotStoreDirectory = '';
    public $imageStoreDirectory = '';
    public $urlPrefix = '';
    public $graphvizDir = '';
    public $imageType = 'png';
    public $createImageTypes = ['png','svg'];

    public $chapter_no = 0;
    public $section_no = 0;
    public $paragraph_no = 0;
    public $figure_no = 0;
    public $list_no = 0;
    public $table_no = 0;

    /**
     * コンストラクタ
     * @return void
     */
    public function __construct()
    {
        $this->block_gamut += array(
            "doGraphvizBlocks"    => 100,
        );
        $this->span_gamut += array(
            "parseCodeKeySpan" => -60,
        );
        $this->span_gamut += array(
            "parseDeleteSpan" => -20,
        );
        parent::__construct();
    }

    /**
     * グラフブロックを抽出してHTMLに変換する。
     *
     * コードブロックと同じように、`@@@`の行でグラフスクリプトをはさむ。
     * グラフスクリプトを graphviz で処理して、svg ファイルを作成。
     * svg ファイルを object タグで読み込む HTML を出力する。
     * @return string
     */
    protected function doGraphvizBlocks($text)
    {
        $less_than_tab = $this->tab_width;

        $text = preg_replace_callback('{
                (?:\n|\A)
                # 1: Opening marker
                (
                    (?:@{3,})                               # 3 or more tildes/backticks.
                )
                [ ]*
                (?:
                    \.?([-_:a-zA-Z0-9]+)                    # $2: standalone class name
                )?
                [ ]*
                (?:
                    \"(.+)\"                                # $3: graph title
                )?
                [ ]*
                (?:
                    ' . $this->id_class_attr_catch_re . '   # $4: Extra attributes
                )?
                [ ]* \n                                     # Whitespace and newline following marker.

                # $5: Content
                (
                    (?>
                        (?!\1 [ ]* \n)                      # Not a closing marker.
                        .*\n+
                    )+
                )

                # Closing marker.
                \1 [ ]* (?= \n )
            }xm',
            array($this, '_doGraphvizBlocks_callback'), $text);

        return $text;
    }

    /**
     * グラフブロックを処理するコールバック関数
     * @param  array $matches
     * @return string
     */
    protected function _doGraphvizBlocks_callback($matches)
    {
        $classname =& $matches[2];
        $attrs     =& $matches[4];
        $title     = trim($matches[3]);
        $codeblock = $matches[5];


        $vizDir = rtrim($this->graphvizDir, '/');
        $vizDir = !empty($vizDir)? $vizDir.'/' : '';
        $dotDir = rtrim($this->dotStoreDirectory, '/');
        $dotDir = !empty($dotDir)? $dotDir.'/' : '';
        $imgDir = rtrim($this->imageStoreDirectory, '/');
        $imgDir = !empty($imgDir)? $imgDir.'/' : '';

        if(!file_exists($imgDir)){
            mkdir($imgDir);
        }
        if(!file_exists($dotDir)){
            mkdir($dotDir);
        }

        if(!empty($this->graphvizDir)){
            $out = [];
            $filebase = md5($codeblock);
            $dothash = $filebase;
            if(!empty($title)){
                $filebase = trim(preg_replace('{[/\\~:*+&%$#!?")(]}', '_', $title));
            }

            $makeSvg = false;
            if(!file_exists("{$dotDir}{$filebase}.dot")){
                $makeSvg = true;
            }else{
                if($dothash !== md5(file_get_contents("{$dotDir}{$filebase}.dot"))){
                    $makeSvg = true;
                }
            }
            if($makeSvg){
                file_put_contents("{$dotDir}{$filebase}.dot", $codeblock);
                foreach($this->createImageTypes as $imageType){
                    $cmd = "{$vizDir}dot.exe -Nfontname=serif -Gfontname=serif -Efontname=serif -T{$imageType} -o {$imgDir}{$filebase}.{$imageType} {$dotDir}{$filebase}.dot";
                    exec($cmd, $out);
                }
            }

            $classes = array();
            if ($classname != "") {
                if ($classname{0} == '.') {
                    $classname = substr($classname, 1);
                }
                $classes[] = $this->code_class_prefix . $classname;
            }
            $attr_str = $this->doExtraAttributes('img', $attrs, null, $classes);

            // $codeblock  = "<img$attr_str src=\"graph/{$filebase}.svg\" />";
            $codeblock =
                "<figure>\n" .
                "<figcaption>$title</figcaption>\n" .
                "<img$attr_str src=\"{$this->urlPrefix}{$filebase}.{$this->imageType}\" />\n" .
                "</figure>";
        }else{
            $codeblock = '<pre><code>'.$title."\n".$codeblock.'</code></pre>';
        }
        return "\n".$this->hashBlock($codeblock)."\n\n";
    }

    public function transform($text)
    {
        $text = parent::transform($text);

        $text = preg_replace_callback(
            '{
                (?:
                    ^[ ]*(\<h               # $1: header tag
                        ([123])             # $2: header level
                        (.*?)               # $3: attributes
                    [ ]*\>)
                    (.*)                    # $4: header title
                    \</h\2\>
                    [ ]*\n+
                )|(?:
                    ^[ ]*(\<figcaption\>)   # $5: figcaption tag
                    (.*?)                   # $6: fig or list caption
                    \</figcaption\>
                    [ ]*\n+
                    (?=\<(img|object|pre))  # $7: after tag
                )|(?:
                    ^[ ]*(\<caption\>)      # $8: caption tag
                    (.*?)                   # $9: table caption
                    \</caption\>
                    [ ]*\n+
                )
            }mx',
            array($this, '_doNumbers_callback'), $text);

        return $text;
    }

    /**
     * Callback for setext headers
     * @param  array $matches
     * @return string
     */
    protected function _doNumbers_callback($matches)
    {
        $block = $matches[0];
        if (!empty($matches[4])) {
            $level =& $matches[2];
            $attr  =& $matches[3];
            $title =& $matches[4];

            $header_str = '';
            if ($level==1) {
                $this->chapter_no++;
                $this->section_no=0;
                $this->paragraph_no=0;
                $this->list_no = 0;
                $this->figure_no = 0;
                $this->table_no = 0;
                $header_str = "{$this->chapter_no} ";
            } elseif ($level==2) {
                $this->section_no++;
                $this->paragraph_no=0;
                $header_str = "{$this->chapter_no}.{$this->section_no} ";
            } elseif ($level==3) {
                $this->paragraph_no++;
                $header_str = "{$this->chapter_no}.{$this->section_no}.{$this->paragraph_no} ";
            }

            $id = sprintf("sec_%02d_%02d_%02d", $this->chapter_no, $this->section_no, $this->paragraph_no);
            $block = "<h$level id=\"$id\"$attr>$header_str$title</h$level>\n";
        }

        if (!empty($matches[6])) {
            $title =& $matches[6];
            $after =& $matches[7];

            $fig_str = '';
            switch ($after) {
            case 'pre':
                $this->list_no++;
                   $fig_str = "リスト{$this->chapter_no}.{$this->list_no}) ";
                $id = sprintf("list_%02d_%02d", $this->chapter_no, $this->list_no);
                break;
            case 'object':
            case 'img':
                $this->figure_no++;
                  $fig_str = "図{$this->chapter_no}.{$this->figure_no}) ";
                $id = sprintf("fig_%02d_%02d", $this->chapter_no, $this->figure_no);
            }

            $block = "<figcaption id=\"$id\">$fig_str$title</figcaption>\n";
        }

        if (!empty($matches[9])) {
            $title =& $matches[9];
            $this->table_no++;
            $table_str = "表{$this->chapter_no}.{$this->table_no}) ";
            $id = sprintf("table_%02d_%02d", $this->chapter_no, $this->table_no);
            $block = "<caption id=\"$id\">$table_str$title</caption>\n";
        }
        return "$block";
    }

    protected function _doImages_inline_callback($matches)
    {
        $whole_match    = $matches[1];
        $alt_text       = $matches[2];
        $url            = $matches[3] == '' ? $matches[4] : $matches[3];
        $title          =& $matches[7];
        $attr  = $this->doExtraAttributes("img", $dummy =& $matches[8]);

        $alt_text = $this->encodeAttribute($alt_text);
        $url = $this->encodeURLAttribute($url);
        $result ='';
        if (!empty(trim($title))) {
            $result =
                "<figure>\n".
                "<figcaption>$title</figcaption>\n";
        }
        $result .=
            "<img src=\"$url\" alt=\"$alt_text\"";
        if (isset($title)) {
            $title = $this->encodeAttribute($title);
            $result .=  " title=\"$title\""; // $title already quoted
        }
        $result .= $attr;
        $result .= $this->empty_element_suffix;
        if (isset($title)) {
            $result .=  "\n</figure>";
        }
        return $this->hashPart($result);
    }

    protected function doFencedCodeBlocks($text)
    {
        $less_than_tab = $this->tab_width;

        $text = preg_replace_callback(
            '{
                (?:\n|\A)
                # 1: Opening marker
                (
                    (?:~{3,}|`{3,})                         # 3 or more tildes/backticks.
                )
                [ ]*
                (?:
                    \.?([-_:a-zA-Z0-9]+)                # $2: standalone class name
                )?
                [ ]*
                (?:
                    \"(.+)\"                                # $3: graph title
                )?
                [ ]*
                (?:
                    ' . $this->id_class_attr_catch_re . '   # $4: Extra attributes
                )?
                [ ]* \n                                 # Whitespace and newline following marker.

                # $5: Content
                (
                    (?>
                    (?!\1 [ ]* \n)                      # Not a closing marker.
                    .*\n+
                    )+
                )

                # Closing marker.
                \1 [ ]* (?= \n )
            }xm',
            array($this, '_doFencedCodeBlocks_callback'), $text);

        return $text;
    }

    /**
    * Callback to process fenced code blocks
    * @param  array $matches
    * @return string
    */
    protected function _doFencedCodeBlocks_callback($matches)
    {
        $title     =& $matches[3];
        $classname =& $matches[2];
        $attrs     =& $matches[4];
        $codeblock = $matches[5];

        if ($this->code_block_content_func) {
            $codeblock = call_user_func($this->code_block_content_func, $codeblock, $classname);
        } else {
            $codeblock = htmlspecialchars($codeblock, ENT_NOQUOTES);
        }

        $codeblock = preg_replace_callback('/^\n+/',
        array($this, '_doFencedCodeBlocks_newlines'), $codeblock);

        $classes = array();
        if ($classname != "") {
            if ($classname{0} == '.') {
                $classname = substr($classname, 1);
            }
            $classes[] = $this->code_class_prefix . $classname;
        }
        $attr_str = $this->doExtraAttributes($this->code_attr_on_pre ? "pre" : "code", $attrs, null, $classes);
        $pre_attr_str  = $this->code_attr_on_pre ? $attr_str : '';
        $code_attr_str = $this->code_attr_on_pre ? '' : $attr_str;

        if (!empty($title)) {
            $codeblock  =
                "<figure>\n".
                "<figcaption>$title</figcaption>\n".
                "<pre$pre_attr_str>\n".
                "<code$code_attr_str>$codeblock</code></pre>\n".
                "</figure>";
        } else {
            $codeblock="<pre$pre_attr_str><code$code_attr_str>$codeblock</code></pre>";
        }

        return "\n\n".$this->hashBlock($codeblock)."\n\n";
    }

    /**
     * Form HTML tables.
     * @param  string $text
     * @return string
     */
    protected function doTables($text)
    {
        $less_than_tab = $this->tab_width - 1;
        // Find tables with leading pipe.
        //
        //  | Header 1 | Header 2 | "title"
        //  | -------- | --------
        //  | Cell 1   | Cell 2
        //  | Cell 3   | Cell 4
        $text = preg_replace_callback('
            {
                ^                               # Start of a line
                [ ]{0,' . $less_than_tab . '}   # Allowed whitespace.
                [|]                             # Optional leading pipe (present)
                (.+?)                           # $1: Header row (at least one pipe)
                (?:[|]?[ ]*\"([^\"]+?)\")?      # $2: title
                [ ]*\n
                [ ]{0,' . $less_than_tab . '}   # Allowed whitespace.
                [|] ([ ]*[-:]+[-| :]*) \n       # $3: Header underline

                (                               # $4: Cells
                    (?>
                        [ ]*                    # Allowed whitespace.
                        [|] .* \n               # Row content.
                    )*
                )
                (?=\n|\Z)                       # Stop at final double newline.
            }xm',
            array($this, '_doTable_leadingPipe_callback'), $text);

        // Find tables without leading pipe.
        //
        //  Header 1 | Header 2
        //  -------- | --------
        //  Cell 1   | Cell 2
        //  Cell 3   | Cell 4
        $text = preg_replace_callback('
            {
                ^                               # Start of a line
                [ ]{0,' . $less_than_tab . '}   # Allowed whitespace.
                (\S.*[|].*?)                    # $1: Header row (at least one pipe)
                (?:[ ]*\"([^\"]+?)\")?          # $2: title
                [ ]*\n

                [ ]{0,' . $less_than_tab . '}   # Allowed whitespace.
                ([-:]+[ ]*[|][-| :]*) \n        # $2: Header underline

                (                               # $3: Cells
                    (?>
                        .* [|] .* \n            # Row content
                    )*
                )
                (?=\n|\Z)                       # Stop at final double newline.
            }xm',
            array($this, '_DoTable_callback'), $text);

        return $text;
    }

    /**
     * Callback for removing the leading pipe for each row
     * @param  array $matches
     * @return string
     */
    protected function _doTable_leadingPipe_callback($matches)
    {
        $head        = $matches[1];
        $title        = $matches[2];
        $underline    = $matches[3];
        $content    = $matches[4];

        $content    = preg_replace('/^ *[|]/m', '', $content);

        return $this->_doTable_callback(array($matches[0], $head, $title, $underline, $content));
    }

    /**
     * Make the align attribute in a table
     * @param  string $alignname
     * @return string
     */
    protected function _doTable_makeAlignAttr($alignname)
    {
        if (empty($this->table_align_class_tmpl)) {
            return " align=\"$alignname\"";
        }

        $classname = str_replace('%%', $alignname, $this->table_align_class_tmpl);
        return " class=\"$classname\"";
    }

    /**
     * Calback for processing tables
     * @param  array $matches
     * @return string
     */
    protected function _doTable_callback($matches)
    {
        $head        = $matches[1];
        $title        = $matches[2];
        $underline    = $matches[3];
        $content    = $matches[4];

        // Remove any tailing pipes for each line.
        $head        = preg_replace('/[|] *$/m', '', $head);
        $underline    = preg_replace('/[|] *$/m', '', $underline);
        $content    = preg_replace('/[|] *$/m', '', $content);

        // Reading alignement from header underline.
        $separators    = preg_split('/ *[|] */', $underline);
        foreach ($separators as $n => $s) {
            if (preg_match('/^ *-+: *$/', $s)) {
                $attr[$n] = $this->_doTable_makeAlignAttr('right');
            } elseif (preg_match('/^ *:-+: *$/', $s)) {
                $attr[$n] = $this->_doTable_makeAlignAttr('center');
            } elseif (preg_match('/^ *:-+ *$/', $s)) {
                $attr[$n] = $this->_doTable_makeAlignAttr('left');
            } else {
                $attr[$n] = '';
            }
        }

        // Parsing span elements, including code spans, character escapes,
        // and inline HTML tags, so that pipes inside those gets ignored.
        $head        = $this->parseSpan($head);
        $headers    = preg_split('/ *[|] */', $head);
        $col_count    = count($headers);
        $attr       = array_pad($attr, $col_count, '');

        // Write column headers.
        $text = "<table>\n";
        if (!empty($title)) {
            $text .= "<caption>$title</caption>\n";
        }
        $text .= "<thead>\n";
        $text .= "<tr>\n";
        foreach ($headers as $n => $header) {
            $text .= "  <th$attr[$n]>" . $this->runSpanGamut(trim($header)) . "</th>\n";
        }
        $text .= "</tr>\n";
        $text .= "</thead>\n";

        // Split content by row.
        $rows = explode("\n", trim($content, "\n"));

        $text .= "<tbody>\n";
        foreach ($rows as $row) {
            // Parsing span elements, including code spans, character escapes,
            // and inline HTML tags, so that pipes inside those gets ignored.
            $row = $this->parseSpan($row);

            // Split row by cell.
            $row_cells = preg_split('/ *[|] */', $row, $col_count);
            $row_cells = array_pad($row_cells, $col_count, '');

            $text .= "<tr>\n";
            foreach ($row_cells as $n => $cell) {
                $text .= "  <td$attr[$n]>" . $this->runSpanGamut(trim($cell)) . "</td>\n";
            }
            $text .= "</tr>\n";
        }
        $text .= "</tbody>\n";
        $text .= "</table>";

        return $this->hashBlock($text) . "\n";
    }

    /**
     * Take the string $str and parse it into tokens, hashing embeded HTML,
     * escaped characters and handling del spans.
     * @param  string $str
     * @return string
     */
    protected function parseDeleteSpan($str) {
        $output = '';

        $span_re = '{
                (
                    \\\\'.$this->escape_chars_re.'
                |
                    (?<![\\\\~])
                    ~+                      # del span marker
                )
                }xs';

        while (1) {
            // Each loop iteration seach for either the next tag, the next
            // openning del span marker, or the next escaped character.
            // Each token is then passed to handleDeleteSpanToken.
            $parts = preg_split($span_re, $str, 2, PREG_SPLIT_DELIM_CAPTURE);
            // Create token from text preceding tag.
            if ($parts[0] != "") {
                $output .= $parts[0];
            }

            // Check if we reach the end.
            if (isset($parts[1])) {
                $output .= $this->handleDeleteSpanToken($parts[1], $parts[2]);
                $str = $parts[2];
            } else {
                break;
            }
        }

        return $output;
    }

    /**
     * Take the string $str and parse it into tokens, hashing embeded HTML,
     * escaped characters and handling code(key) spans.
     * @param  string $str
     * @return string
     */
    protected function parseCodeKeySpan($str) {
        $output = '';

        $span_re = '{
                (
                    \\\\'.$this->escape_chars_re.'
                |
                    (?<![\\\\~])
                    ```+                # code (key) span marker
                )
                }xs';

        while (1) {
            // Each loop iteration seach for either the next tag, the next
            // openning code(key) span marker, or the next escaped character.
            // Each token is then passed to handleCodeKeySpanToken.
            $parts = preg_split($span_re, $str, 2, PREG_SPLIT_DELIM_CAPTURE);
            // Create token from text preceding tag.
            if ($parts[0] != "") {
                $output .= $parts[0];
            }

            // Check if we reach the end.
            if (isset($parts[1])) {
                $output .= $this->handleCodeKeySpanToken($parts[1], $parts[2]);
                $str = $parts[2];
            } else {
                break;
            }
        }
        return $output;
    }


    protected function handleDeleteSpanToken($token, &$str) {
        switch ($token{0}) {
            case "\\":
                return $this->hashPart("&#". ord($token{1}). ";");
            case "~":
                // Search for end marker in remaining text.
                if (preg_match('/^(.*?[^~])'.preg_quote($token).'(?!~)(.*)$/sm',
                    $str, $matches))
                {
                    $str = $matches[2];
                    $delspan = $this->makeDelSpan($matches[1]);
                    return $this->hashPart($delspan);
                }
                return $token; // Return as text since no ending marker found.
            default:
                return $this->hashPart($token);
        }
    }

    protected function handleCodeKeySpanToken($token, &$str) {
        switch ($token{0}) {
            case "\\":
                return $this->hashPart("&#". ord($token{1}). ";");
            case "`":
                // Search for end marker in remaining text.
                if (preg_match('/^(.*?[^`])'.preg_quote($token).'(?!`)(.*)$/sm',
                    $str, $matches))
                {
                    $str = $matches[2];
                    $delspan = $this->makeCodeKeySpan($matches[1]);
                    return $this->hashPart($delspan);
                }
                return $token; // Return as text since no ending marker found.
            default:
                return $this->hashPart($token);
        }
    }

    /**
     * Create a del span markup for $delspan. Called from handleDeleteSpanToken.
     * @param  string $code
     * @return string
     */
    protected function makeDelSpan($delspan) {
        $delspan = htmlspecialchars(trim($delspan), ENT_NOQUOTES);
        return $this->hashPart("<del>$delspan</del>");
    }

    /**
     * Create a code span markup for $code. Called from makeCodeKeySpan.
     * @param  string $code
     * @return string
     */
    protected function makeCodeKeySpan($code) {
        if ($this->code_span_content_func) {
            $code = call_user_func($this->code_span_content_func, $code);
        } else {
            $code = htmlspecialchars(trim($code), ENT_NOQUOTES);
        }
        return $this->hashPart("<code class=\"key\">$code</code>");
    }


}
