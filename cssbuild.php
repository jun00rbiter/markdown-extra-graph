<?php
require "vendor/leafo/lessphp/lessc.inc.php";
$less = new lessc;
unlink("css/main.css");
echo $less->checkedCompile("resource/less/main.less", "css/main.css");