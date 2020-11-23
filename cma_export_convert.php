#!/usr/bin/php
<?php

$debug = false;

// adapt this default category to your needs
const FIXED_CATEGORY_ID = 5;
const FIXED_CATEGORY_NAME = "Bome Products";
const FIXED_CATEGORY_DESCRIPTION = "Discussion about all Bome products.";

/*
A conversion tool from CM Answers to Discourse.


INPUT:
--------------------------
CM Answers Wordpress plugin:
    https://www.cminds.com/cm-answer-store-page-content/
  with Answers Import Export Add-On:
    https://www.cminds.com/wordpress-plugins-library/cm-answers-import-and-export-add-on-for-wordpress/

to create a cma-export-[datestamp].csv file.

Run this tool on the exported CMA data:
php cma_export_convert.php cma-export-20201121160241.csv


OUTPUT:
--------------------------
A set of .csv files:
  users.csv
  categories.csv [not implemented, will just create one category]
  topics.csv     [from CMA threads]
  posts.csv      [from CMS answers and comments]
  topicviews.csv [to be fed into separate converter UpdateViewCount]


IMPORT INTO DISCOURSE
--------------------------
clone DiscourseTransfer into current directory:
git clone https://github.com/bome/discourse-transfer.git

Follow the Readme.md of DiscourseTransfer:
https://github.com/bome/discourse-transfer

Here are my commands I used:
(cd discourse-transfer/ ; mvn install dependency:copy-dependencies)
(cd discourse-transfer/ ; mvn package)

API_KEY=fcdadc....  # <--- the API key created in Discourse|Settings|API
API_USER=florian
DISCOURSE_URL=https://forum.bome.com
DT_PARAMS="-u $API_USER -a "$API_KEY" -w "$DISCOURSE_URL"
./DiscourseTransfer.sh $DT_PARAMS -d -uf users.csv -um users_map.csv  2>>log_create_users.txt
# if there is an error, you can safely fix users.csv and/or this conversion tool and re-run the command
./DiscourseTransfer.sh $DT_PARAMS -d -cf categories.csv -cm categories_map.csv 2>>log_create_categories.txt
# if there is an error, you can safely fix categories.csv and/or this conversion tool and re-run the command
./DiscourseTransfer.sh $DT_PARAMS -d -tf topics.csv -tm topics_map.csv -cm categories_map.csv -tr topics_redirect_map.csv 2>>log_create_topics.txt
./DiscourseTransfer.sh $DT_PARAMS -d -pf posts.csv -pm posts_map.csv -tm topics_map.csv 2>>log_create_posts.txt

If an import failed due to wrong username, do this:
cat topics.csv | grep timnuzzi >> topics_additional.csv
Then replace tiberiominuzzi with tim.nuzzi (or whatever username is already registered in Discourse).

If importing posts fails with code 403, it is likely, again, that the user does not exist or is not activated.
Go into Discourse Admin, Users, look up user. If not activated, activate the user manually.


IMPORT VIEWCOUNT
---------------------------------------------
Create view count .sql statements:
./UpdateViewCount.sh

enter Discourse docker container, go into postgresql, and execute the SQL statements to update the view count:
./launcher enter app
su - postgres
\c discourse
# show tables
<now copy/paste the UPDATE statements>


CREATE REDIRECT SCRIPT
---------------------------------------------
Using the created topics_redirect_map.csv, you can easily create a web server redirect list,
or, what I chose, a PHP file which looks up the matching redirect.
To create the PHP file, edit discoursetransfer_create_redirect_php.php and adapt to your needs,
then run:
php discoursetransfer_create_redirect_php.php topics_redirect_map.csv cma_forum_redirect.php

Look at discoursetransfer_create_redirect_php.php for more information how to use it with Apache.


BEHIND THE SCENES
-------------------------------------
The output format of this script is matching the input of the modified DiscourseTransfer:
    https://github.com/bome/discourse-transfer/

 The .csv files use ; as separator.

 Categories are not fully supported by this tool.
 Attachments are incorporated into the text, pointing to the original location.
 Tags are not supported by DiscourseTransfer.
 
 categories.csv:
 id ; name ; description ; timestamp ; color ; text_color
 
 users.csv:
 userid ; username ; name ; email [; password [; hash]]

 topics.csv:
 topicid ; forumid ; username ;  post_time ; topic_title ; post_text ; url
 
 posts.csv:
 postid ; topicid ; forumid ; username ; post_time ; post_text ; reply_to_topic_id ; reply_to_post_id
 
 topicviews.csv: [for UpdateViewCount.java]
 topicid ; views
 

 forumid: really the category id

 topic_title and post_text are escaped:
 ;  -> [SEMICOLON]
 \n -> [NEWLINE]
 "  -> [DOUBLEQUOTE]
 \r -> <nothing>
 

NOTE:
for CMA Answers Export  Import Addon 1.2.0, the following patch is required:

File model/QuestionMeta.php:40
	static function export($fp, $withHeader = true) {
		global $wpdb;
		
		$fields = array_map(function($field) { return "m.$field"; }, self::$fields);
		
		$page = 1;
		while ($records = static::exportGetRows($page, $fields)) {
			
			if ($withHeader AND $page == 1) {
				fputcsv($fp, static::getExportHeader());
			}
			
			foreach ($records as &$record) {
				if (in_array($record['meta_key'], self::$meta)) {
					$record = array_merge(array('type' => 'cma_thread_meta'), $record);
					// Florian Bomers 2020-11-17: fix attachment link (not ID)
					if (self::$meta['attachment'] == $record['meta_key']) {
						$record['meta_value'] = wp_get_attachment_url($record['meta_value']);
						if (empty($record['meta_value'])) {
							$record = null;
						}
					}
				} else {
					$record = null;
				}
				if ($record != null) {
					fputcsv($fp, $record);
				}
			}
			
			$page++;
			
		}
		
		fputcsv($fp, array());
		
	}



CMA exported format (all in one file):
_____________________________________________________________
cma_thread:
Type,ID,"Post title","Post content","Post author","Post date","Post modified","Post status","Post name","Display name","User email","User login",Categories,Tags
cma_thread,13325,"This is topic 13325 title","Line one
Line 2.",1198,"2017-01-17 21:56:08","2017-01-31 02:24:07",publish,original-slug-of-this-topic,Nice Name,user@example.com,username,[],[]

_____________________________________________________________
cma_answer:
Type,"Comment ID","Comment post ID","Comment author","Comment author email","Comment author IP","Comment date","Comment content","Comment approved","Comment agent","Comment parent","User id","Display name","User email","User login"

cma_answer,5128,13325,"Other User",other@example.com,188.17.10.133,"2017-01-31 16:51:14","<p>just an image</p>",1,,0,2,"Other User",other@example.com,other_user

_____________________________________________________________
cma_comment:
Type,"Comment ID","Comment post ID","Comment author","Comment author email","Comment author IP","Comment date","Comment content","Comment approved","Comment agent","Comment parent","User id","Display name","User email","User login"

example for thread/topic comment:
cma_comment,5088,13325,"Other User",other@example.com,188.17.16.9,"2017-01-31 01:51:48","<p>A comment to topic 13325.</p>",1,,0,2,"Other User",other@example.com,other_user
example for answer/post comment:
cma_comment,5119,13325,"Other User",other@example.com,18.14.10.13,"2017-01-31 13:43:02","<p>A comment to post 5128.<br /></p>",1,,5128,2,"Other User",other@example.com,other_user

_____________________________________________________________
cma_thread_meta:

Type,           "Meta id","Post id","Meta key","Meta value"
cma_thread_meta,115893,   13325,    _views,     209
cma_thread_meta,115936,   13325,    _attachment,https://example.com/images/attachment_topic.jpg

_____________________________________________________________
cma_answer_meta:
Type | Meta id | Meta key | Meta value | Comment id

NOTE: "Comment id" should really be "Answer ID"
can all be ignored except if Meta key == "CMA_answer_attachment":
in that case, "Meta value" is the URL of the attachment.
cma_answer_meta,1473,CMA_answer_attachment,https://example.com/images/attachment_post.jpg,5128

*/

