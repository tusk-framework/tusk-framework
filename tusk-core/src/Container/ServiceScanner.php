<?php

namespace Tusk\Core\Container;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionException;
use Tusk\Contracts\Attributes\Service;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class ServiceScanner
{
    /**
     * @param array<string> $directories
     * @return array<string, array{scope: string, class: string, dependencies: array<string>, interfaces: array<string>}>
     */
    public function scan(array $directories): array
    {
        $definitions = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $className = $this->extractClassName($file->getPathname());
                    
                    if ($className) {
                        try {
                            if (!class_exists($className) && !interface_exists($className)) {
                                require_once $file->getPathname();
                            }
                            
                            $reflection = new ReflectionClass($className);
                            
                            if ($reflection->isInstantiable()) {
                                $attributes = $reflection->getAttributes(Service::class);
                                
                                if (!empty($attributes)) {
                                    /** @var Service $attr */
                                    $attr = $attributes[0]->newInstance();
                                    
                                    $dependencies = [];
                                    $constructor = $reflection->getConstructor();
                                    
                                    if ($constructor) {
                                        foreach ($constructor->getParameters() as $param) {
                                            $type = $param->getType();
                                            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                                                $dependencies[] = $type->getName();
                                            }
                                        }
                                    }
                                    
                                    $definitions[$className] = [
                                        'class' => $className,
                                        'scope' => $attr->scope,
                                        'dependencies' => $dependencies,
                                        'interfaces' => $reflection->getInterfaceNames()
                                    ];
                                }
                            }
                        } catch (ReflectionException $e) {
                            // Ignore files that cannot be reflected
                        } catch (Throwable $e) {
                            // Ignore parsing errors for now
                        }
                    }
                }
            }
        }

        return $definitions;
    }

    private function extractClassName(string $file): ?string
    {
        $fp = fopen($file, 'r');
        if (!$fp) return null;
        
        $class = $namespace = $buffer = '';
        $i = 0;
        
        while (!$class) {
            if (feof($fp)) break;
            $buffer .= fread($fp, 512);
            $tokens = @token_get_all($buffer);
            
            if (!str_contains($buffer, '{')) continue;
            
            for (; $i < count($tokens); $i++) {
                if ($tokens[$i][0] === T_NAMESPACE) {
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if ($tokens[$j][0] === T_NAME_QUALIFIED || $tokens[$j][0] === T_STRING) {
                            $namespace .= $tokens[$j][1];
                        } else if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                            break;
                        }
                    }
                }
                if ($tokens[$i][0] === T_CLASS) {
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if ($tokens[$j] === '{') {
                            $class = $tokens[$i + 2][1];
                            break 2;
                        }
                    }
                }
            }
        }
        fclose($fp);
        
        return $class ? ($namespace ? $namespace . '\\' . $class : $class) : null;
    }
}
