<?php

declare(strict_types=1);

namespace Eventjet\GraphqlCodegen;

use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\BooleanType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FloatType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\StringType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use LogicException;
use PhpParser\Builder\Namespace_;
use PhpParser\Builder\Property;
use PhpParser\BuilderFactory;
use PhpParser\PrettyPrinter\Standard;

use function array_filter;
use function array_map;
use function array_merge;
use function array_reduce;
use function dirname;
use function end;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function mkdir;
use function sprintf;
use function str_replace;
use function ucfirst;
use function version_compare;

final class Generator
{
    private Schema $schema;
    private DocumentNode $document;
    private string $namespace;
    private string $directory;
    private string $platformVersion;

    private function __construct(
        Schema $schema,
        DocumentNode $document,
        string $namespace,
        string $directory,
        ?string $platformVersion = null
    ) {
        $this->schema = $schema;
        $this->document = $document;
        $this->namespace = $namespace;
        $this->directory = $directory;
        $this->platformVersion = $platformVersion ?? '7.4';
    }

    public static function run(
        string $schemaFile,
        string $opFile,
        string $namespace,
        string $directory,
        ?string $platformVersion = null
    ): void {
        $schema = BuildSchema::build(file_get_contents($schemaFile));
        $documentNode = Parser::parse(file_get_contents($opFile));
        $instance = new self($schema, $documentNode, $namespace, $directory, $platformVersion);
        $instance->go();
    }

    /**
     * @param Namespace_ $namespace
     * @param string $fileName
     */
    private static function writeNamespaceToFile(Namespace_ $namespace, string $fileName): void
    {
        $dir = dirname($fileName);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fileName, (new Standard())->prettyPrintFile([$namespace->getNode()]));
    }

    private static function unwarpType(Type $type): Type
    {
        $unwrapped = Type::getNullableType($type);
        if ($unwrapped instanceof ListOfType) {
            return self::unwarpType($unwrapped->getWrappedType());
        }
        return $unwrapped;
    }

    public function findOnlyOperation(): ?OperationDefinitionNode
    {
        foreach ($this->document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }
            return $definition;
        }
        return null;
    }

    /**
     * @return FragmentDefinitionNode[]
     */
    public function findFragments(): array
    {
        return array_filter(
            iterator_to_array($this->document->definitions),
            function (DefinitionNode $node): bool {
                return $node instanceof FragmentDefinitionNode;
            }
        );
    }

    /**
     * @param FieldNode|OperationDefinitionNode|FragmentDefinitionNode $node
     * @return FieldNode[]
     */
    private function collectFields($node, Type $type): array
    {
        $fields = [];
        foreach (iterator_to_array($node->selectionSet->selections) as $selection) {
            if (
                $selection instanceof FieldNode
                && ($type instanceof ObjectType || $type instanceof InterfaceType)
                && $type->hasField($selection->name->value)
            ) {
                $fields[] = [$selection];
            } elseif ($selection instanceof FragmentSpreadNode) {
                $fragment = $this->findFragment($selection->name->value);
                $fields[] = self::collectFields($fragment, $type);
            } elseif ($selection instanceof InlineFragmentNode) {
                $fields[] = self::collectFields($selection, $type);
            }
        }
        $fields = array_merge([], ...$fields);
        $fields = array_reduce(
            $fields,
            function (array $fields, FieldNode $field): array {
                foreach ($fields as $existingField) {
                    if ($field->name->value === $existingField->name->value) {
                        return $fields;
                    }
                }
                $fields[] = $field;
                return $fields;
            },
            []
        );
        return $fields;
    }

    /**
     * @param string $name
     */
    private function findFragment(string $name): FragmentDefinitionNode
    {
        foreach ($this->findFragments() as $fragment) {
            if ($fragment->name->value !== $name) {
                continue;
            }
            return $fragment;
        }
        throw new LogicException(sprintf('Unknown fragment "%s".', $name));
    }

    private function property(FieldNode $field, string $namespace, Type $type): Property
    {
        $unwrapped = self::unwarpType($type);
        $factory = new BuilderFactory();
        if ($unwrapped instanceof ObjectType || $unwrapped instanceof InterfaceType) {
            $propertyType = '\\' . $this->generateClassForNode($field, $namespace, $unwrapped);
        } elseif ($unwrapped instanceof IDType) {
            $propertyType = 'string';
        } elseif ($unwrapped instanceof FloatType) {
            $propertyType = 'float';
        } elseif ($unwrapped instanceof StringType) {
            $propertyType = 'string';
        } elseif ($unwrapped instanceof EnumType) {
            $propertyType = 'string';
        } elseif ($unwrapped instanceof BooleanType) {
            $propertyType = 'bool';
        } elseif ($unwrapped instanceof IntType) {
            $propertyType = 'int';
        } else {
            $propertyType = null;
        }
        $docComment = null;
        if (Type::getNullableType($type) instanceof ListOfType) {
            $docComment = '/** @var ' . $propertyType . '[] */';
            $propertyType = 'array';
        }
        $property = $factory->property($field->alias !== null ? $field->alias->value : $field->name->value);
        if ($propertyType !== null) {
            if ($this->canUsePropertyTypes()) {
                $property = $property->setType(($type instanceof NonNull ? '' : '?') . $propertyType);
            } else {
                $docComment = '/** @var ' . $propertyType . ($type instanceof NonNull ? '' : '|null') . ' */';
            }
        }
        if ($docComment !== null) {
            $property = $property->setDocComment($docComment);
        }
        return $property;
    }

    /**
     * @param FieldNode|OperationDefinitionNode $node
     */
    private function generateClassForNode($node, string $namespace, Type $type, ?string $parentFqcn = null): string
    {
        $className = ucfirst($node->name->value);
        if ($parentFqcn !== null) {
            $parts = explode('\\', $parentFqcn);
            $parentClassName = end($parts);
            $className = $parentClassName . $type->name;
        }
        $class = (new BuilderFactory())->class($className);
        $fqcn = $namespace . '\\' . $className;
        if ($parentFqcn !== null) {
            $class->extend('\\' . $parentFqcn);
        }
        if ($type instanceof InterfaceType) {
            $class = $class->makeAbstract();
            $possibleTypes = $this->schema->getPossibleTypes($type);
            foreach ($possibleTypes as $possibleType) {
                $this->generateClassForNode($node, $namespace, $possibleType, $fqcn);
            }
        } else {
            $class = $class->makeFinal();
        }
        if ($node->selectionSet !== null) {
            $fields = $this->collectFields($node, $type);
            $stmts = array_filter(
                array_map(
                    function (FieldNode $selection) use ($fqcn, $type, $parentFqcn): ?Property {
                        return $this->property(
                            $selection,
                            $parentFqcn ?? $fqcn,
                            self::unwarpType($type)->getField($selection->name->value)->getType()
                        );
                    },
                    $fields
                )
            );
            $class->addStmts($stmts);
        }
        $namespaceNode = (new BuilderFactory())->namespace($namespace)->addStmt($class);
        $fileName = $this->directory . str_replace('\\', '/', $fqcn) . '.php';
        self::writeNamespaceToFile($namespaceNode, $fileName);
        return $fqcn;
    }

    private function go(): void
    {
        $operation = $this->findOnlyOperation();
        if ($operation === null) {
            return;
        }
        $this->generateClassForNode($operation, $this->namespace, $this->schema->getQueryType());
    }

    /**
     * @return bool|int
     */
    private function canUsePropertyTypes()
    {
        return version_compare($this->platformVersion, '7.4', '>=');
    }
}