const DISCOURSE_MIN_USERNAME_LENGTH = 3;
const DISCOURSE_MIN_TOPIC_TITLE_LENGTH = 10;


// array of thread arrays. The index is the ID
$cma_thread = array();
const thread_index_title=0;
const thread_index_content=1;
const thread_index_date=2;
const thread_index_userid=3;
const thread_index_email=4;
const thread_index_username=5;
const thread_index_nickname=6;
const thread_index_categories=7;
const thread_index_tags=8;
const thread_index_slug=9;
const thread_index_views=10; // from cma_thread_meta
const thread_index_comments=11; //array of cma_comment, "Comment post ID" is thread ID, "Comment parent" is 0.
const thread_index_answers=12;  //array of cma_answer

//cma_answer / cma_comment:
const answer_index_date = 0;
const answer_index_content = 1;
const answer_index_userid = 2;
const answer_index_email = 3;
const answer_index_username = 4;
const answer_index_nickname = 5;
const answer_index_comments = 6; //array of cma_comment, "Comment post ID" is thread ID. if "Comment parent" is cma_answer ID

$current_line = 0;

// statistics
$errors = 0;
$answer_count = 0;
$comment_count = 0;
$attachment_count = 0;

// map answer ID to thread ID
$answer_to_thread = array();


function debug($text)
{
	global $debug;
	if ($debug) echo $text . "\n";
}


