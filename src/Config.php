<?php

namespace PhpExt\Config;

use Dotenv\Dotenv;
use Exception;
use Exceptions\ConfigNotFound;
use Exceptions\KeyNotFoundInConfig;
use Symfony\Component\Yaml\Yaml;

class Config
{
    const CONFIG_FILE_EXT = 'yml';

    /** @var string */
    private $configPath = '';

    /** @var array */
    private $loadedFiles = [];

    /** @var array */
    private $configData = [];

    public function __construct(string $dotEnvPath, string $configPath)
    {
        $dotEnv = new Dotenv($dotEnvPath);
        $dotEnv->load();

        $this->configPath = $this->prepareConfigPath($configPath);
        $configFilesList = $this->getConfigFileList();

        $appEnv = getenv('APP_ENV');
        if (!empty($appEnv)) {
            $configFilesList = array_merge($configFilesList, $this->getOverrideFileList($appEnv));
        }

        foreach ($configFilesList as $file) {
            $this->loadFile($file);
        }
    }

    private function loadFile(string $fileName): void
    {
        $fileNameParts = explode('.', $fileName);
        $fileExtension = end($fileNameParts);
        if ($fileExtension !== 'yml') {
            $fileName = $fileName . '.yml';
        }

        // skip load loaded file
        if (in_array($fileName, $this->loadedFiles)) {
            return;
        }

        $this->loadedFiles[] = $fileName;

        if (!is_readable($fileName)) {
            throw new ConfigNotFound('File not found: ' . $fileName);
        }

        $data = file_get_contents($fileName);
        $config = Yaml::parse($data);
        $this->configData = array_replace_recursive($this->configData, $config);
    }

    /**
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    public function get(string $key)
    {
        $value = $this->getValue($key);
        if (!is_null($value)) {
            return $value;
        } else {
            throw new KeyNotFoundInConfig("'{$key}' not found in config.");
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->configData[$key] = $value;
    }

    private function prepareConfigPath(string $configPath): string
    {
        return rtrim($configPath, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string[]
     */
    private function getConfigFileList(): array
    {
        return glob(sprintf('%s%s*%s', $this->configPath, DIRECTORY_SEPARATOR, self::CONFIG_FILE_EXT));
    }

    /**
     * @param string $appEnv
     * @return string[]
     */
    private function getOverrideFileList(string $appEnv): array
    {
        return glob(sprintf('%s%s%s*%s', $this->configPath, $appEnv, DIRECTORY_SEPARATOR, self::CONFIG_FILE_EXT));
    }

    /**
     * Search config value
     *
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    private function getValue(string $key)
    {
        if (empty($this->configData)) {
            return null;
        }

        $value = null;
        $found = false;
        $configData = $this->configData;

        // if exist value in first level
        if (isset($configData[$key])) {
            $found = true;
            $value = $configData[$key];
        } else {
            // try found value in inner arrays

            // split key to array
            $keyPath = explode('.', trim($key, '.'));
            // more than one key in array?
            if (count($keyPath) > 1) {
                foreach ($keyPath as $index => $propertyKey) {
                    if (is_array($configData) && isset($configData[$propertyKey])) {
                        $configData = $configData[$propertyKey];
                        // search dotted string in current array
                        if (count($keyPath) > $index + 1) {
                            $dottedIndex = implode('.', array_slice($keyPath, $index + 1));
                            if (isset($configData[$dottedIndex])) {
                                $found = true;
                                $value = $configData[$dottedIndex];
                            }
                        }
                    }
                }
            }
        }

        if ($found) {
            $value = $this->prepareValue($key, $value);
        }

        return $value;
    }

    /**
     * Prepare variables values in parameter
     *
     * @param string $key
     * @param mixed $value
     *
     * @return mixed
     * @throws Exception
     */
    private function prepareValue(string $key, $value)
    {
        if (is_array($value)) {
            return $this->prepareVariables($key, $value);
        } elseif (is_string($value)) {
            return $this->prepareVariable($key, $value);
        }

        return $value;
    }

    /**
     * Prepare all config variables
     *
     * @param string $key
     * @param mixed[] $configData
     *
     * @return mixed[]
     */
    private function prepareVariables(string $key, array $configData): array
    {
        array_walk_recursive(
            $configData,
            function (&$value) use ($key) {
                $value = $this->prepareVariable($key, $value);
            }
        );

        return $configData;
    }

    /**
     * Prepare config variable
     *
     * @param string $key
     * @param mixed $value
     *
     * @return mixed
     * @throws Exception
     */
    private function prepareVariable(string $key, $value)
    {
        preg_match_all('/\%(?<variables>.*?)\%/', $value, $matches);
        $variables = $matches['variables'] ?? false;
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                $value = $this->getVariable($key, $variable, $value);
                if ($value == '%' . $variable . '%') {
                    throw new Exception(sprintf('Config variable not defined: %s.', $variable));
                }
            }
        }

        return $value;
    }

    /**
     * Get config variable
     *
     * @param string $key
     * @param string $variable
     * @param mixed $value
     *
     * @return mixed
     * @throws Exception
     */
    private function getVariable(string $key, string $variable, $value)
    {
        if (getenv($variable) !== false) {
            $value = str_replace("%$variable%", getenv($variable), $value);
        } else {
            // disable recursive search
            if ($key != $variable) {
                $searchValue = $this->getValue($variable);
                if (!is_null($searchValue)) {
                    $value = str_replace("%$variable%", $searchValue, $value);
                }
            }
        }

        return $value;
    }
}