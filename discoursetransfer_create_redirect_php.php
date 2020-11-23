#!/usr/bin/php
<?php

// adapt this URL to your main Discourse forum URL
$default_redirect_url = "https://forum.bome.com/";

/*
 read topics_redirect_map.csv 
 output a PHP file that serves as redirection to the new Discourse forum.

 Usage:
 discoursetransfer_create_redirect_php.php  <input.csv> <output.php>
  <input.csv>  : the topics_redirect_map.csv created by DiscourseTransfer with  parameter -tr
  <output.php> : the php file to install on your web server to manage all redirects.
   
 Installation:
 If the old forum was at /support/kb
 add this mod_rewrite to apache:
 
	<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteBase /
	# rewrite rule for old Q&A forum redirects
	RewriteRule ^support/kb(.*)$  /cma_forum_redirects.php [L]
	</IfModule>

 Then copy the output of this tool to /cma_forum_redirects.php
 
*/ 

function usage()
{
	echo "Usage:\n";
	echo "discoursetransfer_create_redirect_php.php  <input.csv> <output.php>\n";
	echo "  <input.csv>  : the topics_redirect_map.csv created by DiscourseTransfer with  parameter -tr\n";
	echo "  <output.php> : the php file to install on your web server to manage all redirects.\n";
	exit(1);
}


// ------------------ MAIN -----------------
if (!isset($argv[1]) || !isset($argv[2]))
{
	usage();
}

$filename = $argv[1];
$output_file = $argv[2];

echo "Reading $filename...\n";
	
if (($infile = fopen($filename, "r")) === FALSE)
{
	echo "ERROR: cannot open file: $filename\n";
	exit(1);
}

$outfile = fopen($output_file, 'w');
if ($outfile === null || $outfile === false)
{
	echo "ERROR: cannot open output file: $output_file\n";
	exit(1);
}

fwrite($outfile, "<?php\n");
fwrite($outfile, "\$default_url = \"$default_redirect_url\";\n\n");
fwrite($outfile, "\$url = \$_SERVER[\"REQUEST_URI\"];\n");
fwrite($outfile, "// remove query string\n");
fwrite($outfile, "\$qm = strpos(\$url, \"?\");\n");
fwrite($outfile, "if (\$qm !== false) { \$url = substr(\$url, 0, \$qm); }\n\n");
fwrite($outfile, "// only keep slug\n");
fwrite($outfile, "\$slugindex = strrpos(\$url, \"/\");\n");
fwrite($outfile, "if (\$slugindex !== false) { \$url = substr(\$url, \$slugindex + 1); }\n\n");

fwrite($outfile, "\$redirects = array(\n");

// skip header
fgets($infile);

$current_line = 2;
$written = 0;
while (($data = fgets($infile)) !== FALSE)
{
	$elements = explode(";", $data);
	if (count($elements) != 2) {
		echo "ERROR: line $current_line: not 2 elements.\n";
	} else {
		$orig = trim($elements[0]);
		$redir = trim($elements[1]);
		if (empty($orig) || empty($redir)) {
			echo "ERROR: line $current_line: corrupt.\n";
		} else {
			fwrite($outfile, " \"$orig\" => \"$redir\",\n");
			$written++;
		}			
	}
	
	$current_line++;
}
fwrite($outfile, ");\n\n");
fwrite($outfile, "if (isset(\$redirects[\$url])) {\n");
fwrite($outfile, "	header(\"Location: {\$redirects[\$url]}\", true, 301/*permanent*/);\n");
fwrite($outfile, "	exit();\n");
fwrite($outfile, "}\n\n");
fwrite($outfile, "// Otherwise, go to default\n");
fwrite($outfile, "header(\"Location: \$default_url\", true, 301/*permanent*/);\n");
fwrite($outfile, "exit();\n");

fclose($outfile);
fclose($infile);

echo "Written $written redirects OK\n";
