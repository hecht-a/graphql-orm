<?php

declare(strict_types=1);

namespace GraphqlOrm\Persistence;

use GraphqlOrm\Exception\LogicException;

/**
 * The order flush() writes in: one document of mutations, and another only when an entity created needs the identifier
 * of an entity created before it.
 *
 * @internal
 */
final class WritePlan
{
    /**
     * The operations by document, keyed by alias: an operation naming an entity created in the document before.
     *
     * @param list<Operation> $operations
     *
     * @return list<array<string, Operation>>
     *
     * @throws LogicException when entities created need the identifiers of each other
     */
    public static function documents(array $operations): array
    {
        $created = [];

        foreach ($operations as $operation) {
            if ($operation->kind === Operation::CREATE) {
                $created[spl_object_id($operation->entity)] = $operation;
            }
        }

        $waveOf = [];
        $waves = [];

        foreach ($operations as $operation) {
            // The entities deleted last, a relation to them being possibly changed before.
            $wave = $operation->kind === Operation::DELETE ? ($waves === [] ? 0 : max(array_keys($waves))) : self::waveOf($operation, $created, $waveOf, []);
            $waves[$wave][] = $operation;
        }

        ksort($waves);
        $documents = [];

        foreach ($waves as $wave) {
            $aliased = [];

            foreach ($wave as $operation) {
                $aliased['m' . \count($aliased)] = $operation;
            }

            $documents[] = $aliased;
        }

        return $documents;
    }

    /**
     * @param array<int, Operation> $created  the operations creating an entity, by object id
     * @param array<int, int>       $waveOf   the document of the entities created, by object id
     * @param array<int, true>      $visiting the entities created whose document is being found
     *
     * @throws LogicException when entities created need the identifiers of each other
     */
    private static function waveOf(Operation $operation, array $created, array &$waveOf, array $visiting): int
    {
        $id = spl_object_id($operation->entity);

        if ($operation->kind === Operation::CREATE && isset($waveOf[$id])) {
            return $waveOf[$id];
        }

        if (isset($visiting[$id])) {
            throw new LogicException(\sprintf('The new entities "%s" and those it relates to need the identifiers of each other: flush() one of them first.', $operation->entity::class));
        }

        $wave = 0;

        foreach ($operation->values as $value) {
            if ($value instanceof PendingIdentifier) {
                $dependency = $created[spl_object_id($value->entity)];
                $wave = max($wave, 1 + self::waveOf($dependency, $created, $waveOf, $visiting + [$id => true]));
            }
        }

        if ($operation->kind === Operation::CREATE) {
            $waveOf[$id] = $wave;
        }

        return $wave;
    }
}
