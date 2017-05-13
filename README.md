Cloud Storage Server /feeds Extension
=====================================

A powerful and flexible cross-platform /feeds extension for the [self-hosted cloud storage API](https://github.com/cubiclesoft/cloud-storage-server) for notifying and monitoring of changes to data feeds, including future tracking.  Includes a PHP SDK for interacting with the /feeds API.

The /feeds extension is useful for triggering notifications of current and upcoming database/information changes.  Powerful filtering options allow for only sending notifications that monitoring applications are interested in.  The use-cases are endless for achieving near real-time application data responsiveness even inside environments with obnoxious data caches.

Features
--------

* Cross-platform support for all major platforms, including Windows.
* Send insert, update, and delete notifications just like a real database.
* Timestamp each notification to schedule it for the future, now, or in the past.
* An optional future pre-loader can make sure future data is delivered in a system resource-conscious fashion.
* RESTful notification and live WebSocket monitoring support.
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Installation
------------

Extract and copy the Cloud Storage Server files as you would for a server.  Copy the `/server_exts/feeds.php` file to the `/server_exts` directory.  Now install the Cloud Storage Server.

You may find the cross-platform [Service Manager](https://github.com/cubiclesoft/service-manager/) tool to be useful to enable Cloud Storage Server to function as a system service.

Be sure to create a user using Cloud Storage Server `manage.php` and add the /feeds extension to the user account.

Next, you'll need to initialize the user account's /feeds extension.  To do this, use the PHP SDK to run a script like:

````php
<?php
	require_once "sdk/support/sdk_cloud_storage_server_feeds.php";

	$css = new CloudStorageServerFeeds();
	$css->SetAccessInfo("http://127.0.0.1:9893", "YOUR_API_KEY", "", "");

	$data = array(
		"author" => "Someone",
		"subject" => "A test",
		"body" => "This is just a test."
	);

	$result = $css->Notify("blog", "insert", "1", $data, time() + 5);
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}
?>
````

The above will send a notification to the feed with the name of "blog" five seconds in the future.  If there were any monitors watching, they would receive the notification at that time.  At this point, the installation is complete.

External Scripts
----------------

Locate the Cloud Storage Server storage directory as specified by the configuration.  Within it are user ID directories.  Within a user ID directory is a set of other directories associated with each enabled and used extension.  Find the newly set up user account and the /feeds directory.  Within the /feeds directory is a file called 'exectab.txt'.  The 'exectab.txt' file is very similar to crontab except it only executes associated scripts when there is something to process for a given notification type.

Example 'exectab.txt' file:

````
# Run a PHP script as a specific user and group.
-u=someuser -g=somegroup test /usr/bin/php /var/scripts/myscript.php

# Run a PHP script as the same user/group as the Cloud Storage Server process (probably root) with a starting directory of /var/log/apache2.
-d=/var/log/apache2 test2 /usr/bin/php /var/scripts/myscript2.php

# Runs a PHP script as the same user/group as the Cloud Storage Server process (probably root) but only if the incoming data matches a filter.
-f='{"path": "/keywords", "cmp": "value", "values": ["cubiclesoft"]}' test3 /usr/bin/php /var/scripts/myscript3.php
````

The above defines several script names:  `test`, `test2`, and `test3`.  Each one does something different.  The format for script execution lines is:

`[options] feedname [executable [params]]`

The full list of options is:

* -d=startdir - The starting directory for the target process.
* -e=envvar - An environment variable to set for the target process.
* -f=filter - A JSON encoded string containing a filter (see Monitoring for filter options).
* -g=group - The *NIX group to run the process under (*NIX only).
* -u=user - The *NIX user to run the process under (*NIX only).

Executables are run such that their last argument is a JSON encoded string containing the data to process.

Monitoring
----------

Sending notifications that do nothing is not all that useful.  Let's say you have a blog post publishing in the future and you also want to watch database insertions that include a keyword of your company name.  Both feeds can be monitored from a single script that makes a WebSocket connection to Cloud Storage Server.

Example monitoring script:

````php
<?php
	require_once "sdk/support/sdk_cloud_storage_server_feeds.php";

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	$css = new CloudStorageServerFeeds();

	@mkdir($rootpath . "/cache", 0775);
	$result = $css->InitSSLCache("https://remoteserver.com:9892", $rootpath . "/cache/css_ca.pem", $rootpath . "/cache/css_cert.pem");
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	$css->SetAccessInfo("https://remoteserver.com:9892", "YOUR_API_KEY", $rootpath . "/cache/css_ca.pem", file_get_contents($rootpath . "/cache/css_cert.pem"));

	$result = $css->InitMonitor();
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	$ws = $result["ws"];
	$api_sequence_map = array();
	$feeds = array(
		"My Blog" => array(
			"name" => "blog",
			"filters" => array(
			)
		),
		"The Firehose" => array(
			"name" => "firehose",
			"filters" => array(
				array("path" => "/keywords", "cmp" => "value", "values" => array("cubiclesoft"))
			)
		)
	);

	// Start monitoring feeds.
	$num = 1;
	foreach ($feeds as $title => $info)
	{
		$result = $css->AddMonitor($ws, $num, $info["name"], $filters);
		if (!$result["success"])
		{
			var_dump($result);
			exit();
		}

		$api_sequence_map[$num] = $title;

		$num++;
	}

	// Main loop.
	$result = $ws->Wait();
	while ($result["success"])
	{
		do
		{
			$result = $ws->Read();
			if (!$result["success"])  break;

			if ($result["data"] !== false)
			{
				$data = json_decode($result["data"]["payload"], true);

				var_dump($data);

				if (isset($data["success"]))
				{
					if (!$data["success"] || !$data["enabled"])
					{
						// Do something here...
					}
				}
				else
				{
					// Do something here...
				}
			}
		} while ($result["data"] !== false);

		$result = $ws->Wait();
	}

	// An error occurred.
	var_dump($result);
?>
````

Each filter is an array with the following format:

* path - A string containing the path to follow to get to the element to compare or determine if it exists - the first character determines the path separator for the rest of the string
* not - A boolean indicating the the filter outcome should be negated (Default is false)
* cmp - A boolean of false or a string containing one of 'key', 'value', or 'both' (Default is false)
* serialize - A boolean that indicates whether or not to JSON encode arrays/objects before comparing values (Default is false)
* mode - A string containing one of '==', '===', 'regex', '<', '<=', '>', or '>=' (Default is '==')
* values - A single value or an array of values with types as determined by the 'mode' (Default is an empty array)

When 'cmp' is false, only the existence of the 'path' is checked.  Values are checked during setup of the API sequence for validity.  Bad filters are the most common cause of not receiving data.

There are various ways to get the monitoring script to start.  If you want to start it at system boot and keep it going should the script terminate at some point, the cross-platform [Service Manager](https://github.com/cubiclesoft/service-manager/) tool is useful.

Pre-Fill Future Notifications
-----------------------------

When Cloud Storage Server first starts up, /feeds has no knowledge about future notifications that may take place.  In addition, there could be thousands or even millions of future notifications.  Putting all of that data in RAM will slow things down considerably.  When a monitor first registers itself at /feeds/v1/monitor with a new 'name' (e.g. blogs), the /feeds extension looks for a PHP file in the 'user_init/feeds' directory of the main server that matches a file system safe version of the name.  It loads up the file, instantiates a class named 'CSS_Extension_feeds__feedname' (where 'future_[feedname].php' is the name of the file), and runs the class' Init() method.

Once the class is initialized, the AddItems() method will be called at first and then periodically called later on with an array of timestamps that map to arrays of information that will be sent at those times.  The array may be modified.

The SentItems() method can be used to unblock internal functionality so that a later AddItems() call can add more future notification items.

Example class (named 'blogs.php'):

````php
<?php
	class CSS_Extension_feeds__blogs
	{
		private $db, $lastts, $canadd;

		public function Init()
		{
			require_once "/path/to/db.php";

			$this->db = new DB("username", "password");
			$this->lastts = time();
			$this->canadd = true;
		}

		public function AddItems(&$items)
		{
			if ($this->canadd)
			{
				$prevts = $this->lastts;

				$nextpub = $this->db->GetOne("SELECT MIN(published) FROM myblog WHERE published > ?", array(date("Y-m-d H:i:s", $prevts)));
				if ($nextpub)
				{
					$this->lastts = strtotime($nextpub);

					if (!isset($items[$this->lastts]))  $items[$this->lastts] = array();

					$result = $this->db->Query("SELECT * FROM myblog WHERE published = ?", array($nextpub));
					while ($row = $result->NextRow())
					{
						$items[$this->lastts][$row->id] = array("type" => "insert", "data" => (array)$row);
					}
				}

				$nextremove = $this->db->GetOne("SELECT MIN(removeafter) FROM myblog WHERE removeafter > ?", array(date("Y-m-d H:i:s", $prevts)));
				if ($nextremove)
				{
					$ts = strtotime($nextremove);
					if ($ts <= $this->lastts)
					{
						$this->lastts = $ts;

						if (!isset($items[$this->lastts]))  $items[$this->lastts] = array();

						$result = $this->db->Query("SELECT * FROM myblog WHERE removeafter = ?", array($nextremove));
						while ($row = $result->NextRow())
						{
							$items[$this->lastts][$row->id] = array("type" => "delete", "data" => (array)$row);
						}
					}
				}

				$this->canadd = false;
			}
		}

		public function SentItems(&$items, $ts)
		{
			if ($ts >= $this->lastts || !isset($items[$this->lastts]))
			{
				$this->lastts = $ts;
				$this->canadd = true;
			}
		}
	}
?>
````

The example class above connects to a database and populates the next notification item(s) to be sent in the future.  It uses a few class instance variables to avoid making unnecessary database queries.  If it runs out of future items, it disables itself so it won't make additional SQL queries until something happens again.  The use of unique IDs prevents overlap with incoming notifications containing the same data.  The implementation here avoids missing notifications but also doesn't require all future notifications to be loaded at once.  The result is a very efficient and elegant solution to a common problem that most developers have traditionally shrugged their shoulders at and just run live SQL queries in an infinite loop or, more recently, used no-SQL database products, which come with their own caveats.

Extension:  /feeds
------------------

The /feeds extension implements the /feeds/v1 API.  To try to keep this page relatively short, here is the list of available APIs, the input request method, and successful return values (always JSON output):

POST /feeds/v1/notify

* name - Feed name
* type - A string containing one of 'insert', 'update', or 'delete'
* id - A string containing a unique identifier (Tip:  For complex unique IDs, encode the string as JSON)
* data - Additional data to send, preferably an array
* queue - Optional UNIX timestamp (integer) to specify when to start the process
* queuesize - Optional integer to specify the maximum future queue size to keep around
* Returns:  success (boolean)

GET /feeds/v1/monitor (WebSocket only)

* name - Feed name
* filters - An array of filters
* Returns:  success (boolean), name (string), enabled (boolean)

GET /feeds/v1/guest/list

* Returns: success (boolean), guests (array)

POST /feeds/v1/guest/create

* name - Script name
* notify - Guest can send notifications
* monitor - Guest can monitor feeds
* expires - Unix timestamp (integer)
* Returns: success (boolean), id (string), info (array)
* Summary: The 'info' array contains: apikey, created, expires, info (various)

POST /feeds/v1/guest/delete/ID

* ID - Guest ID
* Returns: success (boolean)
