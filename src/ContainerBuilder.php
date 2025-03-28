<?php
/**
 * ClanCats Container
 *
 * @link      https://github.com/ClanCats/Container/
 * @copyright Copyright (c) 2016-2024 Mario Döring
 * @license   https://github.com/ClanCats/Container/blob/master/LICENSE (MIT License)
 */
namespace ClanCats\Container;

use ClanCats\Container\{
    Container,

    Exceptions\ContainerBuilderException
};

class ContainerBuilder 
{
    /**
     * The full container name with namespace
     * 
     * @var string
     */
    protected string $containerName;

    /**
     * The class name without namespace
     * 
     * @var string
     */
    protected string $containerClassName;

    /**
     * Just the namespace
     * 
     * @var string|null
     */
    protected ?string $containerNamespace = null;

    /**
     * Should we override the debug function in the generated container
     * So that when the container is var_dump't we do not end in an 
     * infinite recrusion?
     */
    protected bool $overrideDebugInfo = true;

    /**
     * An array of paramters to be builded directly
     * as propterty.
     * 
     * @var array<string, mixed>
     */
    protected array $parameters = [];

    /**
     * An array of service aliases to be defined.
     * 
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * An array of binded services
     * 
     * @var array<string, ServiceDefinitionInterface>
     */
    protected array $services = [];

    /**
     * An array of service names that should be shared in the builded container
     * 
     * @var array<string>
     */
    protected array $shared = [];

    /**
     * An array of converted service names
     * The normalized service names is camel cased and should be usable as method name.
     * 
     * @var array<string>
     */
    private array $normalizedServiceNames = [];

    /**
     * Constrcut a container builder instance 
     * 
     * @param string            $containerName
     * @return void
     */
    public function __construct(string $containerName)
    {
        $this->setContainerName($containerName);
    }

    /**
     * Sets the container name 
     * This will also update the "containerClassName" and "containerNamespace"
     * 
     * @param string            $containerName
     * @return void
     */
    public function setContainerName(string $containerName) 
    {
        if (empty($containerName) || !(preg_match('/^[a-zA-Z0-9\\\\_]*$/', $containerName)) || is_numeric($containerName[0]))
        {
            throw new ContainerBuilderException('The container name cannot be empty, start with a number or contain sepcial characters except "\\".');
        }

        if ($containerName[0] === "\\")
        {
            $containerName = substr($containerName, 1);
        }

        $this->containerClassName = $this->containerName = $containerName;

        // check if we need to generate a namespace
        if (($pos = strrpos($containerName, "\\")) !== false)
        {
            $this->containerNamespace = substr($containerName, 0, $pos);
            $this->containerClassName = substr($containerName, $pos + 1);
        }
    }

    /**
     * Sets the override debug info flag
     * When set to false the generated container will not override the __debugInfo method.
     * 
     * @param bool              $overrideDebugInfo
     * @return void
     */
    public function setOverrideDebugInfo(bool $overrideDebugInfo) : void
    {
        $this->overrideDebugInfo = $overrideDebugInfo;
    }

    /**
     * Get the current container full name
     * 
     * @return string 
     */
    public function getContainerName() : string
    {
        return $this->containerName;
    }

    /**
     * Get the current container class name without namespace
     * 
     * @return string
     */
    public function getContainerClassName() : string
    {
        return $this->containerClassName;
    }

    /**
     * Get the php container namespace not to confuse with the "ContainerNamespace" class.
     * 
     * @return string|null
     */
    public function getContainerNamespace() : ?string
    {
        return $this->containerNamespace;
    }

    /**
     * Get all currently added services 
     * 
     * @return array<string, ServiceDefinitionInterface>
     */
    public function getServices() : array 
    {
        return $this->services;
    }

    /**
     * Returns all shared service names
     * 
     * @return array<string>
     */
    public function getSharedNames() : array 
    {
        return $this->shared;
    }

