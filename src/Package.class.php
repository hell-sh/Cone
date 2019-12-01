<?php
namespace Cone;
use Exception;
class Package
{
	/**
	 * @var array $data
	 */
	private $data;

	/**
	 * @param array $data
	 */
	function __construct($data)
	{
		$this->data = $data;
	}

	/**
	 * @return array
	 */
	function getData()
	{
		return $this->data;
	}

	function isManuallyInstalled(&$installed_packages = null)
	{
		return self::getInstallData($installed_packages)["manual"];
	}

	function getInstallData(&$installed_packages = null)
	{
		return @Cone::getInstalledPackagesList($installed_packages)[$this->getName()];
	}

	function getName()
	{
		return $this->data["name"];
	}

	function getSource()
	{
		return $this->data["source"];
	}

	function getAliases()
	{
		return array_key_exists("aliases", $this->data) ? $this->data["aliases"] : [];
	}

	function getRiskyAliases()
	{
		return array_key_exists("risky_aliases", $this->data) ? $this->data["risky_aliases"] : [];
	}

	/**
	 * @param array|null $installed_packages
	 * @throws Exception
	 */
	function update(&$installed_packages = null)
	{
		$in_flow = $installed_packages !== null;
		if(!$in_flow)
		{
			$installed_packages = Cone::getInstalledPackagesList();
		}
		if(array_key_exists("update", $this->data))
		{
			$this->performSteps($this->data["update"]);
		}
		else if($this->hasVersion() && (strpos($this->data["version"], "dev") !== false || version_compare($this->data["version"], $this->getInstallData()["version"], ">")))
		{
			echo "Updating ".$this->getDisplayName()."...\n";
			$this->uninstall($installed_packages);
			$this->install($installed_packages, true);
		}
		if(!$in_flow)
		{
			Cone::removeUnneededDependencies($installed_packages);
			Cone::setInstalledPackages($installed_packages);
		}
	}

	/**
	 * @param $steps
	 * @return array
	 * @throws Exception
	 */
	function performSteps($steps)
	{
		$inverted_actions = [];
		foreach($steps as $step)
		{
			switch($step["type"])
			{
				case "platform_switch":
					$this->platformSwitch($step, function($platform) use ($step)
					{
						$this->performSteps($step[$platform]);
					});
					break;
				case "platform_download_and_extract":
					$this->platformSwitch($step, function($platform) use ($step)
					{
						$archive_ext = ($platform == "windows" ? ".zip" : ".tar.gz");
						$this->performSteps([
							[
								"type" => "download",
								"target" => $step["target"].$archive_ext
							] + $step[$platform],
							[
								"type" => "extract",
								"file" => $step["target"].$archive_ext,
								"target" => $step["target"]
							],
							[
								"type" => "delete",
								"file" => $step["target"].$archive_ext
							]
						]);
					});
					break;
				case "echo":
					echo $step["value"]."\n";
					break;
				case "shell_exec":
					passthru($step["value"]);
					break;
				case "enable_php_extension":
					array_push($inverted_actions, ["type" => "disable_php_extension"] + $step);
					file_put_contents(php_ini_loaded_file(), str_replace([
						"\n;extension=".$step["name"],
						"\n;extension=php_".$step["name"].".dll"
					], [
						"\nextension=".$step["name"],
						"\nextension=php_".$step["name"].".dll"
					], file_get_contents(php_ini_loaded_file())));
					break;
				case "disable_php_extension":
					array_push($inverted_actions, ["type" => "enable_php_extension"] + $step);
					file_put_contents(php_ini_loaded_file(), str_replace([
						"\nextension=".$step["name"],
						"\nextension=php_".$step["name"].".dll"
					], [
						"\n;extension=".$step["name"],
						"\n;extension=php_".$step["name"].".dll"
					], file_get_contents(php_ini_loaded_file())));
					break;
				case "install_unix_package":
					if(Cone::isUnix())
					{
						array_push($inverted_actions, ["type" => "remove_unix_package"] + $step);
						UnixPackageManager::installPackage($step["name"]);
					}
					break;
				case "remove_unix_package":
				case "uninstall_unix_package":
					if(Cone::isUnix())
					{
						array_push($inverted_actions, ["type" => "install_unix_package"] + $step);
						UnixPackageManager::removePackage($step["name"]);
					}
					break;
				case "download":
					Cone::download(str_replace("{version}", $this->getVersion(), $step["url"]), $step["target"]);
					if(array_key_exists("hash", $step))
					{
						foreach($step["hash"] as $algo => $hash)
						{
							if(hash_file($algo, $step["target"]) != $hash)
							{
								unlink($step["target"]);
								throw new Exception($step["target"]." signature mismatch");
							}
						}
					}
					break;
				case "extract":
					if(!is_file($step["file"]))
					{
						throw new Exception($step["file"]." can't be extracted as it doesn't exist");
					}
					Cone::extract($step["file"], $step["target"]);
					break;
				case "delete":
					if(!file_exists($step["file"]))
					{
						throw new Exception($step["file"]." can't be deleted as it doesn't exist.");
					}
					Cone::reallyDelete($step["file"]);
					break;
				case "keep":
					$file = str_replace("{version}", $this->getVersion(), $step["file"]);
					if(!file_exists($file))
					{
						throw new Exception($file." can't be kept as it doesn't exist");
					}
					$dir = __DIR__."/../packages/".$this->getName()."/";
					if(!empty($step["as"]) && !is_dir($dir))
					{
						mkdir($dir);
					}
					rename($file, $dir.$step["as"]);
					break;
				default:
					throw new Exception("Unknown step type: ".$step["type"]);
			}
		}
		return $inverted_actions;
	}

