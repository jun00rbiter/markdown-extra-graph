<?php
namespace jun00rbiter\MarkdownGraph;

/**
 * MarkdownGraph  -  PHP Markdown Extra に graphviz のグラフ記述シンタックスを追加
 *
 * @package   markdown-extra-graph
 * @author    jun00rbiter <jun00rbiter@gmail.com>
 * @copyright 2017- jun00rbiter <https://michelf.com/projects/php-markdown/>
 * @copyright (Original PHP Markdown Extra) Michel Fortin <https://michelf.com/projects/php-markdown/>
 * @copyright (Original Markdown) John Gruber <https://daringfireball.net/projects/markdown/>
 */

use Michelf\MarkdownExtra;

class MarkdownGraph extends MarkdownExtra {

	public $noChapter = 0;
	public $noSection = 0;
	public $noParagraph = 0;

	public $dot_store_directory = __DIR__ . '/../../tmp';
	public $svg_store_directory = __DIR__ . '/../../tmp';
	public $url_prefix = "/tmp";

	/**
	 * コンストラクタ
	 * @return void
	 */
	public function __construct() {
		$this->block_gamut += array(
			"doGraphvizBlocks"	=> 100,
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
	protected function doGraphvizBlocks($text) {
	
		$less_than_tab = $this->tab_width;
		
		$text = preg_replace_callback('{
				(?:\n|\A)
				# 1: Opening marker
				(
					(?:@{3,}) # 3 or more tildes/backticks.
				)
				[ ]*
				(?:
					\.?([-_:a-zA-Z0-9]+) # 2: standalone class name
				)?
				[ ]*
				(?:
					' . $this->id_class_attr_catch_re . ' # 3: Extra attributes
				)?
				[ ]*
				(?:
					\[?(.+)\] # 4: graph context
				)?
				[ ]* \n # Whitespace and newline following marker.
				
				# 5: Content
				(
					(?>
						(?!\1 [ ]* \n)	# Not a closing marker.
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
	protected function _doGraphvizBlocks_callback($matches) {
		$classname =& $matches[2];
		$attrs     =& $matches[3];
		$title 	   =& trim($matches[4]);
		$codeblock = $matches[5];

        $out = [];
        $filebase = md5($codeblock);
        file_put_contents($this->dot_store_directory."/{$filebase}.dot",$codeblock);
        exec(
			"dot -Tsvg -o " . $this->svg_store_directory . "/{$filebase}.svg "
			. $this->dot_store_directory . "/{$filebase}.dot", $out);

		$classes = array();
		if ($classname != "") {
			if ($classname{0} == '.')
				$classname = substr($classname, 1);
			$classes[] = $this->code_class_prefix . $classname;
		}
		$attr_str = $this->doExtraAttributes('img', $attrs, null, $classes);

		// $codeblock  = "<img$attr_str src=\"graph/{$filebase}.svg\" />";
		$codeblock =
			"<figure>\n" .
			"  <object$attr_str type=\"image/svg+xml\" data=\"{$this->url_prefix}/{$filebase}.svg\"></object>\n" .
			"  <figcaption>$title</figcaption>\n" .
			"</figure>";
		return "\n\n".$this->hashBlock($codeblock)."\n\n";
	}
}