// read the given file and parse it into global cma_thread array.
function parse($filename)
{
	echo "Reading $filename...\n";
	
	global $current_line;
	if (($infile = fopen($filename, "r")) !== FALSE)
	{
		$current_line = 1;
		while (($data_in_csv = fgetcsv($infile)) !== FALSE)
		{
			if ($data_in_csv != null && count($data_in_csv) > 2)
			{
				//echo "line $current_line: {count($data_in_csv)} elements.\n";
				if ($data_in_csv[0] == "cma_thread")
				{
					parse_cma_thread($data_in_csv);
				}
				else if ($data_in_csv[0] == "cma_answer")
				{
					parse_cma_answer($data_in_csv, false/*is comment*/);
				}
				else if ($data_in_csv[0] == "cma_comment")
				{
					parse_cma_answer($data_in_csv, true/*is comment*/);
				}
				else if ($data_in_csv[0] == "cma_thread_meta")
				{
					parse_cma_thread_meta($data_in_csv);
				}
				else if ($data_in_csv[0] == "cma_answer_meta")
				{
					parse_cma_answer_meta($data_in_csv);
				}
				else if ($data_in_csv[0] != "Type")
				{
					$line = trim(implode($data_in_csv, ","));
					if (!empty($line))
					{
						echo "Ignoring line $current_line: $line\n";
					}
				}
			}
			else if (count($data_in_csv) > 0)
			{
				$line = trim(implode($data_in_csv, ","));
				if (!empty($line))
				{
					echo "Ignoring line $current_line: $line\n";
				}
			}
			$current_line++;
		}
		fclose($infile);
	}
	else
	{
		echo "ERROR: cannot open file: $filename\n";
		return false;
	}
	return true;
}

$unpublished_thread_ids = array();

function parse_cma_thread($data)
{
	//Type,ID,"Post title","Post content","Post author","Post date","Post modified","Post status","Post name","Display name","User email","User login",Categories,Tags
	// 0    1   2           3              4             5            6               7              8             9           10            11          12        13
	if (count($data) != 14)
	{
		global $current_line;
		echo "ERROR cma_thread line $current_line: has " . count($data) . " elements instead of 14\n";
		global $errors; $errors++;
		return;
	}
	
	$id = $data[1];

	// ignore non-published posts
	if ($data[7] != "publish")
	{
		global $unpublished_thread_ids;
		$unpublished_thread_ids[] = $id;
		debug("thread $id: unpublished");
		return;
	}

	debug("thread $id");
	
	$t = array();
	$t[] = $data[2];  // title
	$t[] = $data[3];  // content
	$t[] = $data[5];  // date
	$t[] = $data[4];  // user ID
	$t[] = $data[10]; // user email
	$t[] = $data[11]; // username
	$t[] = $data[9];  // user nickname
	
	$cats = $data[12];
	if (!empty($cats))
	{
		$cats = json_decode($cats);
		if (count($cats) > 0)
		{
			$cats = implode(",", $cats);
			debug("- categories: $cats");
		}
		else
		{
			$cats = "";
		}
	}
	$t[] = $cats;

	$tags = $data[13];
	if (!empty($tags))
	{
		$tags = json_decode($tags);
		if (count($tags) > 0)
		{
			$tags = implode(",", $tags);
			debug("- tags: $tags");
		}
		else
		{
			$tags = "";
		}
	}
	$t[] = $tags;
	
	$t[] = $data[8]; // slug

	$t[] = 0; //views, handled by meta data
	$t[] = array(); //comments
	$t[] = array(); // answers
	
	global $cma_thread;
	$cma_thread[$id] = $t;

}

$unpublished_answer_ids = array();


