<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Shared\Actions\RecordAuditEvent;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Encuentra en el código los códigos de acción que se le pasan a
 * `RecordAuditEvent::handle()`.
 *
 * Trabaja sobre el árbol sintáctico y no con expresiones regulares porque
 * las llamadas vienen de muchas formas: posicionales o con `action:`, en
 * una línea o en varias, con la instancia en `$auditar`, en
 * `$recordAuditEvent` o pedida con `app()`, y a veces con un ternario
 * que elige entre dos códigos. Una regex se pierde alguna, y un falso
 * negativo acá es exactamente el desfase que la prueba existe para evitar.
 *
 * Lo que no sabe leer —un código armado en una variable— no lo ignora:
 * lo devuelve como no resuelto, para que la prueba falle y alguien decida.
 */
final class AuditActionCodeExtractor
{
    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * @return array{codes: list<array{code: string, where: string}>, unresolved: list<string>}
     */
    public function scanDirectory(string $path): array
    {
        $codes = [];
        $unresolved = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $result = $this->scan((string) file_get_contents($file->getPathname()), $file->getPathname());
            array_push($codes, ...$result['codes']);
            array_push($unresolved, ...$result['unresolved']);
        }

        return ['codes' => $codes, 'unresolved' => $unresolved];
    }

    /**
     * @return array{codes: list<array{code: string, where: string}>, unresolved: list<string>}
     */
    public function scan(string $source, string $label = 'código'): array
    {
        if (! str_contains($source, 'RecordAuditEvent')) {
            return ['codes' => [], 'unresolved' => []];
        }

        $ast = $this->parser->parse($source) ?? [];
        $traverser = new NodeTraverser(new NameResolver);
        $ast = $traverser->traverse($ast);

        $names = $this->instanceNames($ast);
        $codes = [];
        $unresolved = [];

        /** @var list<MethodCall> $calls */
        $calls = $this->finder->findInstanceOf($ast, MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Identifier || $call->name->name !== 'handle') {
                continue;
            }

            if (! $this->isAuditInstance($call->var, $names)) {
                continue;
            }

            $where = "{$label}:{$call->getStartLine()}";
            $action = $this->actionArgument($call);
            $literals = $action === null ? null : $this->literals($action);

            if ($literals === null) {
                $unresolved[] = $where;

                continue;
            }

            foreach ($literals as $code) {
                $codes[] = ['code' => $code, 'where' => $where];
            }
        }

        return ['codes' => $codes, 'unresolved' => $unresolved];
    }

    /**
     * Los nombres con los que el archivo guarda una instancia: parámetros
     * y propiedades tipados como `RecordAuditEvent`, promovidos o no.
     *
     * @param  array<Node>  $ast
     * @return list<string>
     */
    private function instanceNames(array $ast): array
    {
        $names = [];

        /** @var list<Param> $params */
        $params = $this->finder->findInstanceOf($ast, Param::class);

        foreach ($params as $param) {
            if ($this->isAuditType($param->type) && $param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        /** @var list<Property> $properties */
        $properties = $this->finder->findInstanceOf($ast, Property::class);

        foreach ($properties as $property) {
            if ($this->isAuditType($property->type)) {
                foreach ($property->props as $prop) {
                    $names[] = $prop->name->name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function isAuditType(?Node $type): bool
    {
        return $type instanceof Name && $type->toString() === RecordAuditEvent::class;
    }

    /**
     * @param  list<string>  $names
     */
    private function isAuditInstance(Expr $expr, array $names): bool
    {
        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            return in_array($expr->name->name, $names, true);
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            return in_array($expr->name, $names, true);
        }

        // app(RecordAuditEvent::class)->handle(...), y resolve() igual.
        if ($expr instanceof FuncCall && $expr->name instanceof Name
            && in_array($expr->name->toString(), ['app', 'resolve'], true)
        ) {
            $first = $expr->args[0] ?? null;

            return $first instanceof Arg
                && $first->value instanceof Expr\ClassConstFetch
                && $first->value->class instanceof Name
                && $first->value->class->toString() === RecordAuditEvent::class;
        }

        return false;
    }

    private function actionArgument(MethodCall $call): ?Expr
    {
        foreach ($call->args as $arg) {
            if ($arg instanceof Arg && $arg->name?->name === 'action') {
                return $arg->value;
            }
        }

        $first = $call->args[0] ?? null;

        return $first instanceof Arg && $first->name === null ? $first->value : null;
    }

    /**
     * Los códigos que la expresión puede producir, o `null` si no se
     * pueden saber sin ejecutarla.
     *
     * @return list<string>|null
     */
    private function literals(Expr $expr): ?array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return [$expr->value];
        }

        if ($expr instanceof Expr\Ternary) {
            $then = $expr->if === null ? null : $this->literals($expr->if);
            $else = $this->literals($expr->else);

            return $then === null || $else === null ? null : [...$then, ...$else];
        }

        if ($expr instanceof Expr\Match_) {
            $codes = [];

            foreach ($expr->arms as $arm) {
                $body = $this->literals($arm->body);

                if ($body === null) {
                    return null;
                }

                array_push($codes, ...$body);
            }

            return $codes;
        }

        return null;
    }
}
