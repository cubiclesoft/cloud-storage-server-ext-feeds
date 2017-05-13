<?php
	// Cloud Storage Server feeds extension.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	class CSS_Extension_feeds
	{
		private $futureitems, $futurefillers, $monitors, $baseenv, $exectabs, $exectabsts, $usercache, $groupcache;

		public function Install()
		{
			global $rootpath;

			@mkdir($rootpath . "/user_init/feeds", 0770, true);
		}

		public function AddUserExtension($userrow)
		{
			echo "[Feeds Ext] Allow guest creation/deletion (Y/N):  ";
			$guests = (substr(strtoupper(trim(fgets(STDIN))), 0, 1) == "Y");

			return array("success" => true, "info" => array("guests" => $guests));
		}

		public function RegisterHandlers($em)
		{
		}

		public function InitServer()
		{
			global $db;

			$this->futureitems = array();
			$this->futurefillers = array();
			$this->monitors = array();

			$ignore = array(
				"PHP_SELF" => true,
				"SCRIPT_NAME" => true,
				"SCRIPT_FILENAME" => true,
				"PATH_TRANSLATED" => true,
				"DOCUMENT_ROOT" => true,
				"REQUEST_TIME_FLOAT" => true,
				"REQUEST_TIME" => true,
				"argv" => true,
				"argc" => true,
			);

			$this->baseenv = array();
			foreach ($_SERVER as $key => $val)
			{
				if (!isset($ignore[$key]) && is_string($val))  $this->baseenv[$key] = $val;
			}

			$this->exectabs = array();
			$this->exectabsts = array();
			$this->usercache = array();
			$this->groupcache = array();

			// Walk all users and preload future fill items for any user's exectab.
			$result = $db->Query("SELECT", array(
				"*",
				"FROM" => "?",
				"WHERE" => "serverexts LIKE '%feeds%'",
			), "users");

			while ($userrow = $result->NextRow())
			{
				$userrow->serverexts = @json_decode($userrow->serverexts, true);

				if (isset($userrow->serverexts["feeds"]))
				{
					$userrow->basepath = str_replace("\\", "/", $userrow->basepath);
					while (substr($userrow->basepath, -1) === "/")  $userrow->basepath = substr($userrow->basepath, 0, -1);

					$basedir = self::InitUserFeedsBasePath($userrow);

					$this->InitExecTabs($basedir, $userrow->id);
				}
			}
		}

		private function GetUserInfoByName($name)
		{
			if (!function_exists("posix_getpwnam"))  return false;

			if (!isset($this->usercache["_" . $name]))
			{
				$user = @posix_getpwnam($name);
				if ($user === false || !is_array($user))  $this->usercache["_" . $name] = false;
				else
				{
					$this->usercache[$user["uid"]] = $user;
					$this->usercache["_" . $name] = $user;
				}
			}

			return $this->usercache["_" . $name];
		}

		private function GetGroupInfoByName($name)
		{
			if (!function_exists("posix_getgrnam"))  return false;

			if (!isset($this->groupcache["_" . $name]))
			{
				$group = @posix_getgrnam($name);
				if ($group === false || !is_array($group))  $this->groupcache["_" . $name] = "";
				else
				{
					$this->groupcache[$group["gid"]] = $group;
					$this->groupcache["_" . $name] = $group;
				}
			}

			return $this->groupcache["_" . $name];
		}

		private function FutureFillItems($uid, $name)
		{
			if (!isset($this->futurefillers[$name]) || $this->futurefillers[$name] === false)  return;

			if (!isset($this->futureitems[$uid]))  $this->futureitems[$uid] = array();
			if (!isset($this->futureitems[$uid][$name]))  $this->futureitems[$uid][$name] = array();
			$this->futurefillers[$name]->AddItems($this->futureitems[$uid][$name]);
			ksort($this->futureitems[$uid][$name]);

			if (!count($this->futureitems[$uid][$name]))
			{
				unset($this->futureitems[$uid][$name]);

				if (!count($this->futureitems[$uid]))  unset($this->futureitems[$uid]);
			}
		}

		private function InitFutureFiller($name)
		{
			global $rootpath;

			if (!isset($this->futurefillers[$name]))
			{
				// Load optional custom handler to prefill future notification items.
				$this->futurefillers[$name] = false;
				$name2 = preg_replace('/\s+/', "_", preg_replace('/[^A-Za-z0-9_\-]/', " ", $name));
				$filename = $rootpath . "/user_init/feeds/future_" . $name2 . ".php";
				if (file_exists($filename))
				{
					require_once $filename;

					$classname = "CSS_Extension_feeds__" . $name2;
					if (class_exists($classname))
					{
						$this->futurefillers[$name] = new $classname;
						$this->futurefillers[$name]->Init();
					}
				}
			}
		}

		private function NormalizeFilters($filters)
		{
			$result = array();
			$filters = array_values($filters);
			foreach ($filters as $num => $filter)
			{
				if (!is_array($filter))  return array("success" => false, "error" => "Filter " . $num . " is not an array.", "errorcode" => "invalid_filter");
				if (!isset($filter["path"]))  return array("success" => false, "error" => "Filter " . $num . " is missing 'path'.", "errorcode" => "missing_filter_path");
				if (!is_string($filter["path"]) || $filter["path"] === "")  return array("success" => false, "error" => "Filter " . $num . " 'path' is invalid.", "errorcode" => "invalid_filter_path");

				$parts = explode(substr($filter["path"], 0, 1), $filter["path"]);
				$filter["path"] = array();
				foreach ($parts as $part)
				{
					if ($part !== "")  $filter["path"][] = $part;
				}

				if (!isset($filter["not"]))  $filter["not"] = false;
				if (!is_bool($filter["not"]))  return array("success" => false, "error" => "Filter " . $num . " 'not' is invalid.  Expected boolean.", "errorcode" => "invalid_filter_not");
				if (!isset($filter["cmp"]))  $filter["cmp"] = false;
				if ($filter["cmp"] !== false && $filter["cmp"] !== "key" && $filter["cmp"] !== "value" && $filter["cmp"] !== "both")  return array("success" => false, "error" => "Filter " . $num . " 'cmp' is invalid.  Expected false, 'key', 'value', or 'both'.", "errorcode" => "invalid_filter_cmp");
				if (!isset($filter["serialize"]))  $filter["serialize"] = false;
				if (!is_bool($filter["serialize"]))  return array("success" => false, "error" => "Filter " . $num . " 'serialize' is invalid.  Expected boolean.", "errorcode" => "invalid_filter_serialize");
				if (!isset($filter["mode"]))  $filter["mode"] = "==";
				if ($filter["mode"] !== "==" && $filter["mode"] !== "===" && $filter["mode"] !== "regex" && $filter["mode"] !== "<" && $filter["mode"] !== "<=" && $filter["mode"] !== ">" && $filter["mode"] !== ">=")  return array("success" => false, "error" => "Filter " . $num . " 'mode' is invalid.  Expected '==', '===', 'regex', '<', '<=', '>', or '>='.", "errorcode" => "invalid_filter_mode");
				if (isset($filter["value"]))
				{
					$filter["values"] = array($filter["value"]);
					unset($filter["value"]);
				}
				if (!isset($filter["values"]))  $filter["values"] = array();
				if (!is_array($filter["values"]))  $filter["values"] = array($filter["values"]);

				if ($filter["mode"] === "regex")
				{
					foreach ($filter["values"] as $num2 => $value)
					{
						if (!is_string($value) || @preg_match($value, NULL) === false)  return array("success" => false, "error" => "Filter " . $num . " 'values' " . $num2 . " is an invalid regular expression.", "errorcode" => "invalid_filter_values_regex");
					}
				}
				else if ($filter["mode"] === "==" || $filter["mode"] === "===")
				{
					foreach ($filter["values"] as $num2 => $value)
					{
						if (is_array($value))  return array("success" => false, "error" => "Filter " . $num . " 'values' " . $num2 . " is an array.  Primitive data types are required for mode '" . $filter["mode"] . "'.", "errorcode" => "invalid_filter_values_array");
						else if (is_object($value))  return array("success" => false, "error" => "Filter " . $num . " 'values' " . $num2 . " is an object.  Primitive data types are required for mode '" . $filter["mode"] . "'.", "errorcode" => "invalid_filter_values_object");
					}
				}
				else if ($filter["mode"] === "<" || $filter["mode"] === "<=" || $filter["mode"] === ">" || $filter["mode"] === ">=")
				{
					foreach ($filter["values"] as $num2 => $value)
					{
						if (!is_numeric($value))  return array("success" => false, "error" => "Filter " . $num . " 'values' " . $num2 . " is not numeric.  Required for mode '" . $filter["mode"] . "'.", "errorcode" => "invalid_filter_values_numeric");
					}
				}

				$result[] = $filter;
			}

			return array("success" => true, "filters" => $result);
		}

		private function IsFilterMatch($filters, $data)
		{
			// Process filters.
			$match = true;
			foreach ($filters as $filter)
			{
				// Locate path element.
				$found = true;
				$curr = &$data;
				foreach ($filter["path"] as $key)
				{
					if (!isset($curr[$key]))
					{
						$found = false;

						break;
					}

					$curr = &$curr[$key];
				}

				// If the path wasn't found, it may still be a match depending on 'cmp' and 'not' values.
				if (!$found && ($filter["cmp"] !== false || !$filter["not"]))
				{
					$match = false;

					break;
				}

				$found = false;
				if ($filter["cmp"] !== false)
				{
					$currval = $curr;

					if (!is_array($currval))  $currval = array($currval);
					foreach ($currval as $key => $val)
					{
						foreach ($filter["values"] as $cmpval)
						{
							if ($filter["cmp"] === "key" || $filter["cmp"] === "both")
							{
								$val2 = $key;

								switch ($filter["mode"])
								{
									case "==":  if (!is_array($val2) && !is_object($val2) && $val2 == $cmpval)  $found = true;  break;
									case "===":  if (!is_array($val2) && !is_object($val2) && $val2 === $cmpval)  $found = true;  break;
									case "regex":  if (is_string($val2) && preg_match($cmpval, $val2))  $found = true;  break;
									case "<":  if (is_numeric($val2) && $val2 < $cmpval)  $found = true;  break;
									case "<=":  if (is_numeric($val2) && $val2 <= $cmpval)  $found = true;  break;
									case ">":  if (is_numeric($val2) && $val2 > $cmpval)  $found = true;  break;
									case ">=":  if (is_numeric($val2) && $val2 >= $cmpval)  $found = true;  break;
								}
							}

							if (!$found && ($filter["cmp"] === "value" || $filter["cmp"] === "both"))
							{
								$val2 = $val;
								if ($filter["serialize"] && (is_array($val2) || is_object($val2)))  $val2 = json_encode($val2);

								switch ($filter["mode"])
								{
									case "==":  if (!is_array($val2) && !is_object($val2) && $val2 == $cmpval)  $found = true;  break;
									case "===":  if (!is_array($val2) && !is_object($val2) && $val2 === $cmpval)  $found = true;  break;
									case "regex":  if (!is_array($val2) && !is_object($val2) && preg_match($cmpval, (string)$val2))  $found = true;  break;
									case "<":  if (is_numeric($val2) && $val2 < $cmpval)  $found = true;  break;
									case "<=":  if (is_numeric($val2) && $val2 <= $cmpval)  $found = true;  break;
									case ">":  if (is_numeric($val2) && $val2 > $cmpval)  $found = true;  break;
									case ">=":  if (is_numeric($val2) && $val2 >= $cmpval)  $found = true;  break;
								}
							}

							if ($found)  break;
						}

						if ($found)  break;
					}
				}

				if ($filter["not"])  $found = !$found;

				if (!$found)
				{
					$match = false;

					break;
				}
			}

			return $match;
		}

		private function HasMonitor($uid, $name)
		{
			return (isset($this->monitors[$uid]) && isset($this->monitors[$uid][$name]));
		}

		private function NotifyMonitors($uid, $name, $result)
		{
			global $wsserver;

			if (isset($this->monitors[$uid]) && isset($this->monitors[$uid][$name]))
			{
				foreach ($this->monitors[$uid][$name] as $wsid => $info)
				{
					$client = $wsserver->GetClient($wsid);
					if ($client === false)
					{
						unset($this->monitors[$uid][$name][$wsid]);
						if (!count($this->monitors[$uid][$name]))
						{
							unset($this->monitors[$uid][$name]);
							if (!count($this->monitors[$uid]))  unset($this->monitors[$uid]);
						}
					}
					else
					{
						foreach ($info as $api_sequence => $filters)
						{
							if ($this->IsFilterMatch($filters, $result["data"]))
							{
								$result["api_sequence"] = $api_sequence;

								$client->websocket->Write(json_encode($result), WebSocket::FRAMETYPE_TEXT);
							}
						}
					}
				}
			}
		}

		private function RunScripts($uid, $name, $result)
		{
			if (isset($this->exectabs[$uid]) && isset($this->exectabs[$uid][$name]))
			{
				foreach ($this->exectabs[$uid][$name] as $args)
				{
					if ($this->IsFilterMatch($args["opts"]["filter"], $result["data"]))
					{
						// Set up the process environment.
						$env = $this->baseenv;
						foreach ($args["opts"]["envvar"] as $var)
						{
							$pos = strpos($var, "=");
							if ($pos !== false)
							{
								$key = substr($var, 0, $pos);
								$val = (string)substr($var, $pos + 1);

								foreach ($env as $key2 => $val2)
								{
									if (!strcasecmp($key, $key2))  $key = $key2;

									$val = str_ireplace("%" . $key2 . "%", $val2, $val);
								}

								$env[$key] = $val;
							}
						}

						// Set effective user and group.
						if (function_exists("posix_geteuid"))
						{
							$prevuid = posix_geteuid();
							$prevgid = posix_getegid();

							if (isset($args["opts"]["user"]))
							{
								$userinfo = $this->GetUserInfoByName($args["opts"]["user"]);
								if ($userinfo !== false)
								{
									posix_seteuid($userinfo["uid"]);
									posix_setegid($userinfo["gid"]);
								}
							}

							if (isset($args["opts"]["group"]))
							{
								$groupinfo = $this->GetGroupInfoByName($args["opts"]["group"]);
								if ($groupinfo !== false)  posix_setegid($groupinfo["gid"]);
							}
						}

						$args["params"][] = escapeshellarg(json_encode($result));

						$cmd = implode(" ", $args["params"]);
//echo $cmd . "\n";

						// Start the process.
						$procpipes = array(array("pipe", "r"), array("pipe", "w"), array("pipe", "w"));
						$proc = @proc_open($cmd, $procpipes, $pipes, (isset($args["opts"]["dir"]) ? $args["opts"]["dir"] : NULL), $env, array("suppress_errors" => true, "bypass_shell" => true));

						// Restore effective user and group.
						if (function_exists("posix_geteuid"))
						{
							posix_seteuid($prevuid);
							posix_setegid($prevgid);
						}

						if (!is_resource($proc))  echo "Failed to start process:  " . $cmd . "\n";
						else
						{
							foreach ($pipes as $fp)  fclose($fp);

							proc_close($proc);
						}
					}
				}
			}
		}

		private function ProcessFutureItems()
		{
			$ts = time();
			$result = false;
			foreach ($this->futureitems as $uid => $names)
			{
				foreach ($names as $name => $tsinfo)
				{
					foreach ($tsinfo as $ts2 => $idsinfo)
					{
						if ($ts < $ts2)
						{
							if ($result === false || $result > $ts2)  $result = $ts2;

							break;
						}

						foreach ($idsinfo as $id => $info)
						{
							$info["id"] = (string)$id;
							$info["time"] = $ts2;

							$this->NotifyMonitors($uid, $name, $info);
							$this->RunScripts($uid, $name, $info);
						}

						if (isset($this->futurefillers[$name]))  $this->futurefillers[$name]->SentItems($this->futureitems[$uid][$name], $ts2);

						unset($this->futureitems[$uid][$name][$ts2]);
					}

					$this->FutureFillItems($uid, $name);
				}
			}

			return ($result !== false ? $result - $ts : false);
		}

		public function UpdateStreamsAndTimeout($prefix, &$timeout, &$readfps, &$writefps)
		{
			$diff = $this->ProcessFutureItems();

			if ($diff !== false && ($timeout === false || $timeout > $diff))  $timeout = $diff;
		}

		public function HTTPPreProcessAPI($pathparts, $client, $userrow, $guestrow)
		{
		}

		public static function InitUserFeedsBasePath($userrow)
		{
			$basedir = $userrow->basepath . "/" . $userrow->id . "/feeds";
			@mkdir($basedir, 0770, true);

			return $basedir;
		}

		private function InitExecTabs($basedir, $uid)
		{
			global $rootpath, $userhelper;

			// Copy staging file into directory.
			$filename = $basedir . "/exectab.txt";
			if (!file_exists($filename))
			{
				$bytesdiff = 0;
				if (!file_exists($rootpath . "/user_init/feeds/exectab.txt"))  $data2 = "";
				else  $data2 = file_get_contents($rootpath . "/user_init/feeds/exectab.txt");
				$bytesdiff = strlen($data2);
				file_put_contents($filename, $data2);

				// Adjust total bytes stored.
				$userhelper->AdjustUserTotalBytes($uid, $bytesdiff);
			}

			// Parse 'exectab.txt' if it has changed since the last API call.
			if (!isset($this->exectabsts[$uid]))  $this->exectabsts[$uid] = 0;
			if ($this->exectabsts[$uid] < filemtime($filename) && filemtime($filename) < time())
			{
				require_once $rootpath . "/support/cli.php";

				$cmdopts = array(
					"shortmap" => array(
						"d" => "dir",
						"e" => "envvar",
						"f" => "filter",
						"g" => "group",
						"u" => "user"
					),
					"rules" => array(
						"dir" => array("arg" => true),
						"envvar" => array("multiple" => true, "arg" => true),
						"filter" => array("multiple" => true, "arg" => true),
						"group" => array("arg" => true),
						"user" => array("arg" => true)
					),
					"allow_opts_after_param" => false
				);

				$this->exectabs[$uid] = array();
				$fp = fopen($filename, "rb");
				while (($line = fgets($fp)) !== false)
				{
					$line = trim($line);

					if ($line !== "" && $line{0} !== "#" && substr($line, 0, 2) !== "//")
					{
						$args = CLI::ParseCommandLine($cmdopts, ". " . $line);

						if (!isset($args["opts"]["envvar"]))  $args["opts"]["envvar"] = array();

						if (count($args["params"]))
						{
							$name = array_shift($args["params"]);

							if (!isset($args["opts"]["envvar"]))  $args["opts"]["envvar"] = array();
							if (!isset($args["opts"]["filter"]))  $args["opts"]["filter"] = array();

							// Process filters.
							foreach ($args["opts"]["filter"] as $num => $filter)  $args["opts"]["filter"][$num] = @json_decode($filter, true);
							$result = $this->NormalizeFilters($args["opts"]["filter"]);
							if (!$result["success"])  file_put_contents($basedir . "/error.txt", "An error occurred while processing the filters for " . $name . ".  " . $result["error"] . " (" . $result["errorcode"] . ")");
							else
							{
								$args["opts"]["filter"] = $result["filters"];

								if (!isset($this->exectabs[$uid][$name]))  $this->exectabs[$uid][$name] = array();

								$this->exectabs[$uid][$name][] = $args;

								$this->InitFutureFiller($name);
								$this->FutureFillItems($uid, $name);
							}
						}
					}
				}
				fclose($fp);

				$this->exectabsts[$uid] = filemtime($filename);
			}
		}

		public function ProcessAPI($reqmethod, $pathparts, $client, $userrow, $guestrow, $data)
		{
			global $rootpath, $userhelper;

			$basedir = self::InitUserFeedsBasePath($userrow);

			$this->InitExecTabs($basedir, $userrow->id);

			// Main API.
			$y = count($pathparts);
			if ($y < 4)  return array("success" => false, "error" => "Invalid API call.", "errorcode" => "invalid_api_call");

			if ($pathparts[3] === "notify")
			{
				// /feeds/v1/notify
				if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /feeds/v1/notify", "errorcode" => "use_post_request");
				if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
				if (!isset($data["type"]))  return array("success" => false, "error" => "Missing 'type'.", "errorcode" => "missing_type");
				if (!is_string($data["type"]) || ($data["type"] !== "insert" && $data["type"] !== "update" && $data["type"] !== "delete"))  return array("success" => false, "error" => "Invalid 'type'.  Expected 'insert', 'update', or 'delete'.", "errorcode" => "invalid_type");
				if (!isset($data["id"]))  return array("success" => false, "error" => "Missing 'id'.", "errorcode" => "missing_id");
				if (!is_string($data["id"]))  return array("success" => false, "error" => "Invalid 'id'.  Expected a string.", "errorcode" => "invalid_id");
				if (!isset($data["data"]))  return array("success" => false, "error" => "Missing 'data'.", "errorcode" => "missing_data");
				if (!isset($data["queue"]))  $data["queue"] = time();
				if (!is_int($data["queue"]))  return array("success" => false, "error" => "Invalid 'queue'.  Expected a UNIX timestamp integer.", "errorcode" => "invalid_queue");
				if (!isset($data["queuesize"]))  $data["queuesize"] = -1;
				if (!is_int($data["queuesize"]))  return array("success" => false, "error" => "Invalid 'queuesize'.", "errorcode" => "invalid_queuesize");
				if ($guestrow !== false && !$guestrow->serverexts["feeds"]["notify"])  return array("success" => false, "error" => "Notify access denied.", "errorcode" => "access_denied");
				if ($guestrow !== false && $guestrow->serverexts["feeds"]["name"] !== $data["name"])  return array("success" => false, "error" => "Feeds notification access denied to the specified name.", "errorcode" => "access_denied");

				// Queue the item.
				$uid = $userrow->id;
				$name = $data["name"];
				if (!isset($this->futureitems[$uid]))  $this->futureitems[$uid] = array();
				if (!isset($this->futureitems[$uid][$name]))  $this->futureitems[$uid][$name] = array();
				if (!isset($this->futureitems[$uid][$name][$data["queue"]]))
				{
					$this->futureitems[$uid][$name][$data["queue"]] = array();
					ksort($this->futureitems[$uid][$name]);
				}

				// Delete any matching IDs that occur after the delete time.
				if ($data["type"] === "delete")
				{
					foreach ($this->futureitems[$uid][$name] as $ts => $idmap)
					{
						if ($ts > $data["queue"])  unset($this->futureitems[$uid][$name][$ts][$data["id"]]);
					}
				}

				$this->futureitems[$uid][$name][$data["queue"]][$data["id"]] = array("type" => $data["type"], "data" => $data["data"]);

				// Process scheduled items.
				$this->ProcessFutureItems();

				// Reduce the future queue size to the max allowed.
				if ($data["queuesize"] > -1 && isset($this->futureitems[$uid]) && isset($this->futureitems[$uid][$name]) && count($this->futureitems[$uid][$name]) > $data["queuesize"])
				{
					$keys = array_reverse(array_keys($this->futureitems[$uid][$name]));
					while (count($this->futureitems[$uid][$name]) > $data["queuesize"])  unset($this->futureitems[$uid][$name][array_shift($keys)]);

					$this->FutureFillItems($uid, $name);
				}

				return array("success" => true);
			}
			else if ($pathparts[3] === "monitor")
			{
				if ($client instanceof WebServer_Client)  return array("success" => false, "error" => "WebSocket connection is required for:  /feeds/v1/monitor", "errorcode" => "use_websocket");
				if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /feeds/v1/monitor", "errorcode" => "use_get_request");
				if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
				if (!is_string($data["name"]) || $data["name"] === "")  return array("success" => false, "error" => "Invalid feed 'name'.", "errorcode" => "invalid_name");
				if (!isset($data["filters"]))  $data["filters"] = array();
				if (!is_array($data["filters"]))  return array("success" => false, "error" => "Invalid 'filters'.", "errorcode" => "invalid_filters");
				if ($guestrow !== false && !$guestrow->serverexts["feeds"]["monitor"])  return array("success" => false, "error" => "Monitor access denied.", "errorcode" => "access_denied");
				if ($guestrow !== false && $guestrow->serverexts["feeds"]["name"] !== $data["name"])  return array("success" => false, "error" => "Monitoring access denied to the specified name.", "errorcode" => "access_denied");

				$uid = $userrow->id;
				$name = $data["name"];

				// Normalize the filters into a common syntax.
				$result = $this->NormalizeFilters($data["filters"]);
				if (!$result["success"])  return $result;

				if (!isset($this->monitors[$uid]))  $this->monitors[$uid] = array();
				if (!isset($this->monitors[$uid][$name]))  $this->monitors[$uid][$name] = array();
				if (!isset($this->monitors[$uid][$name][$client->id]))  $this->monitors[$uid][$name][$client->id] = array();

				$this->monitors[$uid][$name][$client->id][$data["api_sequence"]] = $result["filters"];

				$this->InitFutureFiller($name);
				$this->FutureFillItems($uid, $name);

				return array("success" => true, "name" => $name, "enabled" => true);
			}
			else if ($pathparts[3] === "guest")
			{
				// Guest API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /feeds/v1/guest.", "errorcode" => "invalid_api_call");
				if ($guestrow !== false)  return array("success" => false, "error" => "Guest API key detected.  Access denied to /feeds/v1/guest.", "errorcode" => "access_denied");
				if (!$userrow->serverexts["feeds"]["guests"])  return array("success" => false, "error" => "Insufficient privileges.  Access denied to /feeds/v1/guest.", "errorcode" => "access_denied");

				if ($pathparts[4] === "list")
				{
					// /feeds/v1/guest/list
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /feeds/v1/guest/list", "errorcode" => "use_get_request");

					return $userhelper->GetGuestsByServerExtension($userrow->id, "feeds");
				}
				else if ($pathparts[4] === "create")
				{
					// /feeds/v1/guest/create
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /feeds/v1/guest/create", "errorcode" => "use_post_request");
					if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
					if (!isset($data["notify"]))  return array("success" => false, "error" => "Missing 'notify'.", "errorcode" => "missing_notify");
					if (!isset($data["monitor"]))  return array("success" => false, "error" => "Missing 'monitor'.", "errorcode" => "missing_monitor");
					if (!isset($data["expires"]))  return array("success" => false, "error" => "Missing 'expires'.", "errorcode" => "missing_expires");

					$options = array(
						"name" => (string)$data["name"],
						"notify" => (bool)(int)$data["notify"],
						"monitor" => (bool)(int)$data["monitor"]
					);

					$expires = (int)$data["expires"];

					if ($expires <= time())  return array("success" => false, "error" => "Invalid 'expires' timestamp.", "errorcode" => "invalid_expires");

					return $userhelper->CreateGuest($userrow->id, "feeds", $options, $expires);
				}
				else if ($pathparts[4] === "delete")
				{
					// /feeds/v1/guest/delete/ID
					if ($reqmethod !== "DELETE")  return array("success" => false, "error" => "DELETE request required for:  /feeds/v1/guest/delete/ID", "errorcode" => "use_delete_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of guest for:  /feeds/v1/guest/delete/ID", "errorcode" => "missing_id");

					return $userhelper->DeleteGuest($pathparts[5], $userrow->id);
				}
			}

			return array("success" => false, "error" => "Invalid API call.", "errorcode" => "invalid_api_call");
		}
	}
?>