function parse_cma_answer($data, $is_comment)
{
	//Type,"Comment ID","Comment post ID","Comment author","Comment author email","Comment author IP","Comment date","Comment content","Comment approved",
	// 0       1               2              3 (name)           4                        5                  6               7                 8                   
	// "Comment agent","Comment parent","User id","Display name","User email","User login"
	//        9             10             11      12 (nickname)      13       14 (username)
	$name = $is_comment ? "comment" : "answer";
	if (count($data) != 15)
	{
		global $current_line;
		echo "ERROR cma_$name line $current_line: has ${count($data)} elements instead of 15\n";
		global $errors; $errors++;
		return;
	}
	$id = $data[1];
	$thread_id = $data[2];
	$answer_id = $data[10]; // for comments
	
	$approved = $data[8];
	if ($approved != 1)
	{
		debug("- ignored cma_$name $id  status=$approved");
		if (!$is_comment)
		{
			global $unpublished_answer_ids;
			$unpublished_answer_ids[] = $id;
		}
		return;
	}
	
	$a = array();
	$a[] = $data[6]; // date
	$a[] = $data[7]; // content
	$a[] = $data[11];// user id
	$a[] = $data[13];// user email
	$a[] = $data[14];// username/login
	$a[] = $data[12];// nickname
	$a[] = null;     // comments

	debug("$name $id (thread $thread_id answer $answer_id)");
	
	if (!$is_comment)
	{
		$a[answer_index_comments] = array();
	}
	
	// sometimes, the user data at end (current data) does not exist
	$email = $a[answer_index_email];
	if (empty($email))
	{
		$email = $data[4];
		if (empty($email))
		{
			$email = "deleted_user@example.com";
			echo "$name $id: no email given, set to '$email'.\n";
			echo "   (user_id={$data[11]} author='{$data[3]}'  nickname='{$data[12]}'  username='{$data[14]}')\n";
		}
		$a[answer_index_email] = $email;
	}
	$username = $a[answer_index_username];
	if (empty($username))
	{
		$username = $data[3];
		if (empty($username))
		{
			$username = "deleted_user";
			echo "$name $id: no username given, set to '$username'.\n";
			echo "    (user_id={$data[11]}  email='$email'  nickname='{$data[12]}')\n";
		}
		$a[answer_index_username] = $username;
	}
	$nickname = $a[answer_index_nickname];
	if (empty($nickname))
	{
		$nickname = $data[3];
		if (empty($nickname))
		{
			$nickname = "Deleted User";
			echo "$name $id: no nickname given, set to '$nickname'.\n";
			echo "    (user_id={$data[11]} email='$email'  username='$username')\n";
		}
		$a[answer_index_nickname] = $nickname;
	}

	//echo "$name $id: date='{$a[answer_index_date]}'  userID={$a[answer_index_userid]} email='{$a[answer_index_email]}'  username='{$a[answer_index_username]}'  name='{$a[answer_index_nickname]}'\n";
	//echo "  dump: " . implode($data, ",") . "\n";

	
	global $cma_thread;
	if (!isset($cma_thread[$thread_id]))
	{
		global $current_line;
		global $unpublished_thread_ids;
		if (in_array($answer_id, $unpublished_thread_ids))
		{
			echo "WARNING line $current_line: cma_$name $id is ignored, because parent thread $thread_id is unpublished\n";
			return;
		}
		echo "ERROR cma_$name line $current_line: parent thread $thread_id does not exist.\n";
		global $errors; $errors++;
		return;
	}

	$t = &$cma_thread[$thread_id];
	if ($is_comment)
	{
		if ($answer_id > 0)
		{
			// comment for an answer
			if (!isset($t[thread_index_answers]) || !isset($t[thread_index_answers][$answer_id]))
			{
				global $current_line;
				global $unpublished_answer_ids;
				if (in_array($answer_id, $unpublished_answer_ids))
				{
					echo "WARNING line $current_line: cma_$name $id is ignored, because parent answer $answer_id (in thread $thread_id) is unpublished\n";
					return;
				}
				else
				{
					echo "ERROR line $current_line: cma_$name $id in thread $thread_id does not have a parent answer $answer_id\n";
					global $errors; $errors++;
					return;
				}
			}

			$answer = &$t[thread_index_answers][$answer_id];
			if (!isset($answer[answer_index_comments]))
			{
				$answer[answer_index_comments] = array();
			}
			debug("- cma_thread[$thread_id][thread_index_answers][$answer_id][answer_index_comments][$id]=comment $id");
			$answer[answer_index_comments][$id] = $a;
		}
		else
		{
			// comment for a thread
			//debug("- cma_thread[$thread_id][thread_index_comments][$id]=comment $id");
			$t[thread_index_comments][$id] = $a;
		}
		global $comment_count; $comment_count++;
	}
	else
	{
		// answer
		debug("- cma_thread[$thread_id][thread_index_answers][$id]=answer $id");
		$t[thread_index_answers][$id] = $a;
		debug("- answer_to_thread[$id] = $thread_id");
		global $answer_to_thread;
		$answer_to_thread[$id] = $thread_id;
		global $answer_count; $answer_count++;
	}
}