	protected function platformSwitch($step, $callback)
	{
		$arch = PHP_INT_SIZE == 8 ? "x64" : "x86";
		if(Cone::isWindows())
		{
			if(array_key_exists("windows", $step))
			{
				$callback("windows");
			}
			if(array_key_exists("windows_{$arch}", $step))
			{
				$callback("windows_{$arch}");
			}
		}
		else
		{
			if(array_key_exists("unix", $step))
			{
				$callback("unix");
			}
			if(array_key_exists("unix_{$arch}", $step))
			{
				$callback("unix_{$arch}");
			}
			if(Cone::isLinux())
			{
				if(array_key_exists("linux", $step))
				{
					$callback("linux");
				}
				if(array_key_exists("linux_{$arch}", $step))
				{
					$callback("linux_{$arch}");
				}
			}
			else if(Cone::isMacOS())
			{
				if(array_key_exists("macos", $step))
				{
					$callback("macos");
				}
				if(array_key_exists("macos_{$arch}", $step))
				{
					$callback("macos_{$arch}");
				}
			}
		}
	}

	function getVersion()
	{
		return @$this->data["version"];
	}

	function hasVersion()
	{
		return array_key_exists("version", $this->data);
	}

	function getDisplayName(&$installed_packages = null)
	{
		if(array_key_exists("display_name", $this->data))
		{
			return $this->data["display_name"];
		}
		if($this->isInstalled($installed_packages))
		{
			return $this->getInstallData($installed_packages)["display_name"];
		}
		return strtoupper(substr($this->getName(), 0, 1)).substr($this->getName(), 1);
	}

	function isInstalled(&$installed_packages = null)
	{
		return $this->getInstallData($installed_packages) !== null;
	}

