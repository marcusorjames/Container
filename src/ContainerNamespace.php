<?php
/**
 * ClanCats Container
 *
 * @link      https://github.com/ClanCats/Container/
 * @copyright Copyright (c) 2016-2017 Mario Döring
 * @license   https://github.com/ClanCats/Container/blob/master/LICENSE (MIT License)
 */
namespace ClanCats\Container;

use ClanCats\Container\{
    Exceptions\ContainerNamespaceException
};

use ClanCats\Container\ContainerParser\{
    ContainerParser
};

/**
 * The container namespace acts as a collection of multiple 
 * container files that get parsed into one pot.
 */
class ContainerNamespace
{
    /**
     * The container namespaces parameters
     * 
     * @var array
     */
    protected $parameters = [];

    /**
     * The container namespaces service defintions
     * 
     * @param array[string => Service]
     */
    protected $services = [];

    /**
     * An array of service names that should be shared through the container
     * 
     * @param array[string]
     */
    protected $shared = [];

    /**
     * An array of paths 
     * 
     *     name => container file path
     * 
     * @var array
     */
    protected $paths = [];

    /**
     * Constructor
     * 
     * @param $paths array[string:string]   
     */
    public function __construct(array $paths = [])
    {
        $this->paths = $paths;
    }

    /**
     * Does the container namespace have a parameter with the given name?
     * 
     * @param string            $name The parameter name.
     * @return bool
     */
    public function hasParameter(string $name) : bool
    {
        return array_key_exists($name, $this->parameters);
    }

    /**
     * Set the given parameter and value
     * 
     * @param string            $name The parameter name.
     * @param mixed             $value The parameter value.
     * @return void
     */
    public function setParameter(string $name, $value) 
    {
        $this->parameters[$name] = $value;
    }

    /**
     * Get all parameters from the container namespace
     * 
     * @return array
     */
    public function getParameters() : array
    {
        return $this->parameters;
    }

    /**
     * Is the given path name binded?
     * 
     * @param string            $name The container files path key.
     * @return bool
     */
    public function has(string $name) : bool
    {
        return isset($this->paths[$name]) && is_string($this->paths[$name]);
    }

    /**
     * Simply returns the contents of the given file
     * 
     * @param return string         $containerFilePath The path to a container file.
     * @return string
     */
    protected function getCodeFromFile(string $containerFilePath) : string
    {
        if (!file_exists($containerFilePath) || !is_readable($containerFilePath))
        {
            throw new ContainerNamespaceException("The file '" . $containerFilePath . "' is not readable or does not exist.");
        }

        return file_get_contents($containerFilePath);
    }

    /**
     * Returns the code of in the current namespace binded file.
     * 
     * @return string           $name The container files path key.
     */
    public function getCode(string $name) : string
    {
        if (!$this->has($name))
        {
            throw new ContainerNamespaceException("There is no path named '" . $name . "' binded to the namespace.");
        }

        return $this->getCodeFromFile($this->paths[$name]);
    }

    /**
     * Parse the given container file with the current namespace
     * 
     * @param string        $containerFilePath The path to a container file.
     * @return array
     */ 
    public function parse(string $containerFilePath) : array
    {
        $parser = new ContainerParser($this->getCodeFromFile($containerFilePath), $this);
    }
}