function parse_cma_thread_meta($data)
{
	// Type,"Meta id","Post id","Meta key","Meta value"
	//   0    1          2         3          4
	if (count($data) != 5)
	{
		global $current_line;
		echo "ERROR cma_thread_meta line $current_line: has ${count($data)} elements instead of 5\n";
		global $errors; $errors++;
		return;
	}
	$thread_id = $data[2];
	$key = $data[3];
	$value = $data[4];

	if ($key != "_views" && $key != "_attachment")
	{
		// optimization
		return;
	}
	//debug("thread meta: thread $thread_id: $key => $value");

	global $cma_thread;
	if (!isset($cma_thread[$thread_id]))
	{
		global $current_line;
		echo "ERROR cma_thread_meta line $current_line: thread $thread_id does not exist.\n";
		global $errors; $errors++;
		return;
	}
	$t = &$cma_thread[$thread_id];
	if ($key == "_views")
	{
		$t[thread_index_views] = $value;
	}
	else if ($key == "_attachment")
	{
		handle_thread_attachment($thread_id, $value);
	}
	// other interesting meta fields:
	//_resolved = {0,1}
	//_highest_rated_answer = {0,1}
	//_sticky_post = {0,1}
	//_best_answer_id = ID
	//_marked_as_spam = {0,1}
}


function parse_cma_answer_meta($data)
{
	// Type | Meta id | Meta key | Meta value | Answer id
	//   0      1          2          3            4
	if (count($data) != 5)
	{
		global $current_line;
		echo "ERROR cma_answer_meta line $current_line: has ${count($data)} elements instead of 5\n";
		global $errors; $errors++;
		return;
	}
	$answer_id = $data[4];
	$key = $data[2];
	$value = $data[3];

	if ($key != "CMA_answer_attachment")
	{
		// optimization
		return;
	}

	//debug("answer meta: answer $answer_id: $key => $value");

	global $answer_to_thread;
	if (!isset($answer_to_thread[$answer_id]))
	{
		global $current_line;
		echo "ERROR cma_answer_meta line $current_line: answer $answer_id does not exist.\n";
		global $errors; $errors++;
		return;
	}
	$thread_id = $answer_to_thread[$answer_id];

	global $cma_thread;
	if (!isset($cma_thread[$thread_id]))
	{
		global $current_line;
		echo "ERROR cma_answer_meta line $current_line: thread $thread_id does not exist.\n";
		global $errors; $errors++;
		return;
	}
	$t = &$cma_thread[$thread_id];

	if ($key == "CMA_answer_attachment")
	{
		handle_answer_attachment($thread_id, $answer_id, $value);
	}
}


function handle_thread_attachment($thread_id, $url)
{
	debug("Thread $thread_id: attachment: $url");
	
	// find thread content and add the attachment
	global $cma_thread;
	if (!isset($cma_thread[$thread_id]))
	{
		global $current_line;
		echo "ERROR handle_thread_attachment line $current_line: thread $thread_id does not exist.\n";
		global $errors; $errors++;
		return;
	}
	$t = &$cma_thread[$thread_id];
	$t[thread_index_content] = append_attachment($t[thread_index_content], $url);
}


function handle_answer_attachment($thread_id, $answer_id, $url)
{
	debug("Thread $thread_id answer $answer_id: attachment: $url");

	// find answer content and add the attachment
	global $cma_thread;
	if (!isset($cma_thread[$thread_id]))
	{
		global $current_line;
		echo "ERROR handle_thread_attachment line $current_line: thread $thread_id does not exist.\n";
		global $errors; $errors++;
		return;
	}
	$t = &$cma_thread[$thread_id];

	if (!isset($t[thread_index_answers]) || !isset($t[thread_index_answers][$answer_id]))
	{
		global $current_line;
		echo "ERROR cma_$name line $current_line: thread $thread_id does not have an answer $answer_id\n";
		global $errors; $errors++;
		return;
	}
	$answer = &$t[thread_index_answers][$answer_id];
	
	$answer[answer_index_content] = append_attachment($answer[answer_index_content], $url);
}


// appends a <a href=""> or <img...> tag to the content
function append_attachment($content, $url)
{
	global $attachment_count; $attachment_count++;
	
	// first add header
	// note: must use additional line feeds to import correctly into Discourse.
	$header = "<br/>\n<b>Attachments:</b><br/>\n\n";
	if (strpos($content, $header) === false)
	{
		$content .= $header;
	}
	
	$link_text = "";
	
	if (is_image($url))
	{
		$link_text = "<img src=\"$url\" border=\"0\"/>";
	}
	else
	{
		if (get_extension($url) == "" 
			|| ((strpos($url, "http") === false) && (strpos($url, "/") === false)))
		{
			global $current_line;
			echo "WARNING line $current_line: attachment URL looks bad: $url\n";
		}
		$link_text = "<b>" . extract_filename($url) . "</b>";
	}
	return $content . "<a href=\"$url\">$link_text</a>\n\n";
}


