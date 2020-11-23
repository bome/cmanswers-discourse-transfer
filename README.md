# cmanswers-discourse-transfer
Scripts to migrate a CM Answers forum to Discourse.

# Features
* migrate users
* migrate categories (not fully implemented)
* migrate topics
* migrate posts
* migrate comments to topics and comments to posts
* migrate attachments (leaving them at the old download location)
* optionally migrate user passwords
* will work with Single Sign On
* create redirects for old site to point to new Discourse URLs

# Tools needed

CM Answers Wordpress plugin:
    https://www.cminds.com/cm-answer-store-page-content/

Install Answers Import Export Add-On:
    https://www.cminds.com/wordpress-plugins-library/cm-answers-import-and-export-add-on-for-wordpress/

DiscourseTransfer Java tool:
    https://github.com/bome/discourse-transfer

# Steps
* Export forum using the Import Export plugin
* use `cma_export_convert.php` to split and process
* use `DiscourseTransfer.sh` java tool to import users, categories, topics, posts
* optionally, use `UpdateViewCount.sh` to migrate topic view count to Discourse
* optionally, create redirect .php file using `discoursetransfer_create_redirect_php.php`

See the individual php files for instructions.
