<?php
namespace Cone;
use Exception;
final class Cone
{
	const VERSION = "0.6.2";
	const PACKAGES_FILE = __DIR__."/../packages.json";
	const INSTALLED_PACKAGES_FILE = __DIR__."/../installed_packages.json";
	/**
	 * @var $packages_cache Package[]
	 */
	private static $packages_cache;
	private static $installed_packages_list_cache;

	static function isWindows()
	{
		return defined("PHP_WINDOWS_VERSION_MAJOR");
	}

	static function isUnix()
	{
		return !self::isWindows();
	}

	static function isMacOS()
	{
		return stristr(PHP_OS, "DAR");
	}

	static function isLinux()
	{
		return stristr(PHP_OS, "LINUX");
	}

	static function isAdmin()
	{
		return self::isWindows() ? trim(shell_exec("NET SESSION 2>NUL")) != "" : trim(shell_exec("whoami")) == "root";
	}

	static function getPathFolder()
	{
		return self::isWindows() ? __DIR__."/../path/" : "/usr/bin/";
	}

	static function getTmpFolder()
	{
		return self::isWindows() ? getenv("tmp") : "/tmp/";
	}

	static function which($name)
	{
		return trim(shell_exec(self::isWindows() ? "WHERE ".$name." 2>NUL" : "which ".$name));
	}

	static function getPhpDir()
	{
		return dirname(self::which("php"));
	}

	static function getPhpIni()
	{
		$ini = php_ini_loaded_file();
		if($ini !== false)
		{
			return $ini;
		}
		$dir = self::getPhpDir();
		copy($dir."/php.ini-development", $dir."/php.ini");
		return $dir."/php.ini";
	}

	static function reallyDelete($path)
	{
		if(substr($path, -1) == "/")
		{
			$path = substr($path, 0, -1);
		}
		if(!file_exists($path))
		{
			return;
		}
		if(self::isUnix())
		{
			shell_exec("rm -rf ".escapeshellarg($path));
		}
		else if(is_dir($path))
		{
			foreach(scandir($path) as $file)
			{
				if(!in_array($file, [".", ".."]))
				{
					self::reallyDelete($path."/".$file);
				}
			}
			rmdir($path);
		}
		else
		{
			unlink($path);
		}
	}

	static function download($url, $output)
	{
		shell_exec(self::isWindows() ? "powershell -Command \"[Net.ServicePointManager]::SecurityProtocol = 'tls12, tls11, tls'; Invoke-WebRequest \\\"{$url}\\\" -UseBasicParsing -OutFile \\\"{$output}\\\"\"" : "wget \"{$url}\" -O \"{$output}\"");
	}

	static function extract($file, $target_dir)
	{
		if(self::isWindows())
		{
			$tmp = self::getTmpFolder();
			mkdir("{$tmp}\\Cone_extract");
			shell_exec("COPY \"{$file}\" \"{$tmp}\\Cone_extract.zip\"");
			file_put_contents("tmp.vbs", "Set s = CreateObject(\"Shell.Application\")\r\ns.NameSpace(\"{$tmp}\\Cone_extract\").CopyHere(s.NameSpace(\"{$tmp}\\Cone_extract.zip\").items)");
			shell_exec("cscript //nologo tmp.vbs");
			unlink("tmp.vbs");
			unlink("{$tmp}\\Cone_extract.zip");
			shell_exec("MOVE \"{$tmp}\\Cone_extract\" \"{$target_dir}\"");
		}
		else
		{
			mkdir($target_dir);
			shell_exec("tar -xf \"{$file}\" -C \"{$target_dir}\"");
		}
	}

	static function getRemotePackageLists()
	{
		// TODO: Allow adding and removing from this list
		return ["https://packages.getcone.org/main.json"];
	}

    /**
     * @return Package[]
     */
	static function getPackages()
	{
		if(self::$packages_cache === NULL)
		{
			self::$packages_cache = [];
			foreach(json_decode(file_get_contents(self::PACKAGES_FILE), true) as $raw_package)
			{
				array_push(self::$packages_cache, new Package($raw_package));
			}
		}
		return self::$packages_cache;
	}

	static function getPackage($name, $try_aliases = false)
	{
	    foreach(self::getPackages() as $package)
        {
            if($package->getName() == $name)
            {
                return $package;
            }
        }
	    if($try_aliases)
	    {
            foreach(self::$packages_cache as $package)
            {
                if(in_array($name, $package->getAliases()))
                {
                    return $package;
                }
            }
        }
		return null;
	}

	static function getInstalledPackagesList()
	{
		if(self::$installed_packages_list_cache === NULL)
		{
			self::$installed_packages_list_cache = is_file(self::INSTALLED_PACKAGES_FILE) ? json_decode(file_get_contents(self::INSTALLED_PACKAGES_FILE), true) : [];
		}
		return self::$installed_packages_list_cache;
	}

	static function setInstalledPackagesList($list)
	{
		self::$installed_packages_list_cache = $list;
		file_put_contents(self::INSTALLED_PACKAGES_FILE, json_encode($list));
	}

	static function removeUnneededDependencies(&$installed_packages = null)
	{
		if($installed_packages === null)
		{
			$installed_packages = Cone::getInstalledPackagesList();
		}
		foreach($installed_packages as $name => $data)
		{
			$package = Cone::getPackage($name);
			if(!$package->isManuallyInstalled())
			{
				$needed = false;
				foreach($installed_packages as $name_ => $data_)
				{
					if(in_array($name, Cone::getPackage($name_)->getDependenciesList()))
					{
						$needed = true;
						break;
					}
				}
				if(!$needed)
				{
					/** @deprecated Fallback if display_name is not set for packages installed before 0.6.1 */
					echo "Removing unneeded dependency ".(array_key_exists("display_name", $data) ? $data["display_name"] : $name)."...\n";
					try
					{
						$package->uninstall();
						unset($installed_packages[$name]);
					}
					catch(Exception $e)
					{
						echo $e->getMessage()."\n".$e->getTraceAsString()."\n";
					}
				}
			}
		}
	}
}