function is_image($url)
{
	// with input from https://stackoverflow.com/questions/676949/best-way-to-determine-if-a-url-is-an-image-in-php
	$imgExts = array("gif", "jpg", "jpeg", "png", "tiff", "tif");
	$extension = get_extension($url);
	return in_array($extension, $imgExts);
}

function get_extension($url)
{
	return strtolower(trim(substr(strrchr($url, "."), 1)));
}


function extract_filename($url)
{
	return trim(substr(strrchr($url, "/"), 1));
}


// ----------------------------- jforum output -------------------------------------

// an associative array with user email as key
$jforum_users = array();

// start assigning user ID's starting with this number
$jforum_unknwown_user_id = 100000; // assign entirely fake user IDs

// first write all posts to this array so that they are written to .csv in chronological order
// Discourse sorts posts by order of adding, not by post date.
$jforum_posts = array();

function output_jforum_files()
{
	echo "Creating output files:\n";
	output_jforum_categories("categories.csv");
	output_jforum_users("users.csv");
	output_jforum_topics("topics.csv");
	create_jforum_post_list();
	output_jforum_posts("posts.csv");
	output_jforum_topicviews("topicviews.csv");
}


function output_jforum_categories($filename)
{
	global $errors;
	if ($errors > 0) return false;
	
	$header = array("id", "name", "description", "timestamp", "color", "text_color");
	$fixed_category = array(FIXED_CATEGORY_ID, FIXED_CATEGORY_NAME, FIXED_CATEGORY_DESCRIPTION, "2020-11-18 10:00:00", "000000", "FFFFFF");
	
	$fp = jforum_start_csv($filename, $header);
	if ($fp == null)
	{
		return false;
	}
	jforum_write_line($fp, $fixed_category);
	jforum_close_csv($fp);
	return true;
}


function output_jforum_users($filename)
{
	global $errors;
	if ($errors > 0) return false;
	
	$header = array("userid", "username", "name", "email");
	
	$fp = jforum_start_csv($filename, $header);
	if ($fp == null)
	{
		return false;
	}
	
	// iterate through all threads, answers, and comments to find all users
	global $cma_thread;
	foreach ($cma_thread as $thread_id => $t)
	{
		$ret = jforum_add_user($t[thread_index_userid], $t[thread_index_username], $t[thread_index_email], $t[thread_index_nickname]);
		if (!$ret)
		{
			echo "   (thread $thread_id)\n";
		}
		
		foreach ($t[thread_index_comments] as $comment_id => $c)
		{
			jforum_add_user_from_post($comment_id, $c, true/*is_comment*/);
		}
		foreach ($t[thread_index_answers] as $answer_id => $a)
		{
			jforum_add_user_from_post($answer_id, $a, false/*is_comment*/);
			$comments = &$a[answer_index_comments];
			if ($comments != null)
			{
				foreach ($comments as $comment_id => $c)
				{
					jforum_add_user_from_post($comment_id, $c, true/*is_comment*/);
				}
			}
		}
	}
	
	// dump users
	global $jforum_users;
	foreach ($jforum_users as $user)
	{
		jforum_write_line($fp, $user);
	}

	debug("  - written " . count($jforum_users) . " users.");
	
	jforum_close_csv($fp);
	return true;
}


// add a user from a comment or answer
function jforum_add_user_from_post($post_id, $post, $is_comment)
{
	$ret = jforum_add_user($post[answer_index_userid], $post[answer_index_username], $post[answer_index_email], $post[answer_index_nickname]);
	if (!$ret)
	{
		if ($is_comment)
		{
			echo "   (comment $post_id)\n";
		}
		else
		{
			echo "   (answer $post_id)\n";
		}
	}
	return $ret;
}


// userid ; username ; name ; email
//    0       1         2       3
function jforum_add_user($userid, $username, $email, $name)
{
	global $jforum_users;
	if (empty($username) || empty($email))
	{
		echo "ERROR: cannot add user: username='$username'  email='$email'  name='$name'\n";
		global $errors ; $errors++;
		return false;
	}
	
	if (!isset($jforum_users[$email]))
	{
		if (empty($name))
		{
			$name = $username;
		}
		
		$clean_username = jforum_clean_username($username);
		if ($clean_username != $username)
		{
			echo "  WARNING: user $userid: had to clean username: '$username' --> '$clean_username'\n";
			$username = $clean_username;
		}

		if ($userid == 0)
		{
			global $jforum_unknwown_user_id;
			$userid = $jforum_unknwown_user_id;
			echo "  WARNING: user $email does not have a user ID. Assigning $userid\n";
			$jforum_unknwown_user_id++;
		}
		
		$user = array($userid, $username, jforum_clean_field($name), $email);
		$jforum_users[$email] = $user;
		//debug("-add user ". implode($user, ","));
		
	}
	return true;
}