    /**
     * Add a service by string and arguments array.
     * 
     * @param string                    $serviceName
     * @param class-string              $serviceClass
     * @param array<mixed>              $serviceArguments
     * @param bool                      $isShared
     * @return ServiceDefinition
     */
    public function add(string $serviceName, string $serviceClass, array $serviceArguments = [], bool $isShared = true) : ServiceDefinition
    {
        $service = new ServiceDefinition($serviceClass, $serviceArguments);
        $this->addService($serviceName, $service, $isShared);

        return $service;
    }

    /**
     * Add services by an array
     * 
     * @param array<string, array<mixed>>       $servicesArray
     * @return void
     */
    public function addArray(array $servicesArray) : void
    {
        foreach($servicesArray as $serviceName => $serviceConfiguration)
        {
            $this->addService($serviceName, ServiceDefinition::fromArray($serviceConfiguration), $serviceConfiguration['shared'] ?? true);
        }
    }

    /**
     * Add a service definition instance to the container builder.
     * 
     * @param string                        $serviceName
     * @param ServiceDefinitionInterface    $serviceDefinition
     * @return void
     */
    public function addService(string $serviceName, ServiceDefinitionInterface $serviceDefinition, bool $isShared = true) : void
    {
        if ($this->invalidServiceBuilderString($serviceName))
        {
            throw new ContainerBuilderException('The "'.$serviceName.'" servicename must be a string, cannot be numeric, empty or contain any special characters except "." and "_".');
        }

        // add the service definition
        $this->services[$serviceName] = $serviceDefinition;

        // generate the normalized name
        $this->generateNormalizedServiceName($serviceName);

        // set the shared unshared flag
        if ($isShared && (!in_array($serviceName, $this->shared)))
        {
            $this->shared[] = $serviceName;
        } 
        elseif ((!$isShared) && in_array($serviceName, $this->shared))
        {
            unset($this->shared[array_search($serviceName, $this->shared)]);
        }
    }

    /**
     * Import data from a container namespace 
     * 
     * @param ContainerNamespace            $namespace
     * @return void
     */
    public function importNamespace(ContainerNamespace $namespace) : void
    {
        // import the parameters
        $this->parameters = array_merge($this->parameters, $namespace->getParameters());

        // import aliases
        $this->aliases = array_merge($this->aliases, $namespace->getAliases());

        // import the service definitions
        foreach($namespace->getServices() as $name => $service)
        {
            $this->addService($name, $service);
        }
    }

    /**
     * Checks if the given string is valid and not numeric &
     * 
     * @param string            $value
     * @return bool
     */
    private function invalidServiceBuilderString(string $value) : bool
    {
        if (empty($value) || is_numeric($value)) {
            return true;
        }

        // check for trailing / prepending whitespace ect.
        if (trim($value) !== $value) {
            return true;
        }

        // check for other special characters
        if (preg_match('/[^a-zA-Z0-9._]+/', $value))  {
            return true;
        }

        // also check the first character the string contains with a number
        if (is_numeric($value[0]) || $value[0] === '.' || $value[0] === '_') {
            return true;
        }

        $lastCharacter = $value[strlen($value) - 1];
        if ($lastCharacter === '.' || $lastCharacter === '_') {
            return true;
        }

        return false;
    }

    /**
     * Generate a camelized service name
     * 
     * @param string            $serviceName
     * @return string
     */
    private function camelizeServiceName(string $serviceName) : string
    {
        return str_replace(['.', '_'], '', ucwords(str_replace(['.', '_'], '.', $serviceName), '.'));
    }

    /**
     * Generates the "normalizedServiceNames" array.
     * 
     * @param string            $serviceName
     * @return void 
     */
    private function generateNormalizedServiceName(string $serviceName) 
    {
        $normalizedServiceName = $this->camelizeServiceName($serviceName);

        $duplicateCounter = 0;
        $countedNormalizedServiceName = $normalizedServiceName;
        while(in_array($countedNormalizedServiceName, $this->normalizedServiceNames))
        {
            $duplicateCounter++;
            $countedNormalizedServiceName = $normalizedServiceName . $duplicateCounter;
        }

        $this->normalizedServiceNames[$serviceName] = $countedNormalizedServiceName;
    }