	/**
	 * @param array|null $installed_packages
	 * @throws Exception
	 */
	function uninstall(&$installed_packages = null)
	{
		$in_flow = $installed_packages !== null;
		if(!$in_flow)
		{
			$installed_packages = Cone::getInstalledPackagesList();
		}
		if(!self::isInstalled($installed_packages))
		{
			return;
		}
		$dir = __DIR__."/../packages/".$this->getName();
		if(is_dir($dir))
		{
			Cone::reallyDelete($dir);
		}
		$data = self::getInstallData();
		if(array_key_exists("shortcuts", $data))
		{
			foreach($data["shortcuts"] as $name)
			{
				unlink(Cone::getPathFolder().$name);
				if(Cone::isWindows())
				{
					unlink(Cone::getPathFolder().$name.".bat");
				}
			}
		}
		if(array_key_exists("startmenu", $data))
		{
			foreach($data["startmenu"] as $name)
			{
				if(Cone::isWindows())
				{
					unlink(getenv("PROGRAMDATA")."/Microsoft/Windows/Start Menu/Programs/Hell.sh/Cone/$name.lnk");
				}
				else if(is_dir(getenv("HOME")."/.local/share/applications"))
				{
					unlink(getenv("HOME")."/.local/share/applications/$name.desktop");
				}
			}
		}
		if(array_key_exists("variables", $data))
		{
			if(Cone::isWindows())
			{
				foreach($data["variables"] as $name)
				{
					shell_exec('REG DELETE "HKEY_LOCAL_MACHINE\SYSTEM\CurrentControlSet\Control\Session Manager\Environment" /F /V '.$name);
				}
			}
			else
			{
				$env = [];
				foreach(file("/etc/environment") as $line)
				{
					if($line = trim($line))
					{
						$arr = explode("=", $line, 2);
						$env[$arr[0]] = $arr[1];
					}
				}
				foreach($data["variables"] as $name)
				{
					unset($env[$name]);
				}
				file_put_contents("/etc/environment", join("\n", $env));
			}
		}
		if(Cone::isWindows() && array_key_exists("file_associations", $data))
		{
			foreach($data["file_associations"] as $ext)
			{
				shell_exec("Ftype {$ext}file=\nAssoc .{$ext}=");
			}
		}
		if(array_key_exists("uninstall", $data))
		{
			$this->performSteps($data["uninstall"]);
		}
		unset($installed_packages[$this->getName()]);
		if(!$in_flow)
		{
			Cone::setInstalledPackages($installed_packages);
		}
	}

	/**
	 * @param string $dir
	 * @param array $data
	 * @return string[]
	 */
	private static function getShortcutTarget($dir, $data)
	{
		$target = $dir."/".$data["target"];
		if(Cone::isWindows() && array_key_exists("target_winext", $data))
		{
			$target .= $data["target_winext"];
		}
		$target = realpath($target);
		if($target)
		{
			$target = "\"{$target}\"";
		}
		else
		{
			$target = $data["target"];
		}
		$args = "";
		if(array_key_exists("target_arguments", $data))
		{
			foreach($data["target_arguments"] as $arg)
			{
				if(array_key_exists("path", $arg))
				{
					$args .= "\"".realpath($dir."/".$arg["path"])."\" ";
				}
				else
				{
					$args .= $arg["value"]." ";
				}
			}
		}
		return [$target, rtrim($args)];
	}

