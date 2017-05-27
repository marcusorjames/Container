<?php
/**
 * ClanCats Container
 *
 * @link      https://github.com/ClanCats/Container/
 * @copyright Copyright (c) 2016-2017 Mario Döring
 * @license   https://github.com/ClanCats/Container/blob/master/LICENSE (MIT License)
 */
namespace ClanCats\Container\ContainerParser;

use ClanCats\Container\Exceptions\ContainerInterpreterException;

use ClanCats\Container\ContainerNamespace;
use ClanCats\Container\ServiceDefinition;

use ClanCats\Container\ContainerParser\{
    ContainerLexer,
    Parser\ScopeParser
};

use ClanCats\Container\ContainerParser\Nodes\{
    BaseNode as Node,

    // the nodes
    ValueNode,
    ScopeNode,
    ScopeImportNode,
    ParameterDefinitionNode,
    ServiceDefinitionNode,
    ParameterReferenceNode,
    ServiceReferenceNode
};

class ContainerInterpreter
{
    /**
     * The container definitions namesapce 
     * 
     * @var ContainerNamespace
     */
    protected $namespace;

    /**
     * Construct a new container file interpreter
     * 
     * @param ContainerNamespace                $namespace
     * @return void
     */
    public function __construct(ContainerNamespace $namespace)
    {
        $this->namespace = $namespace;
    }

	/**
	 * Handle a container file scope
	 * 
	 * @param ScopeNode 			$scope
	 * @return void
	 */
    public function handleScope(ScopeNode $scope) 
    {
    	foreach($scope->getNodes() as $node)
    	{
    		if ($node instanceof ScopeImportNode)
    		{
    			$this->handleScopeImport($node);
    		}
            elseif ($node instanceof ParameterDefinitionNode)
            {
                $this->handleParameterDefinition($node);
            }
            elseif ($node instanceof ServiceDefinitionNode)
            {
                $this->handleServiceDefinition($node);
            }
    		else 
    		{
    			throw new ContainerInterpreterException("Unexpected node in scope found.");
    		}
    	}
    }

    /**
     * Handle a scope import statement
     * 
     * @param ScopeImportNode           $import
     * @return void
     */
    public function handleScopeImport(ScopeImportNode $import)
    {
        $path = $import->getPath();

        if (is_null($path) || empty($path))
        {
            throw new ContainerInterpreterException("An import statement cannot be empty.");
        }

        $code = $this->namespace->getCode($path);

        // after retrieving new code we have 
        // to start a new lexer & and parser 
        $lexer = new ContainerLexer($code);
        $parser = new ScopeParser($lexer->tokens());
        $scopeNode = $parser->parse();

        unset($lexer, $parser);

        // and continue handling the importet scope
        $this->handleScope($scopeNode);
    }  

    /**
     * Handle a parameter definition
     * 
     * @param ParameterDefinitionNode 			$definition
     * @return void
     */
    public function handleParameterDefinition(ParameterDefinitionNode $definition) 
    {
        if ($this->namespace->hasParameter($definition->getName()) && $definition->isOverride() === false)
        {
            throw new ContainerInterpreterException("A parameter named \"{$definition->getName()}\" is already defined, you can prefix the definition with \"override\" to get around this error.");
        }

        $this->namespace->setParameter($definition->getName(), $definition->getValue()->getRawValue());
    }

    /**
     * Handle a service definition
     * 
     * @param ParameterDefinitionNode           $definition
     * @return void
     */
    public function handleServiceDefinition(ServiceDefinitionNode $definition) 
    {
        if ($this->namespace->hasService($definition->getName()) && $definition->isOverride() === false)
        {
            throw new ContainerInterpreterException("A service named \"{$definition->getName()}\" is already defined, you can prefix the definition with \"override\" to get around this error.");
        }

        // create a service definition from the node
        $service = new ServiceDefinition($definition->getClassName());

        if ($definition->hasArguments()) 
        {
            $arguments = $definition
                ->getArguments() // get the definitions arguments
                ->getArguments(); // and the argument array from the object

            foreach($arguments as $argument)
            {
                if ($argument instanceof ServiceReferenceNode)
                {
                    $service->addDependencyArgument($argument->getName());
                }
                elseif ($argument instanceof ParameterReferenceNode)
                {
                    $service->addParameterArgument($argument->getName());
                }
                elseif ($argument instanceof ValueNode)
                {
                    $service->addRawArgument($argument->getRawValue());
                }
                else 
                {
                    throw new ContainerInterpreterException("Unable to handle argument node of type \"" . get_class($argument) . "\".");
                }
            }
        }

        // add the node to the namespace
        $this->namespace->setService($definition->getName(), $service);
    }
}