    /**
     * Generate the container class code string
     * 
     * @return string
     */
    public function generate() : string
    {
        $buffer = "<?php\n\n";

        // add namespace if needed
        if (!is_null($this->containerNamespace))
        {
            $buffer .= "namespace " . $this->containerNamespace . ";\n\n";
        }

        // add use statement for the super container
        $aliasContainerName = 'ClanCatsContainer' . md5($this->containerName);
        $buffer .= "use " . Container::class . " as " . $aliasContainerName . ";\n\n";

        // generate the the class
        $buffer .= "class $this->containerClassName extends $aliasContainerName {\n\n";

        $buffer .= $this->generateParameters() . "\n";
        $buffer .= $this->generateAliases() . "\n";
        $buffer .= $this->generateMetaData() . "\n";
        $buffer .= $this->generateResolverTypes() . "\n";
        $buffer .= $this->generateResolverMappings() . "\n";
        $buffer .= $this->generateResolverMethods() . "\n";

        if ($this->overrideDebugInfo) {
            $buffer .= $this->generateDebugInfo() . "\n";
        }

        return $buffer . "\n}";
    }

    /**
     * Generate the service resolver method name for the given service
     * 
     * @param string            $serviceName
     * @return string
     */
    private function getResolverMethodName(string $serviceName) : string 
    {
        if (!isset($this->normalizedServiceNames[$serviceName]))
        {
            throw new ContainerBuilderException("The '" . $serviceName . "' service has never been definied.");
        }

        return 'resolve' . $this->normalizedServiceNames[$serviceName];
    }

    /**
     * Generate arguments code 
     * 
     * @param ServiceArguments          $arguments
     * @return string
     */
    private function generateArgumentsCode(ServiceArguments $arguments) : string
    {
        $buffer = [];

        foreach($arguments->getAll() as list($argumentValue, $argumentType))
        {
            if ($argumentType === ServiceArguments::DEPENDENCY)
            {
                if ($argumentValue === 'container')
                {
                    $buffer[] = "\$this";
                }
                // if the dependency is defined in the current container builder
                // we can be sure that it exists and directly call the resolver method
                elseif (isset($this->services[$argumentValue])) 
                {
                    $resolverMethodCall = "\$this->" . $this->getResolverMethodName($argumentValue) . '()';

                    // if is not shared we can just forward the factory method
                    if (!in_array($argumentValue, $this->shared))
                    {
                        $buffer[] = $resolverMethodCall;
                    }
                    // otherwise we have to check if the singleton has 
                    // already been resolved.
                    else
                    {
                        $buffer[] = "\$this->resolvedSharedServices['$argumentValue'] ?? \$this->resolvedSharedServices['$argumentValue'] = " . $resolverMethodCall;
                    }   
                }
                // if the dependency is not defined inside the container builder
                // it might be added dynamically later. So we just access the containers `get` method.
                else
                {
                    $buffer[] = "\$this->get('$argumentValue')";
                }
            }
            elseif ($argumentType === ServiceArguments::PARAMETER)
            {
                $buffer[] = "\$this->getParameter('$argumentValue')";
            }
            elseif ($argumentType === ServiceArguments::RAW)
            {
                $buffer[] = var_export($argumentValue, true);
            }
        }

        return implode(', ', $buffer);
    }

    /**
     * Generate the containers parameter property
     * 
     * @return string 
     */
    private function generateParameters() : string
    {
        return "protected array \$parameters = " . var_export($this->parameters, true) . ";\n";
    }

    /**
     * Generate the containers aliases property
     * 
     * @return string 
     */
    private function generateAliases() : string
    {
        return "protected array \$serviceAliases = " . var_export($this->aliases, true) . ";\n";
    }