	/**
	 * @param array|null $installed_packages
	 * @param bool $force
	 * @param array $env_arr
	 * @param bool $as_dependency
	 * @throws Exception
	 */
	function install(&$installed_packages = null, $force = false, &$env_arr = [], $as_dependency = false)
	{
		$in_flow = $installed_packages !== null;
		if(!$in_flow)
		{
			$installed_packages = Cone::getInstalledPackagesList();
		}
		if($this->isInstalled($installed_packages))
		{
			return;
		}
		if(!$force && array_key_exists("prerequisites", $this->data))
		{
			foreach($this->data["prerequisites"] as $prerequisite)
			{
				switch($prerequisite["type"])
				{
					case "command_not_found":
						if(Cone::which($prerequisite["value"]) != "")
						{
							if(!$as_dependency)
							{
								echo "Not installing ".$this->getDisplayName()." because '".$prerequisite["value"]."' is a registered command.\n";
							}
							return;
						}
						break;
					default:
						throw new Exception("Unknown prerequisite type: ".$prerequisite["type"]);
				}
			}
		}
		if(array_key_exists("dependencies", $this->data))
		{
			foreach($this->getDependencies() as $dependency)
			{
				$dependency->install($installed_packages, false, $env_arr, $this->getDisplayName());
			}
		}
		echo "Installing ";
		$installed_packages[$this->getName()] = [
			"display_name" => $this->getDisplayName($installed_packages),
			"manual" => !$as_dependency
		];
		echo $installed_packages[$this->getName()]["display_name"];
		if($this->hasVersion())
		{
			echo " ";
			if(strpos($this->data["version"], "dev") === false)
			{
				echo "v";
			}
			echo $this->data["version"];
			$installed_packages[$this->getName()]["version"] = $this->data["version"];
		}
		echo "...\n";
		if(!is_dir(__DIR__."/../packages/"))
		{
			mkdir(__DIR__."/../packages/");
		}
		$uninstall_actions = [];
		if(array_key_exists("install", $this->data))
		{
			$uninstall_actions = $this->performSteps($this->data["install"]);
		}
		$dir = realpath(__DIR__."/../packages/".$this->getName());
		if(array_key_exists("shortcuts", $this->data))
		{
			if($dir === false)
			{
				throw new Exception("Can't create any shortcuts as no file was kept");
			}
			foreach($this->data["shortcuts"] as $name => $data)
			{
				if(!array_key_exists("target", $data))
				{
					throw new Exception("Shortcut is missing target");
				}
				$target = join(" ", self::getShortcutTarget($dir, $data));
				$path = Cone::getPathFolder().$name;
				file_put_contents($path, "#!/bin/bash\n{$target}\"\$@\"");
				if(Cone::isWindows())
				{
					file_put_contents($path.".bat", "@ECHO OFF\n{$target}%*");
				}
				else
				{
					shell_exec("chmod +x ".$path);
				}
			}
			$installed_packages[$this->getName()]["shortcuts"] = array_keys($this->data["shortcuts"]);
		}
		if(array_key_exists("startmenu", $this->data))
		{
			if($dir === false)
			{
				throw new Exception("Can't create any start menu entries as no file was kept");
			}
			foreach($this->data["startmenu"] as $name => $data)
			{
				if(!array_key_exists("target", $data))
				{
					throw new Exception("Start menu entry is missing target");
				}
				$target = self::getShortcutTarget($dir, $data);
				if(Cone::isWindows())
				{
					file_put_contents("tmp.vbs", "Set s = WScript.CreateObject(\"WScript.Shell\")\r\nSet l = s.CreateShortcut(\"".realpath(getenv("PROGRAMDATA")."/Microsoft/Windows/Start Menu/Programs/Hell.sh/Cone/")."\\$name.lnk\")\r\nl.TargetPath = \"".$target[0]."\"\r\nl.Arguments = \"".str_replace("\"", "\"\"",$target[1])."\"\r\nl.Save\r\n");
					echo file_get_contents("tmp.vbs")."\n";
					shell_exec("cscript //nologo tmp.vbs");
					unlink("tmp.vbs");
				}
				else if(is_dir(getenv("HOME")."/.local/share/applications"))
				{
					file_put_contents(getenv("HOME")."/.local/share/applications/$name.desktop", "[Desktop Entry]\nName=$name\nExec=".join(" ", $target)."\n");
				}
			}
			$installed_packages[$this->getName()]["startmenu"] = array_keys($this->data["startmenu"]);
		}
		if(array_key_exists("variables", $this->data))
		{
			foreach($this->data["variables"] as $name => $data)
			{
				if(array_key_exists("path", $data))
				{
					$value = realpath($dir."/".$data["path"]);
				}
				else
				{
					$value = $data["value"];
				}
				if(Cone::isWindows())
				{
					shell_exec("SETX /m {$name} \"{$value}\"");
				}
				else
				{
					file_put_contents("/etc/environment", file_get_contents("/etc/environment")."{$name}={$value}\n");
				}
				putenv("{$name}={$value}");
				array_push($env_arr, $name);
			}
			$installed_packages[$this->getName()]["variables"] = array_keys($this->data["variables"]);
		}
		if(Cone::isWindows() && array_key_exists("file_associations", $this->data))
		{
			if($dir === false)
			{
				throw new Exception("Can't create any file associations as no file was kept");
			}
			foreach($this->data["file_associations"] as $ext => $cmd)
			{
				shell_exec("Ftype {$ext}file={$dir}\\{$cmd}\nAssoc .{$ext}={$ext}file");
			}
			$installed_packages[$this->getName()]["file_associations"] = array_keys($this->data["file_associations"]);
		}
		if(array_key_exists("uninstall", $this->data))
		{
			$uninstall_actions = array_merge($uninstall_actions, $this->data["uninstall"]);
		}
		if($uninstall_actions)
		{
			$installed_packages[$this->getName()]["uninstall"] = $uninstall_actions;
		}
		if(!$in_flow)
		{
			Cone::setInstalledPackages($installed_packages);
		}
	}

	/**
	 * @return Package[]
	 */
	function getDependencies()
	{
		$dependencies = [];
		foreach($this->getDependenciesList() as $name)
		{
			array_push($dependencies, Cone::getPackage($name));
		}
		return $dependencies;
	}

	function getDependenciesList()
	{
		return array_key_exists("dependencies", $this->data) ? $this->data["dependencies"] : [];
	}
}