function jforum_clean_username($username)
{
	if (!empty($username))
	{
		while (strlen($username) < DISCOURSE_MIN_USERNAME_LENGTH - 1)
		{
			$username = $username . "-";
		}
		if (strlen($username) < DISCOURSE_MIN_USERNAME_LENGTH)
		{
			$username = $username . "1";
		}
		// replace illegal chars
		$username = str_replace(array("_", "*", "@", "!", " ", "."), array("-", "x", "-", "-", "-", "-"), $username);
		$last_char = substr($username, -1);
		if (!ctype_alnum($last_char))
		{
			$username .= "1";
		}
	}
	return $username;
}

function jforum_clean_topictitle($title)
{
	if (!empty($title))
	{
		while (strlen($title) < DISCOURSE_MIN_TOPIC_TITLE_LENGTH)
		{
			$title = $title . " -";
		}
	}
	return $title;
}

function jforum_clean_field($field)
{
	return str_replace(array(";", "\""), array(" ", "'"), $field);
}


function jforum_escape_field($field)
{
	return str_replace(array(";", "\n", '"', "\r"), array("[SEMICOLON]", "[NEWLINE]", "[DOUBLEQUOTE]", ""), $field);
}


// topics.csv:
// topicid ; forumid ; username ;  post_time ; topic_title ; post_text ; url
//    0         1         2           3           4             5         6
function output_jforum_topics($filename)
{
	global $errors;
	if ($errors > 0) return;
	
	$header = array("topicid", "forumid", "username", "post_time", "topic_title", "post_text", "url");
	
	$fp = jforum_start_csv($filename, $header);
	if ($fp == null)
	{
		return;
	}
	
	// sort topics by topic_id
	global $cma_thread;
	ksort($cma_thread, SORT_NUMERIC);

	// iterate through all threads and dump them
	foreach ($cma_thread as $id => $t)
	{
		$email = $t[thread_index_email];
		$username = jforum_get_username_from_email($email);
		$post_time = $t[thread_index_date];
		$title = jforum_escape_field(jforum_clean_topictitle($t[thread_index_title]));
		$text = jforum_escape_field($t[thread_index_content]);
		$url = jforum_escape_field($t[thread_index_slug]);
		$topic = array($id, FIXED_CATEGORY_ID, $username, $post_time, $title, $text, $url);
		
		jforum_write_line($fp, $topic);		
	}
	
	jforum_close_csv($fp);
}


function output_jforum_posts($filename)
{
	global $errors;
	if ($errors > 0) return;
	$header = array("postid", "topicid", "forumid", "username", "post_time", "post_text", "reply_to_topic_id", "reply_to_post_id");
	$fp = jforum_start_csv($filename, $header);
	if ($fp == null)
	{
		return;
	}
	
	// output all posts in order of post_id
	global $jforum_posts;
	ksort($jforum_posts, SORT_NUMERIC);
	foreach ($jforum_posts as $p)
	{
		jforum_write_line($fp, $p);
	}
	
	jforum_close_csv($fp);
}

function create_jforum_post_list()
{
	global $errors;
	if ($errors > 0) return;	

	// iterate through all threads, from there iterate all answers and comments and add them to $jforum_posts
	global $cma_thread;
	foreach ($cma_thread as $thread_id => $t)
	{
		// thread comments
		foreach ($t[thread_index_comments] as $comment_id => $c)
		{
			jforum_create_one_post($thread_id, $comment_id, $c, true/*is_comment*/, $thread_id, 0/*$replyToPostId*/);
		}
		foreach ($t[thread_index_answers] as $answer_id => $a)
		{
			// answers
			jforum_create_one_post($thread_id, $answer_id, $a, false/*is_comment*/, 0/*$replyToTopicId*/, 0/*$replyToPostId*/);
			
			$comments = &$a[answer_index_comments];
			if ($comments != null)
			{
				// answer comments
				foreach ($comments as $comment_id => $c)
				{
					jforum_create_one_post($thread_id, $comment_id, $c, true/*is_comment*/, 0/*$replyToTopicId*/, $answer_id);
				}
			}
		}
	}
}