    /**
     * Generate the containers parameter property
     * 
     * @return string 
     */
    private function generateMetaData() : string
    {
        $metaData = [];
        $metaDataService = [];

        foreach($this->services as $serviceName => $serviceDefinition)
        {
            foreach($serviceDefinition->getMetaData() as $key => $serviceMetaData)
            {
                if (!isset($metaData[$key])) {
                    $metaData[$key] = [];
                }

                $metaData[$key][$serviceName] = $serviceMetaData;

                // mapping for the service centered
                if (!isset($metaDataService[$serviceName])) {
                    $metaDataService[$serviceName] = [];
                }

                if (!in_array($key, $metaDataService[$serviceName])) {
                    $metaDataService[$serviceName][] = $key;
                }
            }
        }

        return "protected array \$metadata = " . var_export($metaData, true) . ";\nprotected array \$metadataService = " . var_export($metaDataService, true) . ";\n";
    }

    /**
     * Generate the resolver types array 
     * 
     * @return string 
     */
    private function generateResolverTypes() : string
    {
        $types = []; 

        foreach($this->services as $serviceName => $serviceDefinition)
        {
            $types[] = var_export($serviceName, true) . ' => ' . Container::RESOLVE_METHOD;
        }

        // also add the aliases
        foreach($this->aliases as $serviceName => $targetService)
        {
            $types[] = var_export($serviceName, true) . ' => ' . Container::RESOLVE_ALIAS;
        }

        return "protected array \$serviceResolverType = [" . implode(', ', $types) . "];\n";
    }

    /**
     * Generate the resolver mappings array
     * 
     * @return string 
     */
    private function generateResolverMappings() : string
    {
        $mappings = []; 

        foreach($this->services as $serviceName => $serviceDefinition)
        {
            $mappings[] = var_export($serviceName, true) . ' => ' . var_export($this->getResolverMethodName($serviceName), true);
        }

        return "protected array \$resolverMethods = [" . implode(', ', $mappings) . "];\n";
    }

    /**
     * Generate the resolver methods
     * 
     * @return string
     */
    private function generateResolverMethods() : string
    {
        $buffer = "";

        foreach($this->services as $serviceName => $serviceDefinition)
        {
            $isSharedService = in_array($serviceName, $this->shared);
            $serviceClassName = $serviceDefinition->getClassName();

            if ($serviceClassName[0] !== "\\") {
                $serviceClassName = "\\" . $serviceClassName;
            }

            $buffer .= "public function " . $this->getResolverMethodName($serviceName) . "() : {$serviceClassName} {\n";

            if ($isSharedService) {
                $buffer .= "\tif (isset(\$this->resolvedSharedServices[" . var_export($serviceName, true) . "])) return \$this->resolvedSharedServices[" . var_export($serviceName, true) . "];\n";
            }

            $factoryMethod = current( $serviceDefinition->getMetaData()['factory'][0] ?? []);

            if (is_string($factoryMethod)) {
                $buffer .= "\t\$instance = " . $serviceClassName . '::' . $factoryMethod . "(". $this->generateArgumentsCode($serviceDefinition->getArguments()) .");\n";
            } else {
                $buffer .= "\t\$instance = new " . $serviceClassName . "(". $this->generateArgumentsCode($serviceDefinition->getArguments()) .");\n";
            }

            foreach($serviceDefinition->getMethodCalls() as list($callName, $callArguments))
            {
                $buffer .= "\t\$instance->" . $callName . '('. $this->generateArgumentsCode($callArguments) .");\n";
            }

            if ($isSharedService)
            {
                $buffer .= "\t\$this->resolvedSharedServices[" . var_export($serviceName, true) . "] = \$instance;\n";
            }

            $buffer .= "\treturn \$instance;\n";

            $buffer .= "}\n";
        }

        return $buffer;
    }

    private function generateDebugInfo() : string 
    {
        return <<<EOF
        /**
         * Override the debug info function so that we do not end in an infinite recrusion.
         */
        public function __debugInfo() : array
        {
            return ['services' => \$this->available()];
        }
        EOF;
    }
}