// posts.csv:
// postid ; topicid ; forumid ; username ; post_time ; post_text ; reply_to_topic_id ; reply_to_post_id
//    0        1         2         3          4           5               6                 7
//                                                               (use 0 if not reply)  (use 0 if not reply)
function jforum_create_one_post($thread_id, $post_id, $post, $is_comment, $replyToTopicId, $replyToPostId)
{
	// make sure that the post ID's are unique
	global $jforum_posts;
	if (isset($jforum_posts[$post_id]))
	{
		echo "ERROR: topic ID already exists: thread_id=$thread_id  post_id=$post_id\n";
		global $errors ; $errors++;
		return;
	}

	$email = $post[answer_index_email];
	$username = jforum_get_username_from_email($email);
	$post_time = $post[answer_index_date];
	$text = jforum_escape_field($post[answer_index_content]);
	
	$jforum_posts[$post_id] = array($post_id, $thread_id, FIXED_CATEGORY_ID, $username, $post_time, $text, $replyToTopicId, $replyToPostId);
}


function jforum_get_username_from_email($email)
{
	global $jforum_users;
	if (empty($email))
	{
		echo "ERROR: cannot retrieve user from email: '$email'\n";
		global $errors ; $errors++;
		return false;
	}
	if (!isset($jforum_users[$email]))
	{
		echo "ERROR: user not found: email: '$email'\n";
		global $errors ; $errors++;
		return false;
	}
	$user = &$jforum_users[$email];
	$username = $user[1];
	if (empty($username))
	{
		echo "ERROR: username is empty: email: '$email'\n";
		global $errors ; $errors++;
		return false;
	}
	return $username;
}


// topicviews.csv: [for UpdateViewCount.java]
// topicid ; views
function output_jforum_topicviews($filename)
{
	global $errors;
	if ($errors > 0) return;
	
	$header = array("topicid", "views");
	
	$fp = jforum_start_csv($filename, $header);
	if ($fp == null)
	{
		return;
	}

	// iterate through all threads and dump the views item
	global $cma_thread;
	foreach ($cma_thread as $id => $t)
	{
		$views = $t[thread_index_views];
		if ($views > 0)
		{
			$view = array($id, $views);
			jforum_write_line($fp, $view);
		}
	}
	
	jforum_close_csv($fp);
}


function jforum_start_csv($filename, $header)
{
	echo "- $filename...\n";
	$fp = fopen($filename, 'w');
	if ($fp === null || $fp === false)
	{
		echo "- ERROR: cannot open file.\n";
		global $errors ; $errors++;
		return null;
	}
	jforum_write_line($fp, $header);
	return $fp;
}


function jforum_write_line($fp, $fields)
{
	// cannot use fputcsv(): it requires an "enclosure" character, and uses it for fields with spaces.
	//$ok = (fputcsv($fp, $fields, ";"/*delimiter*/, "\""/*enclosure*/, ""/*escape_char*/) !== false);
	$line = implode($fields, ";") . "\n";
	if (strpos($line, "\"") !== false)
	{
		echo "ERROR: writing corrupt line: one of the fields has a quote char: $line\n";
		global $errors ; $errors++;
	}
	$ok = (fwrite($fp, $line) !== false);
	if (!$ok)
	{
		echo "ERROR: cannot write CSV line: " . implode($fields, ";") . "\n";
		global $errors ; $errors++;
	}
}


function jforum_close_csv($fp)
{
	if ($fp != null)
	{
		fclose($fp);
	}
}


function usage()
{
	echo "Usage:\n";
	echo "cma_export_convert.php <filename>\n";
	
	echo "\nCreates these files in jforum format in the current dir:\n";
	echo "  users.csv\n";
	echo "  categories.csv [not implemented, will just create one category]\n";
	echo "  topics.csv     [from CMA threads]\n";
	echo "  posts.csv      [from CMS answers and comments]\n";
	echo "  topicviews.csv [to be fed into separate converter UpdateViewCount.java]\n";
	
	exit(1);
}


// ------------------ MAIN -----------------
if (!isset($argv[1]))
{
	usage();
}
parse($argv[1]);

//print_r($cma_thread);

$thread_count = count($cma_thread);
echo "\nParse results:\n";
echo "  $thread_count threads\n";
echo "  $answer_count answers\n";
echo "  $comment_count comments\n";
echo "  $attachment_count attachments\n";

if ($errors > 0)
{
	echo "Encountered $errors errors.\n";
}
else
{
	echo "OK.\n";
}
	
if ($errors == 0)
{
	echo "\n";
	
	output_jforum_files();
	
	if ($errors > 0)
	{
		echo "Encountered $errors errors.\n";
	}
	else
	{
		echo "OK.\n";
	}
}
